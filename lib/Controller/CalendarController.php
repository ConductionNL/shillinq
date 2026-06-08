<?php

/**
 * Shillinq Calendar Controller
 *
 * REST API for the booking module: read calendars, read bookings in a date range,
 * and create bookings with server-side conflict detection (bookings-resource-calendar
 * REQ-005). Backed by the OpenRegister ObjectService; all times are handled in UTC.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\ConflictDetectionService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Calendar and booking REST endpoints for the Shillinq booking module.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-4
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalendarController extends Controller
{

    /**
     * Calendar schema slug in OpenRegister.
     *
     * @var string
     */
    private const SCHEMA_CALENDAR = 'Calendar';

    /**
     * Booking schema slug in OpenRegister.
     *
     * @var string
     */
    private const SCHEMA_BOOKING = 'Booking';

    /**
     * Minimum booking duration in seconds (15 minutes) per REQ-007.
     *
     * @var int
     */
    private const MIN_DURATION_SECONDS = 900;

    /**
     * Allowed booking status values per REQ-003.
     *
     * @var array<int,string>
     */
    private const BOOKING_STATUSES = ['pending', 'confirmed', 'cancelled'];

    /**
     * Constructor.
     *
     * @param IRequest                 $request   The request object.
     * @param ContainerInterface       $container The server container (lazy OR resolution).
     * @param SettingsService          $settings  Provides the configured register slug.
     * @param ConflictDetectionService $conflicts Conflict detection service.
     * @param LoggerInterface          $logger    Logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private SettingsService $settings,
        private ConflictDetectionService $conflicts,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/v2/calendars — list calendars, optionally filtered.
     *
     * Filters: resource, organization, status. Results are scoped to the
     * configured register; OpenRegister applies its own RBAC/tenant boundary.
     *
     * @param string|null $resource     Optional resource UUID/slug filter.
     * @param string|null $organization Optional organization filter.
     * @param string|null $status       Optional status filter.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookings-resource-calendar/specs/bookings/spec.md#req-005-calendar-api-endpoints-for-reading-calendars-and-bookings
     */
    #[NoAdminRequired]
    public function index(?string $resource=null, ?string $organization=null, ?string $status=null): JSONResponse
    {
        $unavailable = $this->guardOpenRegister();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $filters = [];
        if ($resource !== null && $resource !== '') {
            $filters['resource'] = $resource;
        }

        if ($organization !== null && $organization !== '') {
            $filters['organization'] = $organization;
        }

        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        try {
            $objectService = $this->objectService();
            $results       = $objectService
                ->setRegister($this->settings->getRegisterSlug())
                ->setSchema(self::SCHEMA_CALENDAR)
                ->findAll(['filters' => $filters, 'limit' => 1000]);

            return new JSONResponse(array_map([$this, 'serialize'], $this->normalize(results: $results)));
        } catch (\Throwable $e) {
            // Register not yet provisioned on this instance — return an empty
            // list rather than 500 so the UI/API stays usable on a fresh
            // install where the seed hasn't run. The diagnostic message is
            // still logged below for operator visibility.
            if ($this->isRegisterAbsent(exception: $e) === true) {
                $this->logger->info(
                    'Shillinq CalendarController: calendars register not provisioned; returning empty list'
                );
                return new JSONResponse([]);
            }

            return $this->serverError(message: 'Failed to list calendars', e: $e);
        }

    }//end index()

    /**
     * Detect "register-not-found" outcomes so the controller can degrade gracefully
     * on a freshly-installed instance instead of returning 500.
     *
     * @param \Throwable $exception Caught exception from an OpenRegister call.
     *
     * @return bool True when the exception signals an absent calendars register.
     */
    private function isRegisterAbsent(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'found none') === true) {
            return true;
        }

        if (str_contains($message, 'register') === true
            && (str_contains($message, 'not found') === true
                || str_contains($message, 'does not exist') === true)
        ) {
            return true;
        }

        return false;

    }//end isRegisterAbsent()

    /**
     * GET /api/v2/calendars/{calendarId} — fetch a single calendar.
     *
     * @param string $calendarId The calendar UUID/slug.
     *
     * @return JSONResponse 200 with the calendar, or 404 when not found.
     *
     * @spec openspec/changes/bookings-resource-calendar/specs/bookings/spec.md#req-005-calendar-api-endpoints-for-reading-calendars-and-bookings
     */
    #[NoAdminRequired]
    public function show(string $calendarId): JSONResponse
    {
        $unavailable = $this->guardOpenRegister();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $calendar = $this->loadCalendar(calendarId: $calendarId);
        if ($calendar === null) {
            return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($this->serialize(row: $calendar));

    }//end show()

    /**
     * GET /api/v2/calendars/{calendarId}/bookings — list bookings in a date range.
     *
     * Bookings are scoped to the calendar's resource and the [start, end] window
     * (defaults: today .. today+30 days), sorted ascending by startTime.
     *
     * @param string      $calendarId The calendar UUID/slug.
     * @param string|null $start      ISO-8601 range start (default: today, UTC).
     * @param string|null $end        ISO-8601 range end (default: start + 30 days).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/bookings-resource-calendar/specs/bookings/spec.md#req-005-calendar-api-endpoints-for-reading-calendars-and-bookings
     */
    #[NoAdminRequired]
    public function bookings(string $calendarId, ?string $start=null, ?string $end=null): JSONResponse
    {
        $unavailable = $this->guardOpenRegister();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $calendar = $this->loadCalendar(calendarId: $calendarId);
        if ($calendar === null) {
            return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
        }

        $startTs = ConflictDetectionService::toEpoch(value: $start) ?? time();
        $endTs   = ConflictDetectionService::toEpoch(value: $end) ?? ($startTs + (30 * 86400));

        try {
            $rows = $this->loadCalendarBookings(calendar: $calendar);
        } catch (\Throwable $e) {
            return $this->serverError(message: 'Failed to list bookings', e: $e);
        }

        $inRange = [];
        foreach ($rows as $row) {
            $bStart = ConflictDetectionService::toEpoch(value: ($row['startTime'] ?? null));
            if ($bStart === null) {
                continue;
            }

            if ($bStart >= $startTs && $bStart <= $endTs) {
                $inRange[] = $row;
            }
        }

        usort(
            $inRange,
            static function (array $a, array $b): int {
                $aTs = (ConflictDetectionService::toEpoch(value: ($a['startTime'] ?? null)) ?? 0);
                $bTs = (ConflictDetectionService::toEpoch(value: ($b['startTime'] ?? null)) ?? 0);
                return ($aTs <=> $bTs);
            }
        );

        return new JSONResponse(array_map([$this, 'serialize'], $inRange));

    }//end bookings()

    /**
     * POST /api/v2/calendars/{calendarId}/bookings — create a booking.
     *
     * Validates the payload, runs server-side conflict detection on the calendar's
     * resource, and creates the booking. Returns 409 with the conflicting bookings
     * when an overlap is detected (unless ?force=1 is passed to override).
     *
     * @param string      $calendarId The calendar UUID/slug.
     * @param string|null $title      Booking title.
     * @param string|null $startTime  Start time (UTC ISO-8601).
     * @param string|null $endTime    End time (UTC ISO-8601).
     * @param string|null $attendee   Attendee name/reference.
     * @param string      $status     Booking status (default: pending).
     * @param bool        $force      Override conflict detection when true.
     *
     * @return JSONResponse 201 created, 400 invalid, 404 no calendar, 409 conflict.
     *
     * @spec openspec/changes/bookings-resource-calendar/specs/bookings/spec.md#req-005-calendar-api-endpoints-for-reading-calendars-and-bookings
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    #[NoAdminRequired]
    public function createBooking(
        string $calendarId,
        ?string $title=null,
        ?string $startTime=null,
        ?string $endTime=null,
        ?string $attendee=null,
        string $status='pending',
        bool $force=false
    ): JSONResponse {
        $unavailable = $this->guardOpenRegister();
        if ($unavailable !== null) {
            return $unavailable;
        }

        $calendar = $this->loadCalendar(calendarId: $calendarId);
        if ($calendar === null) {
            return new JSONResponse(['error' => 'Calendar not found'], Http::STATUS_NOT_FOUND);
        }

        $validation = $this->validateBookingPayload(
            title: $title,
            startTime: $startTime,
            endTime: $endTime,
            attendee: $attendee,
            status: $status
        );
        if ($validation !== null) {
            return new JSONResponse(['error' => $validation], Http::STATUS_BAD_REQUEST);
        }

        $resourceId = (string) ($calendar['resource'] ?? '');
        if ($resourceId === '') {
            return new JSONResponse(['error' => 'Calendar has no resource'], Http::STATUS_BAD_REQUEST);
        }

        try {
            // Conflict detection runs against persisted bookings before the write,
            // failing closed: a fetch error throws and surfaces as a 500 rather than
            // silently allowing a double-book.
            $conflicting = $this->conflicts->checkConflicts(
                resourceId: $resourceId,
                startTime: (string) $startTime,
                endTime: (string) $endTime
            );

            if (empty($conflicting) === false && $force === false) {
                return new JSONResponse(
                    [
                        'error'     => 'Booking conflict detected',
                        'conflicts' => array_map([$this, 'serialize'], $conflicting),
                    ],
                    Http::STATUS_CONFLICT
                );
            }

            $object = [
                'calendar'  => (string) $calendarId,
                'resource'  => $resourceId,
                'title'     => (string) $title,
                'startTime' => (string) $startTime,
                'endTime'   => (string) $endTime,
                'attendee'  => (string) $attendee,
                'status'    => $status,
            ];

            $created = $this->objectService()->saveObject(
                object: $object,
                register: $this->settings->getRegisterSlug(),
                schema: self::SCHEMA_BOOKING,
            );

            return new JSONResponse($this->serialize(row: $this->toArray(row: $created)), Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->serverError(message: 'Failed to create booking', e: $e);
        }//end try

    }//end createBooking()

    /**
     * Validate a booking creation payload. Returns an error string or null.
     *
     * @param string|null $title     Title.
     * @param string|null $startTime Start (UTC ISO-8601).
     * @param string|null $endTime   End (UTC ISO-8601).
     * @param string|null $attendee  Attendee.
     * @param string      $status    Status.
     *
     * @return string|null The first validation error, or null when valid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function validateBookingPayload(
        ?string $title,
        ?string $startTime,
        ?string $endTime,
        ?string $attendee,
        string $status
    ): ?string {
        if ($title === null || trim($title) === '') {
            return 'title is required';
        }

        if ($attendee === null || trim($attendee) === '') {
            return 'attendee is required';
        }

        if (in_array($status, self::BOOKING_STATUSES, true) === false) {
            return 'status must be one of: '.implode(', ', self::BOOKING_STATUSES);
        }

        $startTs = ConflictDetectionService::toEpoch(value: $startTime);
        $endTs   = ConflictDetectionService::toEpoch(value: $endTime);
        if ($startTs === null) {
            return 'startTime is required and must be a valid ISO-8601 timestamp';
        }

        if ($endTs === null) {
            return 'endTime is required and must be a valid ISO-8601 timestamp';
        }

        if ($endTs <= $startTs) {
            return 'endTime must be after startTime';
        }

        if (($endTs - $startTs) < self::MIN_DURATION_SECONDS) {
            return 'booking duration must be at least 15 minutes';
        }

        return null;

    }//end validateBookingPayload()

    /**
     * Load a single calendar by id, or null when not found.
     *
     * @param string $calendarId The calendar UUID/slug.
     *
     * @return array<string,mixed>|null
     */
    private function loadCalendar(string $calendarId): ?array
    {
        try {
            $object = $this->objectService()->find(
                id: $calendarId,
                register: $this->settings->getRegisterSlug(),
                schema: self::SCHEMA_CALENDAR,
            );
        } catch (\Throwable $e) {
            $this->logger->info('Shillinq: calendar lookup failed for '.$calendarId.': '.$e->getMessage());
            return null;
        }

        if ($object === null) {
            return null;
        }

        return $this->toArray(row: $object);

    }//end loadCalendar()

    /**
     * Load all bookings for a calendar's resource.
     *
     * @param array<string,mixed> $calendar The calendar row.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadCalendarBookings(array $calendar): array
    {
        $resourceId = (string) ($calendar['resource'] ?? '');
        $filters    = [];
        if ($resourceId !== '') {
            $filters['resource'] = $resourceId;
        }

        $results = $this->objectService()
            ->setRegister($this->settings->getRegisterSlug())
            ->setSchema(self::SCHEMA_BOOKING)
            ->findAll(['filters' => $filters, 'limit' => 5000]);

        return $this->normalize(results: $results);

    }//end loadCalendarBookings()

    /**
     * Resolve the OpenRegister ObjectService from the container.
     *
     * @return object The ObjectService instance.
     */
    private function objectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()

    /**
     * Return a 503 JSONResponse when OpenRegister is unavailable, else null.
     *
     * @return JSONResponse|null
     */
    private function guardOpenRegister(): ?JSONResponse
    {
        if ($this->settings->isOpenRegisterAvailable() === false) {
            return new JSONResponse(
                ['error' => 'OpenRegister is not installed or enabled'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        return null;

    }//end guardOpenRegister()

    /**
     * Normalize an ObjectService result set into plain arrays.
     *
     * @param iterable<mixed> $results The result set.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalize(iterable $results): array
    {
        $rows = [];
        foreach ($results as $row) {
            $rows[] = $this->toArray(row: $row);
        }

        return $rows;

    }//end normalize()

    /**
     * Convert an ObjectEntity (or array) into a plain associative array.
     *
     * @param mixed $row The row.
     *
     * @return array<string,mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            $serialized = $row->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        return [];

    }//end toArray()

    /**
     * Serialize a row for the API response, flattening the OpenRegister @self
     * envelope so id/uuid surface at the top level (no internal-only fields leak).
     *
     * @param array<string,mixed> $row The row.
     *
     * @return array<string,mixed>
     */
    private function serialize(array $row): array
    {
        $self = ($row['@self'] ?? null);
        if (is_array($self) === true) {
            $id = ($self['id'] ?? $self['uuid'] ?? $self['slug'] ?? null);
            if ($id !== null && isset($row['id']) === false) {
                $row['id'] = $id;
            }

            unset($row['@self']);
        }

        return $row;

    }//end serialize()

    /**
     * Build a 500 JSONResponse without leaking internals to the client.
     *
     * @param string     $message Public-safe message.
     * @param \Throwable $e       The caught exception (logged, not returned).
     *
     * @return JSONResponse
     */
    private function serverError(string $message, \Throwable $e): JSONResponse
    {
        $this->logger->error('Shillinq CalendarController: '.$message.': '.$e->getMessage());
        return new JSONResponse(['error' => $message], Http::STATUS_INTERNAL_SERVER_ERROR);

    }//end serverError()
}//end class
