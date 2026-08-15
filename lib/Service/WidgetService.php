<?php

/**
 * Widget Service
 *
 * Domain orchestration for the public self-service booking widget API
 * (REQ-WSW-001, REQ-WSW-003, REQ-WSW-006). Exposes the public-safe service
 * catalogue, validates customer input, and creates appointments with
 * server-side double-booking prevention. Customer PII is write-only and is
 * never echoed back to the public API (design D6).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the public service catalogue and creates widget appointments.
 *
 * Validation helpers (validateEmail/validatePhone/validateName) are pure and
 * unit-tested in isolation; createAppointment() wires them to OpenRegister and
 * the slot cache.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; deferred pending a dedicated refactor.
 */
class WidgetService {
	/**
	 * Construct the service with lazy DI of OR's ObjectService.
	 *
	 * @param ContainerInterface $container DI container for OR's ObjectService.
	 * @param IAppConfig $appConfig App config for register-slug resolution.
	 * @param SlotService $slotService Slot availability + cache invalidation.
	 * @param LoggerInterface $logger Nextcloud logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly SlotService $slotService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Resolve OR's ObjectService, or null when OpenRegister is unavailable.
	 *
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'WidgetService: ObjectService unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OR record to an array.
	 *
	 * @param mixed $record The record (object or array).
	 *
	 * @return array<string,mixed>|null
	 */
	private function toArray(mixed $record): ?array {
		if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
			$record = $record->jsonSerialize();
		}

		if (is_array($record) === false) {
			return null;
		}

