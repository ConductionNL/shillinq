<?php

/**
 * Booking Detail Controller — pipelinq customer-bridge slice 05.
 *
 * REST surface for the booking detail view: GET /api/v1/bookings/{id}
 * returns the Appointment record hydrated with the linked pipelinq
 * Contact profile and the most recent klantbeeld transactions. This is
 * the seam between the slice 03+04 read adapters and the slice 06 UI;
 * the controller decides when to call pipelinq (only when
 * `pipelinqContactId` is set on the Appointment per slice 01) and
 * converts adapter failures into a view-renderable `contactError`
 * without ever blocking the booking detail render.
 *
 * Endpoint behaviour summary:
 *  - 200 with the hydrated booking when found (linked or unlinked).
 *  - 200 with `contactError` set when the adapter raised — the booking
 *    detail still renders so the operator can keep working.
 *  - 200 with `notLinkedToPipelinq = true` when the Appointment has no
 *    `pipelinqContactId` (slice 06 will render the "not linked" hint).
 *  - 400 when the id path parameter is empty / 404 when the Appointment
 *    cannot be found / 503 when OpenRegister is unavailable.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Pipelinq\KlantbeeldResult;
use OCA\Shillinq\Service\Pipelinq\PipelinqContact;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\PipelinqTransportException;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Booking detail REST controller — hydrates the booking response with
 * the linked pipelinq Contact + klantbeeld history when available.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
 */
class BookingDetailController extends Controller {

	/**
	 * Default klantbeeld history page size — 5 rows is enough for the
	 * profile card in slice 06 and keeps the upstream payload small.
	 */
	private const HISTORY_DEFAULT_LIMIT = 5;

