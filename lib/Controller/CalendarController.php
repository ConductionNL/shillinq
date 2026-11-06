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
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Booking\CalendarRows;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
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
	 * @param ObjectTransitionRunner $transitions Runs the declared booking transitions.
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
		private readonly ObjectTransitionRunner $transitions,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/v2/calendars — list calendars accessible to the current user.
	 *
	 * Query parameters:
	 *  - resource     (optional) filter by resource UUID/logical id.
	 *  - organization (optional) filter by administrationId (legacy alias `organization`).
	 *  - status       (optional) filter by status (active|inactive|archived).
	 *
	 * @return JSONResponse 200 with `{calendars: [...]}`, empty when the caller
	 *                      has no administration; 401 when anonymous;
	 *                      403 for an administration the caller is not in;
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

		if ($orgFilter !== '' && $this->callerMayUseAdministration(administrationId: $orgFilter) === false) {
			return new JSONResponse(['error' => 'Not authorized for this administration'], Http::STATUS_FORBIDDEN);
		}

		$administrationId = $this->resolveAdministrationId(requested: $orgFilter);
		if ($administrationId === null) {
			return new JSONResponse(['calendars' => []]);
		}

		$filters = ['administrationId' => $administrationId];
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
			$calendars[] = (new CalendarRows())->calendar(row: $this->toArray(object: $record));
		}

		return new JSONResponse(['calendars' => $calendars]);
	}//end index()

	/**
	 * GET /api/v2/calendars/{calendarId} — return one calendar by id.
	 *
	 * @param string $calendarId Logical calendar identifier.
	 *
	 * @return JSONResponse 200 with the calendar; 401 when anonymous; 404 when
	 *                      missing or outside the caller's administrations.
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

		$record = $this->loadReadableCalendar(calendarId: $calendarId);
		if ($record === null) {
			return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse((new CalendarRows())->calendar(row: $this->toArray(object: $record)));
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
	 * @return JSONResponse 200 with `{bookings: [...]}`; 404 when the calendar is
	 *                      missing or outside the caller's administrations.
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	#[NoAdminRequired]
	public function listBookings(string $calendarId = ''): JSONResponse {
		$authResult = $this->requireAuth();
		if ($authResult instanceof JSONResponse) {
			return $authResult;
		}

		$refusal = $this->readableCalendarRefusal(calendarId: trim($calendarId));
		if ($refusal !== null) {
			return $refusal;
		}

		$range = $this->requestedRange();
		if ($range instanceof JSONResponse) {
			return $range;
		}

		return $this->bookingsInRangeResponse(calendarId: trim($calendarId), range: $range);
	}//end listBookings()

	/**
	 * The 400 or 404 response when the calendar id is empty, or the calendar
	 * is missing or outside the caller's administrations; null when readable.
	 *
	 * @param string $calendarId Trimmed logical calendar identifier.
	 *
	 * @return JSONResponse|null
	 */
	private function readableCalendarRefusal(string $calendarId): ?JSONResponse {
		if ($calendarId === '') {
			return new JSONResponse(['error' => 'calendarId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->loadReadableCalendar(calendarId: $calendarId) === null) {
			return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end readableCalendarRefusal()

	/**
	 * The requested start/end range in UTC, or a 400 response when it is invalid.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|JSONResponse
	 */
	private function requestedRange(): array|JSONResponse {
		try {
			return (new CalendarRows())->range(
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
	}//end requestedRange()

	/**
	 * The calendar's bookings that intersect the range, sorted by startTime;
	 * 503 when the lookup fails.
	 *
	 * @param string $calendarId Logical calendar identifier.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range The half-open range.
	 *
	 * @return JSONResponse
	 */
	private function bookingsInRangeResponse(string $calendarId, array $range): JSONResponse {
		try {
			$records = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('Booking')
				->findAll(['filters' => ['calendar' => $calendarId], 'limit' => 5000]);
		} catch (\Throwable $e) {
			$this->logger->error('Shillinq: booking lookup failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['error' => 'Booking lookup failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$rows = array_map($this->toArray(...), $records);
		return new JSONResponse(['bookings' => (new CalendarRows())->inRange(rows: $rows, range: $range)]);
	}//end bookingsInRangeResponse()

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
	 *  - confirm    (optional) true to confirm at once through the declared
	 *               `confirm` transition. The booking always starts pending;
	 *               a posted `status` is ignored.
	 *  - overrideConflict (optional) true to create despite an overlap.
	 *
	 * @param string $calendarId Logical calendar identifier.
	 *
	 * @return JSONResponse 201 with the booking; 400 on validation;
	 *                      403 when the caller has no booking rights on the
	 *                      calendar's administration; 404 when calendar
	 *                      missing; 409 on conflict; 422 when the calendar
	 *                      has no administration; 503 when OR unavailable.
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

		$calendar = $this->bookableCalendar(calendarId: trim($calendarId));
		if ($calendar instanceof JSONResponse) {
			return $calendar;
		}

		$authError = $this->authorizeBookingCreation(calendarRow: $calendar['row']);
		if ($authError !== null) {
			return $authError;
		}

		return $this->createBookingOnCalendar(calendar: $calendar);
	}//end createBooking()

	/**
	 * The calendar to book, as its trimmed id and row; 400 when the id is
	 * empty, 404 when the calendar is missing.
	 *
	 * @param string $calendarId Trimmed logical calendar identifier.
	 *
	 * @return array{id: string, row: array<string, mixed>}|JSONResponse
	 */
	private function bookableCalendar(string $calendarId): array|JSONResponse {
		if ($calendarId === '') {
			return new JSONResponse(['error' => 'calendarId is required'], Http::STATUS_BAD_REQUEST);
		}

		$calendar = $this->loadCalendar(calendarId: $calendarId);
		if ($calendar === null) {
			return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
		}

		return ['id' => $calendarId, 'row' => $this->toArray(object: $calendar)];
	}//end bookableCalendar()

	/**
	 * Validate the booking request against an authorised calendar and create
	 * it: 422 when the calendar has no administration, 400 on an invalid
	 * request, 503 without OpenRegister.
	 *
	 * @param array{id: string, row: array<string, mixed>} $calendar The calendar.
	 *
	 * @return JSONResponse
	 */
	private function createBookingOnCalendar(array $calendar): JSONResponse {
		$administrationId = (string)($calendar['row']['administrationId'] ?? '');
		if ($administrationId === '') {
			$message = 'This calendar belongs to no administration, so it cannot take bookings.';
			return new JSONResponse(['error' => 'calendar-without-administration', 'message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$parsed = $this->parseBookingRequest();
		if ($parsed instanceof JSONResponse) {
			return $parsed;
		}

		$scope = ['administrationId' => $administrationId, 'calendar' => $calendar['id'], 'resource' => (string)($calendar['row']['resource'] ?? '')];
		return $this->storeBooking(booking: $parsed + $scope);
	}//end createBookingOnCalendar()

	/**
	 * Check conflicts and create the booking in one transaction (503 without
	 * OpenRegister). The
	 * transaction boundary plus the resource row-lock together close the
	 * read-then-write race window (REQ-004).
	 *
	 * @param array<string, mixed> $booking The parsed request plus its calendar scope.
	 *
	 * @return JSONResponse 201, 409 on an unoverridden conflict, 500 on failure.
	 */
	private function storeBooking(array $booking): JSONResponse {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return new JSONResponse(['error' => 'OpenRegister unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$this->guard->beginTransaction();
		try {
			return $this->storeBookingInTransaction(booking: $booking);
		} catch (\Throwable $e) {
			return $this->bookingCreateFailed(error: $e);
		}

	}//end storeBooking()

	/**
	 * Roll back an open transaction, log the failure and answer 500.
	 *
	 * @param \Throwable $error What went wrong.
	 *
	 * @return JSONResponse
	 */
	private function bookingCreateFailed(\Throwable $error): JSONResponse {
		if ($this->guard->inTransaction() === true) {
			$this->guard->rollBack();
		}

		$this->logger->error('Shillinq: booking create failed', ['exception' => $error->getMessage()]);
		return new JSONResponse(['error' => 'Booking create failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end bookingCreateFailed()

	/**
	 * Lock the resource, refuse an unoverridden overlap, save the booking,
	 * confirm it when asked, and commit.
	 *
	 * @param array<string, mixed> $booking The parsed request plus its calendar scope.
	 *
	 * @return JSONResponse
	 */
	private function storeBookingInTransaction(array $booking): JSONResponse {
		$existingConflicts = $this->lockedConflicts(booking: $booking);
		if ($existingConflicts !== [] && $booking['override'] === false) {
			$this->guard->rollBack();
			$message = 'The proposed booking overlaps existing bookings on this resource.';
			return new JSONResponse(['error' => 'conflict', 'message' => $message, 'conflicts' => $existingConflicts], Http::STATUS_CONFLICT);
		}

		$row = $this->saveBooking(booking: $booking);
		$this->guard->commit();

		return new JSONResponse((new CalendarRows())->format(row: $row), Http::STATUS_CREATED);
	}//end storeBookingInTransaction()

	/**
	 * Lock the resource row, then list the bookings that overlap the request.
	 *
	 * @param array<string, mixed> $booking The parsed request plus its calendar scope.
	 *
	 * @return array<int, array<string, mixed>> The conflicting bookings.
	 */
	private function lockedConflicts(array $booking): array {
		$this->conflicts->lockResource(resourceId: $booking['resource']);
		return $this->conflicts->checkConflicts(
			resourceId: $booking['resource'],
			proposedStart: $booking['start']->format('Y-m-d\\TH:i:s\\Z'),
			proposedEnd: $booking['end']->format('Y-m-d\\TH:i:s\\Z'),
		);
	}//end lockedConflicts()

	/**
	 * Save a pending booking and run the confirm transition when asked.
	 *
	 * @param array<string, mixed> $booking The parsed request plus its calendar scope.
	 *
	 * @return array<string, mixed> The saved row, with the payload as fallback for fields the store does not echo.
	 */
	private function saveBooking(array $booking): array {
		$payload = (new CalendarRows())->payload(booking: $booking);
		$saved   = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
			->saveObject(object: $payload, register: $this->settings->getRegisterSlug(), schema: 'Booking');
		$row     = array_merge($payload, $this->toArray(object: $saved));

		if ($booking['confirm'] === true) {
			$this->transitions->run(objectId: (string)($row['@self']['id'] ?? ($row['id'] ?? '')), action: 'confirm');
			$row['status'] = 'confirmed';
		}

		return $row;
	}//end saveBooking()

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
		if ($this->callerMayUseAdministration(administrationId: (string)($calendarRow['administrationId'] ?? '')) === false) {
			return new JSONResponse(
				['error' => 'calendar-booking-forbidden', 'message' => 'Not authorized to book this calendar'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end authorizeBookingCreation()

	/**
	 * Whether the caller may read or book in an administration: a member of
	 * it, or a Nextcloud admin.
	 *
	 * @param string $administrationId The administration id.
	 *
	 * @return bool
	 */
	private function callerMayUseAdministration(string $administrationId): bool {
		$callerUid = (string)($this->context->currentUserId() ?? '');
		if ($callerUid !== '' && $this->groupManager->isAdmin($callerUid) === true) {
			return true;
		}

		return $this->context->canAccess($administrationId);
	}//end callerMayUseAdministration()

	/**
	 * Load a calendar the caller may read. A calendar outside the caller's
	 * administrations reads as missing, so its existence does not leak.
	 *
	 * @param string $calendarId Logical calendar id.
	 *
	 * @return mixed The calendar record, or null.
	 */
	private function loadReadableCalendar(string $calendarId): mixed {
		$record = $this->loadCalendar(calendarId: $calendarId);
		if ($record === null) {
			return null;
		}

		$row = $this->toArray(object: $record);
		if ($this->callerMayUseAdministration(administrationId: (string)($row['administrationId'] ?? '')) === false) {
			return null;
		}

		return $record;
	}//end loadReadableCalendar()

	/**
	 * Parse and validate the `createBooking()` request body: required
	 * fields present, `startTime`/`endTime`
	 * parseable ISO 8601 timestamps, and the resulting duration at least
	 * `ConflictDetectionService::MIN_DURATION_MINUTES`. Extracted out of
	 * `createBooking()` (rather than inlined there) to keep that method's
	 * NPath complexity under this app's threshold (issue #506) — the
	 * validation moves, none of the checks change.
	 *
	 * @return array{title:string,attendee:string,confirm:bool,override:bool,start:DateTimeImmutable,end:DateTimeImmutable}|JSONResponse
	 *         The validated fields, or a 400 JSONResponse on validation failure.
	 */
	private function parseBookingRequest(): array|JSONResponse {
		$title = trim((string)$this->request->getParam('title', ''));
		$startTime = trim((string)$this->request->getParam('startTime', ''));
		$endTime = trim((string)$this->request->getParam('endTime', ''));
		$attendee = trim((string)$this->request->getParam('attendee', ''));
		$confirm = filter_var($this->request->getParam('confirm', false), FILTER_VALIDATE_BOOLEAN);
		$override = filter_var($this->request->getParam('overrideConflict', false), FILTER_VALIDATE_BOOLEAN);

		if ($title === '' || $startTime === '' || $endTime === '' || $attendee === '') {
			return new JSONResponse(
				['error' => 'title, startTime, endTime and attendee are required'],
				Http::STATUS_BAD_REQUEST,
			);
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
			'confirm' => $confirm,
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
	 * Resolve the administration id to scope reads to: the caller's explicit
	 * organization filter (index() has vetted the membership), otherwise the
	 * caller's active administration.
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