		return $record;
	}//end toArray()

	/**
	 * List public-safe services for a business (REQ-WSW-001).
	 *
	 * Returns only services flagged isPublic, exposing the safe-public subset
	 * (serviceSlug, name, durationMinutes, description, and price only when
	 * priceVisible). The canonical Service schema is reused (bookings-service-catalog);
	 * isPublic/priceVisible/resourceId are additive widget extensions. No PII and no
	 * internal fields are returned (design D6).
	 *
	 * @param string $administrationId The owning administration (tenant scope).
	 *
	 * @return array<int,array<string,mixed>> The public service list.
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md#req-wsw-001
	 */
	public function listPublicServices(string $administrationId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$services = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Service')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'isPublic' => true,
						],
						'limit' => 200,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WidgetService: service lookup failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($services as $service) {
			$service = $this->toArray(record: $service);
			if ($service === null || (bool)($service['isPublic'] ?? false) === false) {
				continue;
			}

			$public = [
				'serviceSlug' => (string)($service['@self']['slug'] ?? ($service['slug'] ?? '')),
				'name' => (string)($service['name'] ?? ''),
				'description' => ($service['description'] ?? null),
				'durationMinutes' => (int)($service['duration'] ?? 0),
			];

			if ((bool)($service['priceVisible'] ?? false) === true) {
				// `basePrice` is the property the Service schema actually
				// DECLARES — and it is in its `required` list
				// (bookings-service-catalog.json). `price` is declared by NO
				// fragment, and OpenRegister's MagicMapper discards undeclared
				// properties, so no stored Service can ever carry it. Reading
				// `price` first therefore published 0.00 for EVERY service with
				// priceVisible=true. Measured on a live instance: a Service
				// saved with basePrice=125.50 renders `basePrice => 125.5` and
				// `price => ABSENT`.
				//
				// `price` is retained only as a fallback for any object stored
				// under an older schema revision that did declare it; it must
				// stay SECOND, because the whole defect was ordering.
				$public['price'] = (float)($service['basePrice'] ?? $service['price'] ?? 0);
				$public['currency'] = (string)($service['currency'] ?? 'EUR');
			}

			$result[] = $public;
		}//end foreach

		return $result;
	}//end listPublicServices()

	/**
	 * Validate an email address against RFC 5322 (REQ-WSW-006).
	 *
	 * @param string $email The candidate email.
	 *
	 * @return bool
	 */
	public function validateEmail(string $email): bool {
		return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
	}//end validateEmail()

	/**
	 * Validate an optional international phone number (REQ-WSW-006).
	 *
	 * Empty is valid (phone is optional). Non-empty must match E.164-ish
	 * `^\+?[1-9]\d{1,14}$`.
	 *
	 * @param string $phone The candidate phone (may be empty).
	 *
	 * @return bool
	 */
	public function validatePhone(string $phone): bool {
		if ($phone === '') {
			return true;
		}

		return preg_match('/^\+?[1-9]\d{1,14}$/', $phone) === 1;
	}//end validatePhone()

	/**
	 * Validate a customer name (1-255 chars, letters/space/hyphen) (REQ-WSW-006).
	 *
	 * @param string $name The candidate name.
	 *
	 * @return bool
	 */
	public function validateName(string $name): bool {
		$length = mb_strlen($name);
		if ($length < 1 || $length > 255) {
			return false;
		}

		return preg_match("/^[\\p{L} '\\-]+$/u", $name) === 1;
	}//end validateName()

	/**
	 * Create an appointment from a widget payload (REQ-WSW-003, REQ-WSW-008).
	 *
	 * THE SINGLE WRITE PATH for widget bookings. `WidgetApiController::appointments()`
	 * used to re-implement this inline; the two copies had already drifted once on
	 * a security check (PR #491 had to patch an `isPublic` gate into the routed copy
	 * that this method always had) and a second time on customer data (the routed
	 * copy persisted only an anonymised `customerId`, silently dropping the
	 * `customerName` / `customerEmail` / `customerPhone` fields that
	 * `register.d/30-bookings-self-service-widget.json` declares on `Appointment`
	 * and that REQ-WSW-006 and the REQ-BCF-003 confirmation email both need).
	 * The controller now delegates here.
	 *
	 * Contract notes:
	 *  - The service is resolved by `serviceId` (REQ-WSW-001/003 payload shape),
	 *    and must be BOTH `isPublic` and `status = active` to be bookable.
	 *  - Availability is re-checked server-side against SlotService, which is
	 *    authoritative and already excludes confirmed appointments (REQ-WSW-002).
	 *  - Status is `pending_confirmation`, NOT `confirmed`: this is the customer
	 *    self-service pathway (REQ-BCA-005 pathway 2, REQ-BCF-010), and
	 *    bookings-confirm-flow keys its ConfirmationToken, confirmation email and
	 *    auto-cancel job off that status.
	 *  - Customer contact details are stored write-only and are never echoed back
	 *    to the public API (design D6).
	 *
	 * Boundary validation (name/email/phone/notes/ISO format) is the controller's
	 * `validateAppointmentPayload()`, which owns the user-facing REQ-WSW-008
	 * messages. This method re-checks email and phone as documented server-side
	 * defence-in-depth; it deliberately does NOT re-check the name, because the
	 * two name patterns differ and silently adopting the stricter one here would
	 * change what the live endpoint accepts (see the PR notes).
	 *
	 * @param string $administrationId The owning administration.
	 * @param array<string,mixed> $payload Widget payload (serviceId, resourceId,
	 *                                     startTime, endTime, customer*).
	 *
	 * @return array<string,mixed> ['code' => int, ...] where code is 201/400/404/409/500.
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	public function createAppointment(string $administrationId, array $payload): array {
		$serviceId = trim((string)($payload['serviceId'] ?? ''));
		$resourceId = trim((string)($payload['resourceId'] ?? ''));
		$startTime = trim((string)($payload['startTime'] ?? ''));
		$endTime = trim((string)($payload['endTime'] ?? ''));
		$name = trim((string)($payload['customerName'] ?? ''));
		$email = trim((string)($payload['customerEmail'] ?? ''));
		$phone = trim((string)($payload['customerPhone'] ?? ''));
		$notes = (string)($payload['notes'] ?? '');

		if ($serviceId === '' || $resourceId === '' || $startTime === '' || $endTime === '') {
			return ['code' => 400, 'error' => 'missing_fields'];
		}

		// Server-side re-check (REQ-WSW-006). Name is deliberately excluded —
		// see the docblock.
		if ($this->validateEmail(email: $email) === false) {
			return ['code' => 400, 'error' => 'invalid_email'];
		}

		if ($this->validatePhone(phone: $phone) === false) {
			return ['code' => 400, 'error' => 'invalid_phone'];
		}

		if (mb_strlen($notes) > 500) {
			return ['code' => 400, 'error' => 'notes_too_long'];
		}

		try {
			$start = new DateTimeImmutable($startTime);
			$end = new DateTimeImmutable($endTime);
		} catch (\Throwable) {
			return ['code' => 400, 'error' => 'invalid_start_time'];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['code' => 500, 'error' => 'service_unavailable'];
		}

		// The #491 gate: a caller who knows any serviceId must not be able to
		// book a service the business never published. Fail-closed — an
		// unresolvable catalogue denies rather than permits.
		$service = $this->findPublicService(
			objectService: $objectService,
			serviceId: $serviceId
		);
		if ($service === null) {
			return ['code' => 404, 'error' => 'service_not_found'];
		}

		$startUtc = $start->setTimezone(new DateTimeZone('UTC'));
		$endUtc = $end->setTimezone(new DateTimeZone('UTC'));

		// Server-authoritative availability re-check (REQ-WSW-003 race
		// scenario). SlotService is the authority and already excludes
		// confirmed appointments and out-of-hours times (REQ-WSW-002).
		if ($this->isSlotOffered(
			serviceId: $serviceId,
			resourceId: $resourceId,
			startTime: $startTime,
			endTime: $endTime
		) === false
		) {
			return ['code' => 409, 'error' => 'slot_unavailable'];
		}

		$phoneValue = null;
		if ($phone !== '') {
			$phoneValue = $phone;
		}

		$notesValue = null;
		if ($notes !== '') {
			$notesValue = $notes;
		}

		// Canonical Appointment (bookings-create-appointment). A customer is a
		// Nextcloud contact entity: the public widget has no authenticated user,
		// so a deterministic guest customerId is derived from the captured email
		// and the captured contact details are stored write-only (design D6).
		//
		// status = pending_confirmation, NOT confirmed. This is the customer
		// self-service pathway (REQ-BCA-005 pathway 2 / REQ-BCF-010); the
		// ConfirmationToken, the confirmation email and the
		// CancelUnconfirmedAppointments job all key off this value, and
		// creating the row already `confirmed` would bypass the whole
		// bookings-confirm-flow capability.
		$appointment = [
			'administrationId' => $administrationId,
			'appointmentId' => 'wsw-' . bin2hex(random_bytes(8)),
			'serviceId' => $serviceId,
			'resourceId' => $resourceId,
			'customerId' => 'cust-anon-' . substr(hash('sha256', strtolower($email)), 0, 16),
			'startTime' => $startUtc->format('Y-m-d\TH:i:s\Z'),
			'endTime' => $endUtc->format('Y-m-d\TH:i:s\Z'),
			'status' => 'pending_confirmation',
			'customerName' => $name,
			'customerEmail' => $email,
			'customerPhone' => $phoneValue,
			'notes' => $notesValue,
			'source' => 'widget',
		];

		try {
			$saved = $objectService->saveObject(
				object: $appointment,
				register: $this->getRegisterSlug(),
				schema: 'Appointment',
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WidgetService: appointment save failed',
				['exception' => $e->getMessage(), 'administrationId' => $administrationId]
			);
			return ['code' => 500, 'error' => 'create_failed'];
		}

		$saved = $this->toArray(record: $saved);

		// Invalidate cached slots for the affected date so the next read updates.
		$this->slotService->invalidate(
			serviceId: $serviceId,
			resourceId: $resourceId,
			date: $startUtc->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d')
		);

		// Design D6: never echo customer PII back to the public API. The
		// appointmentId / status / confirmationMessage keys and their values
		// reproduce what the routed endpoint already returned, so delegating
		// does not change the widget's contract.
		return [
			'code' => 201,
			'appointmentId' => (string)($saved['appointmentId'] ?? $appointment['appointmentId']),
			'status' => (string)($saved['status'] ?? 'pending_confirmation'),
			'startTime' => $startUtc->format('Y-m-d\TH:i:s\Z'),
			'endTime' => $endUtc->format('Y-m-d\TH:i:s\Z'),
			'confirmationMessage' => 'Your appointment was created. You will receive a confirmation email shortly.',
		];

	}//end createAppointment()

	/**
	 * Whether a serviceId names a service the business actually published.
	 *
	 * The same #491 gate as findPublicService(), exposed for callers that need
	 * to ASK the question rather than resolve the row — currently
	 * WidgetApiController::slots().
	 *
	 * Why slots() needs it: `guard()` proves the caller holds a widget API key,
	 * and that key is by definition shipped in a PUBLIC booking widget, so
	 * anyone who can view the widget holds it. listPublicServices() filters the
	 * catalogue down to `isPublic` entries precisely so a business can keep
	 * internal services out of it — but availability was readable for ANY
	 * serviceId of that tenant, published or not. Booking was gated (#491) and
	 * listing was gated; reading availability was the third door.
	 *
	 * Fail-closed: an unresolvable catalogue denies rather than permits.
	 *
	 * @param string $serviceId The service to check.
	 *
	 * @return bool True when the service is public and active.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	public function isPubliclyBookable(string $serviceId): bool {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		return $this->findPublicService(
			objectService: $objectService,
			serviceId: $serviceId
		) !== null;

	}//end isPubliclyBookable()


	/**
	 * Resolve a bookable public Service by its serviceId, or null.
	 *
	 * Fail-closed in every direction: an unresolvable catalogue, a missing
	 * service, a service that is not `isPublic`, or one whose status is not
	 * `active` all deny the booking. This is the PR #491 gate — without it a
	 * caller who knew any serviceId could book a service the business never
	 * published.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $serviceId The service to resolve.
	 *
	 * @return array<string,mixed>|null The service row, or null when not bookable.
	 */
	private function findPublicService(object $objectService, string $serviceId): ?array {
		try {
			$records = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Service')
				->findAll(
					[
						'filters' => ['serviceId' => $serviceId],
						'limit' => 1,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WidgetService: public-service resolution failed',
				['exception' => $e->getMessage(), 'serviceId' => $serviceId]
			);
			return null;
		}

		foreach ($records as $record) {
			$row = $this->toArray(record: $record);
			if ($row === null) {
				return null;
			}

			if ((bool)($row['isPublic'] ?? false) !== true) {
				return null;
			}

			if ((string)($row['status'] ?? '') !== 'active') {
				return null;
			}

			return $row;
		}

		return null;
	}//end findPublicService()

	/**
	 * Whether SlotService currently offers exactly this interval.
	 *
	 * SlotService is the availability authority (REQ-WSW-002): its list already
	 * excludes confirmed appointments, past slots and out-of-hours times, so
	 * matching against it is the same check the routed endpoint performed.
	 * Fail-closed: a lookup failure denies rather than permits.
	 *
	 * @param string $serviceId The service.
	 * @param string $resourceId The resource.
	 * @param string $startTime Candidate start, ISO 8601 UTC.
	 * @param string $endTime Candidate end, ISO 8601 UTC.
	 *
	 * @return bool
	 */
	private function isSlotOffered(
		string $serviceId,
		string $resourceId,
		string $startTime,
		string $endTime,
	): bool {
		try {
			$check = $this->slotService->getAvailableSlots(
				serviceId: $serviceId,
				resourceId: $resourceId,
				date: substr($startTime, 0, 10)
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'WidgetService: slot availability check failed',
				['exception' => $e->getMessage(), 'serviceId' => $serviceId]
			);
			return false;
		}

		foreach (($check['slots'] ?? []) as $slot) {
			if ((string)($slot['startTime'] ?? '') === $startTime
				&& (string)($slot['endTime'] ?? '') === $endTime
			) {
				return true;
			}
		}

		return false;
	}//end isSlotOffered()
}//end class
