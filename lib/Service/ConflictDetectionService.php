<?php

/**
 * Shillinq Conflict Detection Service
 *
 * Stateless interval-overlap conflict detection for the booking module. Prevents
 * double-booking the same resource by comparing a proposed booking's time interval
 * against existing non-cancelled bookings on that resource (in UTC).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects scheduling conflicts (overlapping bookings) on a single resource.
 *
 * Conflict rule (bookings-resource-calendar REQ-004): two bookings on the same
 * resource conflict when their half-open time intervals [start, end) overlap by
 * any amount. Adjacent bookings that merely touch (A ends exactly when B starts)
 * do NOT conflict. Cancelled bookings never participate in conflict detection.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 */
class ConflictDetectionService
{

    /**
     * Booking schema slug in OpenRegister.
     *
     * @var string
     */
    private const SCHEMA_BOOKING = 'Booking';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The server container (used to resolve
     *                                      the OpenRegister ObjectService lazily so
     *                                      Shillinq boots even when OR is absent).
     * @param SettingsService    $settings  Provides the configured register slug.
     * @param LoggerInterface    $logger    Logger.
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private SettingsService $settings,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Pure interval-overlap test on UTC timestamps (seconds since epoch).
     *
     * Uses half-open intervals [start, end): an overlap exists only when one
     * interval starts strictly before the other ends AND ends strictly after the
     * other starts. Touching endpoints (aEnd === bStart) return false.
     *
     * @param int $aStart Proposed booking start (epoch seconds, UTC).
     * @param int $aEnd   Proposed booking end (epoch seconds, UTC).
     * @param int $bStart Existing booking start (epoch seconds, UTC).
     * @param int $bEnd   Existing booking end (epoch seconds, UTC).
     *
     * @return bool True when the two intervals overlap.
     */
    public static function intervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return ($aStart < $bEnd && $aEnd > $bStart);

    }//end intervalsOverlap()

    /**
     * Parse an ISO-8601 / RFC-3339 UTC timestamp into epoch seconds.
     *
     * Returns null when the input is not a parseable timestamp. All booking times
     * are stored in UTC, so the comparison is timezone-agnostic.
     *
     * @param mixed $value The timestamp string.
     *
     * @return int|null Epoch seconds, or null when unparseable.
     */
    public static function toEpoch(mixed $value): ?int
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        $epoch = strtotime($value);
        if ($epoch === false) {
            return null;
        }

        return $epoch;

    }//end toEpoch()

    /**
     * Filter a list of existing bookings down to those that conflict with the
     * proposed interval on the same resource.
     *
     * Pure function over already-fetched booking arrays — this is the unit-testable
     * core. Cancelled bookings and the optionally-excluded booking (for edits) are
     * skipped. Bookings with unparseable times are ignored (defensive).
     *
     * @param array<int,array<string,mixed>> $existing         Existing booking rows
     *                                                         (each with startTime,
     *                                                         endTime, status, and an
     *                                                         id under @self.id or id).
     * @param string                         $startTime        Proposed start (UTC ISO-8601).
     * @param string                         $endTime          Proposed end (UTC ISO-8601).
     * @param string|null                    $excludeBookingId Booking id to skip (self, on edit).
     *
     * @return array<int,array<string,mixed>> The conflicting bookings.
     */
    public function findOverlapping(
        array $existing,
        string $startTime,
        string $endTime,
        ?string $excludeBookingId=null
    ): array {
        $aStart = self::toEpoch(value: $startTime);
        $aEnd   = self::toEpoch(value: $endTime);
        if ($aStart === null || $aEnd === null) {
            return [];
        }

        $conflicts = [];
        foreach ($existing as $booking) {
            $status = ($booking['status'] ?? null);
            if ($status === 'cancelled') {
                continue;
            }

            $bookingId = $this->extractId(booking: $booking);
            if ($excludeBookingId !== null && $bookingId !== null && $bookingId === $excludeBookingId) {
                continue;
            }

            $bStart = self::toEpoch(value: ($booking['startTime'] ?? null));
            $bEnd   = self::toEpoch(value: ($booking['endTime'] ?? null));
            if ($bStart === null || $bEnd === null) {
                continue;
            }

            if (self::intervalsOverlap(aStart: $aStart, aEnd: $aEnd, bStart: $bStart, bEnd: $bEnd) === true) {
                $conflicts[] = $booking;
            }
        }//end foreach

        return $conflicts;

    }//end findOverlapping()

    /**
     * Check for conflicts against persisted bookings on a resource.
     *
     * Fetches all non-cancelled bookings for the resource via the OpenRegister
     * ObjectService (real API: setRegister/setSchema/findAll) and delegates the
     * overlap test to {@see findOverlapping()}. Returns an empty array when
     * OpenRegister is unavailable (the caller treats that as "no conflict known"
     * but the booking write itself will also fail, so this never silently allows
     * a double-book).
     *
     * @param string      $resourceId       The resource UUID/slug to scope the check to.
     * @param string      $startTime        Proposed start (UTC ISO-8601).
     * @param string      $endTime          Proposed end (UTC ISO-8601).
     * @param string|null $excludeBookingId Booking id to skip (self, on edit).
     *
     * @return array<int,array<string,mixed>> The conflicting bookings.
     */
    public function checkConflicts(
        string $resourceId,
        string $startTime,
        string $endTime,
        ?string $excludeBookingId=null
    ): array {
        $existing = $this->fetchResourceBookings(resourceId: $resourceId);
        return $this->findOverlapping(
            existing: $existing,
            startTime: $startTime,
            endTime: $endTime,
            excludeBookingId: $excludeBookingId
        );

    }//end checkConflicts()

    /**
     * Fetch all bookings for a resource via the OpenRegister ObjectService.
     *
     * @param string $resourceId The resource UUID/slug.
     *
     * @return array<int,array<string,mixed>> Booking rows as associative arrays.
     */
    private function fetchResourceBookings(string $resourceId): array
    {
        if ($this->settings->isOpenRegisterAvailable() === false) {
            $this->logger->warning('Shillinq: OpenRegister unavailable, conflict check skipped (write will be rejected downstream)');
            return [];
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $registerSlug  = $this->settings->getRegisterSlug();

            $results = $objectService
                ->setRegister($registerSlug)
                ->setSchema(self::SCHEMA_BOOKING)
                ->findAll(
                    [
                        'filters' => ['resource' => $resourceId],
                        'limit'   => 5000,
                    ]
                );

            return $this->normalizeRows(results: $results);
        } catch (\Throwable $e) {
            // Do NOT silently treat an error as "no conflicts": log and re-throw so
            // the booking creation transaction fails closed rather than open.
            $this->logger->error('Shillinq: conflict pre-check failed: '.$e->getMessage());
            throw $e;
        }//end try

    }//end fetchResourceBookings()

    /**
     * Normalize ObjectService results (ObjectEntity instances or arrays) into
     * plain associative arrays carrying at least startTime, endTime, status, id.
     *
     * @param iterable<mixed> $results The raw ObjectService result set.
     *
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRows(iterable $results): array
    {
        $rows = [];
        foreach ($results as $row) {
            if (is_array($row) === true) {
                $rows[] = $row;
                continue;
            }

            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $serialized = $row->jsonSerialize();
                if (is_array($serialized) === true) {
                    $rows[] = $serialized;
                }
            }
        }//end foreach

        return $rows;

    }//end normalizeRows()

    /**
     * Extract a booking's identifier from either the OpenRegister @self envelope
     * or a flat id field.
     *
     * @param array<string,mixed> $booking The booking row.
     *
     * @return string|null
     */
    private function extractId(array $booking): ?string
    {
        $self = ($booking['@self'] ?? null);
        if (is_array($self) === true) {
            $id = ($self['id'] ?? $self['uuid'] ?? $self['slug'] ?? null);
            if ($id !== null) {
                return (string) $id;
            }
        }

        $flat = ($booking['id'] ?? $booking['uuid'] ?? null);
        if ($flat !== null) {
            return (string) $flat;
        }

        return null;

    }//end extractId()
}//end class
