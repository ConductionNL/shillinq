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
use OCA\Shillinq\AppInfo\AppIdentity;
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
	 * The widget payload fields read as trimmed strings.
	 */
	private const APPOINTMENT_FIELDS = ['serviceId', 'resourceId', 'startTime', 'endTime', 'customerName', 'customerEmail', 'customerPhone'];
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
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
					config: [
						'filters' => [
							'administrationId' => $administrationId,
							'isPublic' => true,
						],
						'limit' => 200,
					],
					// The public widget has no Nextcloud session; its API key is the authentication.
					_rbac: false,
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
		$request  = $this->appointmentRequest(payload: $payload) + ['administrationId' => $administrationId];
		$rejected = $this->rejectedRequest(request: $request);
		if ($rejected !== null) {
			return $rejected;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['code' => 500, 'error' => 'service_unavailable'];
		}

		return $this->rejectedBooking(objectService: $objectService, request: $request)
			?? $this->storeAppointment(objectService: $objectService, request: $request);
	}//end createAppointment()

	/**
	 * The widget payload as trimmed strings, with the window parsed into UTC
	 * (null when either time cannot be parsed).
	 *
	 * @param array<string,mixed> $payload Widget payload.
	 *
	 * @return array<string,mixed> The request fields.
	 */
	private function appointmentRequest(array $payload): array {
		$request = ['notes' => (string)($payload['notes'] ?? '')];
		foreach (self::APPOINTMENT_FIELDS as $field) {
			$request[$field] = trim((string)($payload[$field] ?? ''));
		}

		$request['window'] = $this->requestWindow(request: $request);

		return $request;
	}//end appointmentRequest()

	/**
	 * The requested start and end in UTC, null when either cannot be parsed.
	 *
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
	 */
	private function requestWindow(array $request): ?array {
		try {
			$utc = new DateTimeZone('UTC');
			return [
				'start' => (new DateTimeImmutable($request['startTime']))->setTimezone($utc),
				'end' => (new DateTimeImmutable($request['endTime']))->setTimezone($utc),
			];
		} catch (\Throwable) {
			return null;
		}
	}//end requestWindow()

	/**
	 * The 400 response for a request that fails the boundary checks, in the
	 * order the endpoint reports them (REQ-WSW-006 server-side re-check). The
	 * name is deliberately not re-checked; see createAppointment().
	 *
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return array<string,mixed>|null The rejection, null when the request passes.
	 */
	private function rejectedRequest(array $request): ?array {
		$failed = [
			'missing_fields' => in_array('', [$request['serviceId'], $request['resourceId'], $request['startTime'], $request['endTime']], true),
			'invalid_email' => $this->validateEmail(email: $request['customerEmail']) === false,
			'invalid_phone' => $this->validatePhone(phone: $request['customerPhone']) === false,
			'notes_too_long' => mb_strlen($request['notes']) > 500,
			'invalid_start_time' => $request['window'] === null,
		];

		$error = array_search(true, $failed, true);
		if ($error === false) {
			return null;
		}

		return ['code' => 400, 'error' => $error];
	}//end rejectedRequest()

	/**
	 * The 404 or 409 response when the service is not published (the #491
	 * gate, fail-closed) or the slot is no longer offered (the
	 * server-authoritative REQ-WSW-003 re-check).
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return array<string,mixed>|null The rejection, null when the booking may proceed.
	 */
	private function rejectedBooking(object $objectService, array $request): ?array {
		$service = $this->findPublicService(
			objectService: $objectService,
			serviceId: $request['serviceId'],
			administrationId: $request['administrationId']
		);
		if ($service === null) {
			return ['code' => 404, 'error' => 'service_not_found'];
		}

		if ($this->isSlotOffered(request: $request) === false) {
			return ['code' => 409, 'error' => 'slot_unavailable'];
		}

		return null;
	}//end rejectedBooking()

	/**
	 * Save the appointment, invalidate the cached slots of its date and
	 * answer 201, or 500 when the save fails.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return array<string,mixed> The response.
	 */
	private function storeAppointment(object $objectService, array $request): array {
		$appointment = $this->appointmentRecord(request: $request);
		try {
			$saved = $objectService->saveObject(object: $appointment, register: $this->getRegisterSlug(), schema: 'Appointment', _rbac: false);
		} catch (\Throwable $e) {
			$context = ['exception' => $e->getMessage(), 'administrationId' => $request['administrationId']];
			$this->logger->error('WidgetService: appointment save failed', $context);
			return ['code' => 500, 'error' => 'create_failed'];
		}

		$saved = $this->toArray(record: $saved);
		$this->invalidateSlots(request: $request);

		return $this->createdResponse(saved: $saved, appointment: $appointment);
	}//end storeAppointment()

	/**
	 * Invalidate the cached slots of the booked date so the next read updates.
	 *
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return void
	 */
	private function invalidateSlots(array $request): void {
		$this->slotService->invalidate(
			serviceId: $request['serviceId'],
			resourceId: $request['resourceId'],
			date: $request['window']['start']->format('Y-m-d')
		);
	}//end invalidateSlots()

	/**
	 * The canonical Appointment (bookings-create-appointment) for a widget request.
	 *
	 * A customer is a Nextcloud contact entity: the public widget has no
	 * authenticated user, so a deterministic guest customerId is derived from
	 * the captured email and the contact details are stored write-only (design
	 * D6). The status is pending_confirmation, NOT confirmed: this is the
	 * customer self-service pathway (REQ-BCA-005 pathway 2 / REQ-BCF-010); the
	 * ConfirmationToken, the confirmation email and the
	 * CancelUnconfirmedAppointments job all key off this value.
	 *
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return array<string,mixed> The Appointment object.
	 */
	private function appointmentRecord(array $request): array {
		return [
			'administrationId' => $request['administrationId'],
			'appointmentId' => 'wsw-' . bin2hex(random_bytes(8)),
			'serviceId' => $request['serviceId'],
			'resourceId' => $request['resourceId'],
			'customerId' => 'cust-anon-' . substr(hash('sha256', strtolower($request['customerEmail'])), 0, 16),
			'startTime' => $request['window']['start']->format('Y-m-d\TH:i:s\Z'),
			'endTime' => $request['window']['end']->format('Y-m-d\TH:i:s\Z'),
			'status' => 'pending_confirmation',
		] + $this->customerFields(request: $request) + ['source' => 'widget'];
	}//end appointmentRecord()

	/**
	 * The write-only customer fields; an empty phone or note is stored as null.
	 *
	 * @param array<string,mixed> $request The request fields.
	 *
	 * @return array<string,string|null>
	 */
	private function customerFields(array $request): array {
		$fields = [
			'customerName' => $request['customerName'],
			'customerEmail' => $request['customerEmail'],
			'customerPhone' => $request['customerPhone'],
			'notes' => $request['notes'],
		];
		foreach (['customerPhone', 'notes'] as $optional) {
			if ($fields[$optional] === '') {
				$fields[$optional] = null;
			}
		}

		return $fields;
	}//end customerFields()

	/**
	 * The 201 response. Design D6: customer PII is never echoed back to the
	 * public API; the keys and values reproduce what the routed endpoint
	 * always returned.
	 *
	 * @param array<string,mixed>|null $saved The saved row, null when unreadable.
	 * @param array<string,mixed> $appointment The object that was saved.
	 *
	 * @return array<string,mixed>
	 */
	private function createdResponse(?array $saved, array $appointment): array {
		return [
			'code' => 201,
			'appointmentId' => (string)($saved['appointmentId'] ?? $appointment['appointmentId']),
			'status' => (string)($saved['status'] ?? 'pending_confirmation'),
			'startTime' => $appointment['startTime'],
			'endTime' => $appointment['endTime'],
			'confirmationMessage' => 'Your appointment was created. You will receive a confirmation email shortly.',
		];
	}//end createdResponse()

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
	 * @param string $serviceId        The service to check.
	 * @param string $administrationId The administration the service must belong to.
	 *
	 * @return bool True when the service is public, active and in that administration.
	 *
	 * @psalm-return   bool
	 * @phpstan-return bool
	 *
	 * @spec openspec/specs/bookings-self-service-widget/spec.md
	 */
	public function isPubliclyBookable(string $serviceId, string $administrationId): bool {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		return $this->findPublicService(
			objectService: $objectService,
			serviceId: $serviceId,
			administrationId: $administrationId
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
	 * A service of another administration than the widget key's is not
	 * bookable either.
	 *
	 * @param object $objectService    OR ObjectService.
	 * @param string $serviceId        The service to resolve.
	 * @param string $administrationId The administration the service must belong to.
	 *
	 * @return array<string,mixed>|null The service row, or null when not bookable.
	 */
	private function findPublicService(object $objectService, string $serviceId, string $administrationId): ?array {
		try {
			$records = $objectService
				->setRegister($this->getRegisterSlug())
				->setSchema('Service')
				->findAll(
					config: [
						'filters' => ['serviceId' => $serviceId],
						'limit' => 1,
					],
					_rbac: false,
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

			if ($administrationId === '' || (string)($row['administrationId'] ?? '') !== $administrationId) {
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
	 * @param array<string,mixed> $request The request: serviceId, resourceId and
	 *                                     the candidate startTime and endTime
	 *                                     (ISO 8601 UTC).
	 *
	 * @return bool
	 */
	private function isSlotOffered(array $request): bool {
		try {
			$check = $this->slotService->getAvailableSlots(
				serviceId: $request['serviceId'],
				resourceId: $request['resourceId'],
				date: substr($request['startTime'], 0, 10)
			);
		} catch (\Throwable $e) {
			$context = ['exception' => $e->getMessage(), 'serviceId' => $request['serviceId']];
			$this->logger->error('WidgetService: slot availability check failed', $context);
			return false;
		}

		return $this->slotsInclude(slots: ($check['slots'] ?? []), request: $request);
	}//end isSlotOffered()

	/**
	 * Whether a slot list holds exactly the requested interval.
	 *
	 * @param mixed $slots The offered slots.
	 * @param array<string,mixed> $request The request with startTime and endTime.
	 *
	 * @return bool
	 */
	private function slotsInclude(mixed $slots, array $request): bool {
		foreach ($slots as $slot) {
			if ((string)($slot['startTime'] ?? '') === $request['startTime']
				&& (string)($slot['endTime'] ?? '') === $request['endTime']
			) {
				return true;
			}
		}

		return false;
	}//end slotsInclude()
}//end class
