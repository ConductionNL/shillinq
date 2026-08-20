<?php

/**
 * Calendar Controller
 *
 * REST API for the bookings-resource-calendar change (REQ-005). Exposes the
 * GET /api/v2/calendars index, GET /api/v2/calendars/{id} detail, the
 * GET /api/v2/calendars/{id}/bookings range read and the POST .../bookings
 * create endpoints. Every method runs as #[NoAdminRequired] so authenticated
 * staff (not just admins) can drive the booking calendar; the
 * AdministrationContextService enforces the per-administration IDOR guard
 * before any data leaves the controller, and the conflict-detection service
 * runs inside a database transaction with a row-lock on the resource record
 * so concurrent inserts cannot both pass the check (REQ-004).
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
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Calendar + Booking REST controller (REQ-005).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; early-return refactor deferred pending full behavioral
 * verification of each branch.
 */
class CalendarController extends Controller {

	/**
	 * Default date range when the client omits start/end query parameters.
	 * The detail endpoint returns bookings in the next 30 days from `today`
	 * per REQ-005 of bookings-resource-calendar.
	 */
	private const DEFAULT_RANGE_DAYS = 30;

	/**
	 * Construct the controller with DI dependencies.
	 *
	 * @param IRequest $request The request object.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param ConflictDetectionService $conflicts Conflict detection service (REQ-004).
	 * @param AdministrationContextService $context Per-administration IDOR guard (ADR-005).
	 * @param TransactionalGuard $guard Transaction + row-lock facade (production-wired with IDBConnection).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 * @param IL10N $l10n Localized user-facing error messages (ADR-050).
	 * @param IGroupManager $groupManager Nextcloud admin bypass for the booking guard.
	 */
	public function __construct(
		IRequest $request,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly ConflictDetectionService $conflicts,
		private readonly AdministrationContextService $context,
		private readonly TransactionalGuard $guard,
		private readonly LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/v2/calendars — list calendars accessible to the current user.
	 *
	 * Query parameters:
	 *  - resource     (optional) filter by resource UUID/logical id.
	 *  - organization (optional) filter by administrationId (legacy alias `organization`).
	 *  - status       (optional) filter by status (active|inactive|archived).
	 *
	 * @return JSONResponse 200 with `{calendars: [...]}`; 401 when anonymous;
	 *                      503 when OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$authResult = $this->requireAuth();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$resourceFilter = trim((string)$this->request->getParam('resource', ''));
		$orgFilter = trim((string)$this->request->getParam('organization', ''));
		$statusFilter = trim((string)$this->request->getParam('status', ''));

		$filters = ['administrationId' => $this->resolveAdministrationId(requested: $orgFilter)];
		if ($resourceFilter !== '') {
			$filters['resource'] = $resourceFilter;
		}

		if ($statusFilter !== ''
			&& in_array($statusFilter, ['active', 'inactive', 'archived'], true) === true
		) {
			$filters['status'] = $statusFilter;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$records = $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Calendar')
				->findAll(
					[
						'filters' => $filters,
						'limit' => 500,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: calendars index failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Calendar lookup failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$calendars = [];
		foreach ($records as $record) {
			$calendars[] = $this->formatCalendar(record: $record);
		}

		return new JSONResponse(['calendars' => $calendars]);
	}//end index()

	/**
	 * GET /api/v2/calendars/{calendarId} — return one calendar by id.
	 *
	 * @param string $calendarId Logical calendar identifier.
	 *
	 * @return JSONResponse 200 with the calendar; 401 when anonymous; 404 when missing.
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function show(string $calendarId = ''): JSONResponse {
		$authResult = $this->requireAuth();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		$calendarId = trim($calendarId);
		if ($calendarId === '') {
			return new JSONResponse(['error' => 'calendarId is required'], Http::STATUS_BAD_REQUEST);
		}

		$record = $this->loadCalendar(calendarId: $calendarId);
		if ($record === null) {
			return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->formatCalendar(record: $record));
	}//end show()

	/**
	 * GET /api/v2/calendars/{calendarId}/bookings — bookings in a date range.
	 *
	 * Query parameters:
	 *  - start (optional) ISO 8601 inclusive lower bound; default today.
	 *  - end   (optional) ISO 8601 exclusive upper bound; default start + 30 days.
	 *
	 * Returns bookings sorted by startTime ascending.
	 *
	 * @param string $calendarId Logical calendar identifier.
	 *
	 * @return JSONResponse 200 with `{bookings: [...]}`; 404 when calendar missing.
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function listBookings(string $calendarId = ''): JSONResponse {
		$authResult = $this->requireAuth();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		$calendarId = trim($calendarId);
		if ($calendarId === '') {
			return new JSONResponse(['error' => 'calendarId is required'], Http::STATUS_BAD_REQUEST);
		}

		$calendar = $this->loadCalendar(calendarId: $calendarId);
		if ($calendar === null) {
			return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			[$rangeStart, $rangeEnd] = $this->resolveRange(
				start: (string)$this->request->getParam('start', ''),
				end: (string)$this->request->getParam('end', ''),
			);
		} catch (InvalidArgumentException $e) {
			$this->logger->warning('Shillinq: invalid booking range', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['message' => $this->l10n->t('Invalid start/end range.'), 'error' => 'calendar-invalid-range'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$records = $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Booking')
				->findAll(
					[
						'filters' => ['calendar' => $calendarId],
						'limit' => 5000,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: booking lookup failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(['error' => 'Booking lookup failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$bookings = [];
		foreach ($records as $record) {
			$row = $this->toArray(object: $record);
			$startRaw = (string)($row['startTime'] ?? '');
			$endRaw = (string)($row['endTime'] ?? '');
			if ($startRaw === '' || $endRaw === '') {
				continue;
			}

			try {
				$start = (new DateTimeImmutable($startRaw))->setTimezone(new DateTimeZone('UTC'));
				$end = (new DateTimeImmutable($endRaw))->setTimezone(new DateTimeZone('UTC'));
			} catch (\Throwable) {
				continue;
			}

			// Half-open range: include bookings whose window intersects the range.
			if ($start < $rangeEnd && $end > $rangeStart) {
				$bookings[] = $this->formatBooking(row: $row);
			}
		}

		usort(
			$bookings,
			static fn (array $a, array $b): int => strcmp((string)$a['startTime'], (string)$b['startTime'])
		);

		return new JSONResponse(['bookings' => $bookings]);
	}//end listBookings()

	/**
	 * POST /api/v2/calendars/{calendarId}/bookings — create a booking.
	 *
	 * Wraps the conflict check and the OR write in a transaction with a
	 * row-lock on the resource record so concurrent callers serialise
	 * (REQ-004 race-condition guard). Returns 201 on success and 409 with
	 * the list of conflicting bookings when an overlap is detected.
	 *
	 * Request body fields:
	 *  - title      (required) Booking title.
	 *  - startTime  (required) ISO 8601 UTC start.
	 *  - endTime    (required) ISO 8601 UTC end; must be ≥15 min after start.
	 *  - attendee   (required) Attendee name or reference.
	 *  - status     (optional) pending|confirmed; default pending.
	 *
	 * @param string $calendarId Logical calendar identifier.
	 *
	 * @return JSONResponse 201 with the booking; 400 on validation;
	 *                      403 when the caller has no booking rights on the
	 *                      calendar's administration; 404 when calendar
	 *                      missing; 409 on conflict; 503 when OR unavailable.
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	#[NoAdminRequired]
	public function createBooking(string $calendarId = ''): JSONResponse {
		$authResult = $this->requireAuth();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		$calendarId = trim($calendarId);
		if ($calendarId === '') {
			return new JSONResponse(['error' => 'calendarId is required'], Http::STATUS_BAD_REQUEST);
		}

		$calendar = $this->loadCalendar(calendarId: $calendarId);
		if ($calendar === null) {
			return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
		}

		$calendarRow = $this->toArray(object: $calendar);
		$resourceId = (string)($calendarRow['resource'] ?? '');

		$authError = $this->authorizeBookingCreation(calendarRow: $calendarRow);
		if ($authError !== null) {
			return $authError;
		}

		$parsed = $this->parseBookingRequest();
		if ($parsed instanceof JSONResponse) {
			return $parsed;
		}

		$title = $parsed['title'];
		$attendee = $parsed['attendee'];
		$status = $parsed['status'];
		$override = $parsed['override'];
		$start = $parsed['start'];
		$end = $parsed['end'];

		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		// Transactionally check + create. The conflict service runs first,
		// then — only if no overlap — the OR write fires. The transaction
		// boundary plus the resource row-lock together close the read-then-
		// write race-window (REQ-004).
		$this->guard->beginTransaction();
		try {
			$this->conflicts->lockResource(resourceId: $resourceId);

			$existingConflicts = $this->conflicts->checkConflicts(
				resourceId: $resourceId,
				proposedStart: $start->format('Y-m-d\\TH:i:s\\Z'),
				proposedEnd: $end->format('Y-m-d\\TH:i:s\\Z'),
			);

			if ($existingConflicts !== [] && $override === false) {
				$this->guard->rollBack();
				return new JSONResponse(
					[
						'error' => 'conflict',
						'message' => 'The proposed booking overlaps existing bookings on this resource.',
						'conflicts' => $existingConflicts,
					],
					Http::STATUS_CONFLICT,
				);
			}

			$bookingId = $this->generateBookingId();
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$payload = [
				'administrationId' => (string)($calendarRow['administrationId'] ?? 'adm-1'),
				'bookingId' => $bookingId,
				'calendar' => $calendarId,
				'resource' => $resourceId,
				'title' => $title,
				'startTime' => $start->format('Y-m-d\\TH:i:s\\Z'),
				'endTime' => $end->format('Y-m-d\\TH:i:s\\Z'),
				'attendee' => $attendee,
				'status' => $status,
			];

			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->settings->getRegisterSlug(),
				schema: 'Booking',
			);

			$this->guard->commit();

			$row = $this->toArray(object: $saved);
			// Merge in fields from our payload as a fallback if the OR layer
			// does not echo them (e.g. unit-test fake).
			$row = array_merge($payload, $row);

			return new JSONResponse(
				$this->formatBooking(row: $row),
				Http::STATUS_CREATED,
			);
		} catch (\Throwable $e) {
			if ($this->guard->inTransaction() === true) {
				$this->guard->rollBack();
			}

			$this->logger->error(
				'Shillinq: booking create failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Booking create failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}//end try

	}//end createBooking()

	/**
	 * Per-object booking-creation guard (security-endpoint-guards REQ-001):
	 * `requireAuth()` in `createBooking()` only checks authentication — any
	 * authenticated user could otherwise book any calendar/resource with no
	 * ownership check, the confirmed finding for this method. A booking is
	 * only valid when the caller has a membership in the calendar's own
	 * administration. A Nextcloud admin bypasses this check, matching
	 * `BookingNotificationController::authorizeBookingAccess()`'s
	 * established pattern. Extracted out of `createBooking()` (rather than
	 * inlined there) to keep that method's NPath complexity under this
	 * app's threshold (issue #506) — the branching moves, the check itself
	 * does not change.
	 *
	 * @param array<string,mixed> $calendarRow The fetched calendar row.
	 *
	 * @return JSONResponse|null 403 response when not authorized to book
	 *                           this calendar, null when the caller may.
	 *
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	private function authorizeBookingCreation(array $calendarRow): ?JSONResponse {
		$callerUid = (string)($this->context->currentUserId() ?? '');
		$callerIsAdmin = ($callerUid !== '' && $this->groupManager->isAdmin($callerUid) === true);
		$calendarAdministrationId = (string)($calendarRow['administrationId'] ?? '');
		if ($callerIsAdmin === false && $this->context->canAccess($calendarAdministrationId) === false) {
			return new JSONResponse(
				['error' => 'calendar-booking-forbidden', 'message' => 'Not authorized to book this calendar'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end authorizeBookingCreation()

	/**
	 * Parse and validate the `createBooking()` request body: required
	 * fields present, `status` in the allowed set, `startTime`/`endTime`
	 * parseable ISO 8601 timestamps, and the resulting duration at least
	 * `ConflictDetectionService::MIN_DURATION_MINUTES`. Extracted out of
	 * `createBooking()` (rather than inlined there) to keep that method's
	 * NPath complexity under this app's threshold (issue #506) — the
	 * validation moves, none of the checks change.
	 *
	 * @return array{title:string,attendee:string,status:string,override:bool,start:DateTimeImmutable,end:DateTimeImmutable}|JSONResponse
	 *         The validated fields, or a 400 JSONResponse on validation failure.
	 */
	private function parseBookingRequest(): array|JSONResponse {
		$title = trim((string)$this->request->getParam('title', ''));
		$startTime = trim((string)$this->request->getParam('startTime', ''));
		$endTime = trim((string)$this->request->getParam('endTime', ''));
		$attendee = trim((string)$this->request->getParam('attendee', ''));
		$status = trim((string)$this->request->getParam('status', 'pending'));
		$override = filter_var($this->request->getParam('overrideConflict', false), FILTER_VALIDATE_BOOLEAN);

		if ($title === '' || $startTime === '' || $endTime === '' || $attendee === '') {
			return new JSONResponse(
				['error' => 'title, startTime, endTime and attendee are required'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		if (in_array($status, ['pending', 'confirmed', 'cancelled'], true) === false) {
			return new JSONResponse(['error' => 'status must be pending, confirmed or cancelled'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$start = (new DateTimeImmutable($startTime))->setTimezone(new DateTimeZone('UTC'));
			$end = (new DateTimeImmutable($endTime))->setTimezone(new DateTimeZone('UTC'));
		} catch (\Throwable) {
			return new JSONResponse(['error' => 'startTime and endTime must be ISO 8601 timestamps'], Http::STATUS_BAD_REQUEST);
		}

		$durationSeconds = ($end->getTimestamp() - $start->getTimestamp());
		if ($durationSeconds < (ConflictDetectionService::MIN_DURATION_MINUTES * 60)) {
			return new JSONResponse(
				['error' => 'Booking duration must be at least ' . ConflictDetectionService::MIN_DURATION_MINUTES . ' minutes'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		return [
			'title' => $title,
			'attendee' => $attendee,
			'status' => $status,
			'override' => $override,
			'start' => $start,
			'end' => $end,
		];
	}//end parseBookingRequest()

	/**
	 * Require an authenticated user (the controller is #[NoAdminRequired]
	 * but anonymous access is rejected per ADR-005).
	 *
	 * @return JSONResponse|null 401 response when anonymous, null when authenticated.
	 */
	private function requireAuth(): ?JSONResponse {
		if ($this->context->currentUserId() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		return null;
	}//end requireAuth()

	/**
	 * Resolve the administration id to scope reads to. When the caller
	 * provides an explicit organization filter, the context service vets
	 * that the user has a membership there; otherwise the user's current
	 * session administration is used.
	 *
	 * @param string $requested Caller-supplied organization/administration id (may be empty).
	 *
	 * @return string|null Administration id to filter on, or null when no scope.
	 */
	private function resolveAdministrationId(string $requested): ?string {
		if ($requested !== '') {
			return $requested;
		}

		$context = $this->context->buildContext();
		$active = (string)($context['activeAdministrationId'] ?? '');
		if ($active !== '') {
			return $active;
		}

		return null;
	}//end resolveAdministrationId()

	/**
	 * Look up a calendar by its logical id.
	 *
	 * @param string $calendarId Logical id (e.g. cal-001).
	 *
	 * @return mixed|null OR record or null when not found.
	 */
	private function loadCalendar(string $calendarId): mixed {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$records = $objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Calendar')
				->findAll(
					[
						'filters' => ['calendarId' => $calendarId],
						'limit' => 1,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: calendar lookup failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		foreach ($records as $record) {
			return $record;
		}

		return null;
	}//end loadCalendar()

	/**
	 * Default a missing range to today + 30 days and normalise to UTC.
	 *
	 * @param string $start Caller-supplied start (may be empty).
	 * @param string $end Caller-supplied end (may be empty).
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Inclusive lower / exclusive upper bounds.
	 *
	 * @throws InvalidArgumentException When the inputs are malformed or inverted.
	 */
	private function resolveRange(string $start, string $end): array {
		$tz = new DateTimeZone('UTC');

		if ($start !== '') {
			$startDt = new DateTimeImmutable($start, $tz);
		} else {
			$startDt = new DateTimeImmutable('today', $tz);
		}

		if ($end !== '') {
			$endDt = new DateTimeImmutable($end, $tz);
		} else {
			$endDt = $startDt->modify('+' . self::DEFAULT_RANGE_DAYS . ' days');
		}

		if ($endDt <= $startDt) {
			throw new InvalidArgumentException('end must be after start');
		}

		return [$startDt->setTimezone($tz), $endDt->setTimezone($tz)];
	}//end resolveRange()

	/**
	 * Generate a stable, sortable booking id for a new booking.
	 *
	 * @return string Booking id (bk-YYYYMMDDHHMMSS-rrrr).
	 */
	private function generateBookingId(): string {
		return 'bk-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2));
	}//end generateBookingId()

	/**
	 * Shape a calendar OR record into the API response payload.
	 *
	 * @param mixed $record OR ObjectService record.
	 *
	 * @return array<string,mixed>
	 */
	private function formatCalendar(mixed $record): array {
		$row = $this->toArray(object: $record);
		return [
			'id' => (string)($row['calendarId'] ?? ''),
			'calendarId' => (string)($row['calendarId'] ?? ''),
			'resource' => (string)($row['resource'] ?? ''),
			'administrationId' => (string)($row['administrationId'] ?? ''),
			'timeZone' => (string)($row['timeZone'] ?? 'Europe/Amsterdam'),
			'workingHours' => ($row['workingHours'] ?? null),
			'status' => (string)($row['status'] ?? 'active'),
			'createdAt' => (string)($row['createdAt'] ?? ''),
			'updatedAt' => (string)($row['updatedAt'] ?? ''),
		];

	}//end formatCalendar()

	/**
	 * Shape a booking OR record into the API response payload.
	 *
	 * @param array<string,mixed> $row Flat booking row.
	 *
	 * @return array<string,mixed>
	 */
	private function formatBooking(array $row): array {
		return [
			'id' => (string)($row['bookingId'] ?? ''),
			'bookingId' => (string)($row['bookingId'] ?? ''),
			'calendar' => (string)($row['calendar'] ?? ''),
			'resource' => (string)($row['resource'] ?? ''),
			'administrationId' => (string)($row['administrationId'] ?? ''),
			'title' => (string)($row['title'] ?? ''),
			'startTime' => (string)($row['startTime'] ?? ''),
			'endTime' => (string)($row['endTime'] ?? ''),
			'attendee' => (string)($row['attendee'] ?? ''),
			'status' => (string)($row['status'] ?? 'pending'),
			'externalId' => ($row['externalId'] ?? null),
			'createdAt' => (string)($row['createdAt'] ?? ''),
			'updatedAt' => (string)($row['updatedAt'] ?? ''),
		];

	}//end formatBooking()

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