	/**
	 * Construct the controller with DI dependencies.
	 *
	 * @param IRequest $request Inbound request object.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param AdministrationContextService $context Per-administration IDOR guard (ADR-005).
	 * @param PipelinqContactAdapter $pipelinq Slice 02-04 adapter (getContact + fetchKlantbeeld).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly AdministrationContextService $context,
		private readonly PipelinqContactAdapter $pipelinq,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/v1/bookings/{id}
	 *
	 * Load the Appointment record by business id, hydrate with the
	 * linked pipelinq Contact profile + klantbeeld history (slice 03/04)
	 * when `pipelinqContactId` is set, and return the full payload to
	 * the detail view. Adapter failures degrade to `contactError`; the
	 * booking detail render always succeeds when the Appointment exists.
	 *
	 * Query parameters:
	 *  - nocache (optional) — when truthy, clears the slice-03 contact
	 *    cache before re-fetching so dev/test flows can observe a fresh
	 *    upstream response without waiting for the 5-minute TTL.
	 *  - limit  (optional) — klantbeeld page size (defaults to 5, the
	 *    adapter clamps to 1..100).
	 *  - offset (optional) — klantbeeld page offset (defaults to 0).
	 *
	 * @param string $id Appointment business id (path parameter).
	 *
	 * @return JSONResponse 200 with the hydrated booking; 400 when id is
	 *                      empty; 404 when missing; 503 when OR
	 *                      unavailable.
	 *
	 * @spec openspec/changes/bookings-pipelinq-customer-bridge-05-detail-controller-inject/tasks.md
	 */
	#[NoAdminRequired]
	public function show(string $id = ''): JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$bookingId = trim($id);
		if ($bookingId === '') {
			return new JSONResponse(['error' => 'id is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$appointment = $this->loadAppointment(appointmentId: $bookingId);
		if ($appointment === null) {
			return new JSONResponse(['error' => 'Booking not found'], Http::STATUS_NOT_FOUND);
		}

		// ADR-005 IDOR — the Appointment record carries the owning
		// administration; the context service rejects callers without
		// membership. Masked as 404 so the controller does not leak
		// existence to out-of-tenant users.
		// The `$administrationId !== ''` short-circuit that used to guard this
		// call DISABLED THE CHECK for exactly the records that need it most.
		// `?? ''` catches an absent or null administrationId and turns it into
		// '', and '' then made the whole condition false — so an Appointment
		// with no owning administration was readable by ANY authenticated user,
		// cross-tenant, in a bookkeeping app. Records in that state are not
		// hypothetical here: seed objects that fail their schema's `required`
		// list are skipped on import, which leaves schemas unpopulated and
		// partially-formed rows in play (see tests/validate-seeds.js).
		//
		// canAccess() already fails closed — AdministrationContextService::
		// canAccess() returns false for '' on its own line. The service was
		// right; the caller opted out of it. Calling it unconditionally makes
		// an unknown administration a 404 instead of a free read.
		$administrationId = (string)($appointment['administrationId'] ?? '');
		if ($this->context->canAccess($administrationId) === false) {
			return new JSONResponse(['error' => 'Booking not found'], Http::STATUS_NOT_FOUND);
		}

		$payload = [
			'booking' => $this->presentBooking(appointment: $appointment),
			'contact' => null,
			'klantbeeld' => null,
			'contactError' => null,
			'notLinkedToPipelinq' => false,
		];

		$pipelinqContactId = trim((string)($appointment['pipelinqContactId'] ?? ''));
		if ($pipelinqContactId === '') {
			$payload['notLinkedToPipelinq'] = true;
			return new JSONResponse($payload);
		}

		// Dev / test cache-bust hook — when ?nocache=1 the slice-03
		// contact cache is wiped so a fresh upstream call happens. Tests
		// exercise this to avoid relying on the 5-minute TTL.
		$noCache = filter_var(
			$this->request->getParam('nocache', false),
			FILTER_VALIDATE_BOOLEAN
		);
		if ($noCache === true) {
			$this->pipelinq->clearCache();
		}

		$limit = $this->resolveLimit();
		$offset = $this->resolveOffset();

		try {
			$contact = $this->pipelinq->getContact(externalId: $pipelinqContactId);
			$payload['contact'] = $this->presentContact(contact: $contact);
		} catch (PipelinqTransportException $e) {
			$this->logger->warning(
				'BookingDetailController: pipelinq Contact unavailable',
				[
					'app' => Application::APP_ID,
					'bookingId' => $bookingId,
					'externalId' => $pipelinqContactId,
					'status' => $e->statusCode(),
				]
			);
			$payload['contactError'] = $this->sanitiseAdapterError(exception: $e);
			// Skip klantbeeld when Contact is unreachable — there is
			// nothing for the history to attach to in the UI.
			return new JSONResponse($payload);
		} catch (Throwable $e) {
			$this->logger->error(
				'BookingDetailController: pipelinq Contact failed',
				[
					'app' => Application::APP_ID,
					'bookingId' => $bookingId,
					'exception' => $e->getMessage(),
				]
			);
			$payload['contactError'] = 'Customer profile temporarily unavailable.';
			return new JSONResponse($payload);
		}//end try

		// Klantbeeld returns an envelope (`isUnavailable()`/`isEmpty()`)
		// and never throws by contract, so we let the UI distinguish the
		// three legitimate outcomes (rows / empty / unavailable) without
		// collapsing them into a single `contactError`.
		try {
			$payload['klantbeeld'] = $this->presentKlantbeeld(
				result: $this->pipelinq->fetchKlantbeeld(
					externalId: $pipelinqContactId,
					limit: $limit,
					offset: $offset
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'BookingDetailController: klantbeeld fetch threw unexpectedly',
				[
					'app' => Application::APP_ID,
					'bookingId' => $bookingId,
					'exception' => $e->getMessage(),
				]
			);
			$payload['klantbeeld'] = $this->presentKlantbeeld(
				result: KlantbeeldResult::unavailable(limit: $limit, offset: $offset)
			);
		}//end try

		return new JSONResponse($payload);
	}//end show()

	/**
	 * Resolve the klantbeeld page limit from the query string.
	 *
	 * The adapter clamps to 1..100; the controller only catches the
	 * "missing / non-numeric" case so the default kicks in.
	 *
	 * @return int
	 */
	private function resolveLimit(): int {
		$raw = $this->request->getParam('limit', null);
		if ($raw === null || is_numeric($raw) === false) {
			return self::HISTORY_DEFAULT_LIMIT;
		}

		return (int)$raw;
	}//end resolveLimit()

	/**
	 * Resolve the klantbeeld page offset from the query string.
	 *
	 * @return int
	 */
	private function resolveOffset(): int {
		$raw = $this->request->getParam('offset', null);
		if ($raw === null || is_numeric($raw) === false) {
			return 0;
		}

		return max(0, (int)$raw);
	}//end resolveOffset()

	/**
	 * Look up an Appointment by its business id.
	 *
	 * @param string $appointmentId Appointment business id.
	 *
	 * @return array<string,mixed>|null Flat appointment row or NULL.
	 */
	private function loadAppointment(string $appointmentId): ?array {
		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$records = $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Appointment')
				->findAll(
					[
						'filters' => ['appointmentId' => $appointmentId],
						'limit' => 1,
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'BookingDetailController: appointment lookup failed',
				[
					'app' => Application::APP_ID,
					'appointmentId' => $appointmentId,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		foreach ($records as $record) {
			return $this->toArray(object: $record);
		}

		return null;
	}//end loadAppointment()

	/**
	 * Shape an Appointment row for the detail response.
	 *
	 * @param array<string,mixed> $appointment Flat OR row.
	 *
	 * @return array<string,mixed>
	 */
	private function presentBooking(array $appointment): array {
		$pipelinqContactId = null;
		// `isset()` is already false for null — the `!== null` conjunct this
		// replaces was unreachable.
		if (isset($appointment['pipelinqContactId']) === true) {
			$pipelinqContactId = (string)$appointment['pipelinqContactId'];
		}

		return [
			'appointmentId' => (string)($appointment['appointmentId'] ?? ''),
			'administrationId' => (string)($appointment['administrationId'] ?? ''),
			'serviceId' => (string)($appointment['serviceId'] ?? ''),
			'resourceId' => (string)($appointment['resourceId'] ?? ''),
			'customerId' => (string)($appointment['customerId'] ?? ''),
			'customerName' => (string)($appointment['customerName'] ?? ''),
			'customerEmail' => (string)($appointment['customerEmail'] ?? ''),
			'startTime' => (string)($appointment['startTime'] ?? ''),
			'endTime' => (string)($appointment['endTime'] ?? ''),
			'status' => (string)($appointment['status'] ?? ''),
			'notes' => (string)($appointment['notes'] ?? ''),
			'pipelinqContactId' => $pipelinqContactId,
			'createdAt' => (string)($appointment['createdAt'] ?? ''),
			'updatedAt' => (string)($appointment['updatedAt'] ?? ''),
		];

	}//end presentBooking()

	/**
	 * Shape a {@see PipelinqContact} DTO for the detail response.
	 *
	 * @param PipelinqContact $contact Adapter-returned profile.
	 *
	 * @return array<string,mixed>
	 */
	private function presentContact(PipelinqContact $contact): array {
		return [
			'externalId' => $contact->externalId,
			'legalName' => $contact->legalName,
			'email' => $contact->email,
			'phone' => $contact->phone,
			'address' => $contact->address,
			'kvkNumber' => $contact->kvkNumber,
			'found' => $contact->isFound(),
		];

	}//end presentContact()

	/**
	 * Shape a {@see KlantbeeldResult} envelope for the detail response.
	 *
	 * Preserves the three legitimate outcomes (available, empty,
	 * unavailable) via dedicated booleans so the slice-06 UI can branch.
	 *
	 * @param KlantbeeldResult $result Adapter-returned envelope.
	 *
	 * @return array<string,mixed>
	 */
	private function presentKlantbeeld(KlantbeeldResult $result): array {
		$serialised = $result->jsonSerialize();
		$serialised['empty'] = $result->isEmpty();

		return $serialised;
	}//end presentKlantbeeld()

	/**
	 * Build a user-safe `contactError` string from an adapter exception.
	 *
	 * The raw upstream body MUST NOT leak into the response (ADR-005
	 * security note in the design); only a coarse, action-oriented
	 * message is exposed.
	 *
	 * @param PipelinqTransportException $exception Transport-layer failure.
	 *
	 * @return string Sanitised, non-internal message.
	 */
	private function sanitiseAdapterError(PipelinqTransportException $exception): string {
		$status = $exception->statusCode();
		if ($status === 0) {
			// 0 = circuit breaker open / DNS / connect failure — generic.
			return 'Customer profile temporarily unavailable.';
		}

		if ($status >= 500) {
			return 'Customer profile temporarily unavailable (upstream error).';
		}

		if ($status === 401 || $status === 403) {
			return 'Customer profile cannot be loaded (authentication required).';
		}

		return 'Customer profile temporarily unavailable.';
	}//end sanitiseAdapterError()

	/**
	 * Normalise an OR record (Entity, array, or JSON-serialisable) into a flat array.
	 *
	 * @param mixed $object OR ObjectService payload.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'getObject') === true) {
				$inner = $object->getObject();
				if (is_array($inner) === true) {
					return $inner;
				}
			}

			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
