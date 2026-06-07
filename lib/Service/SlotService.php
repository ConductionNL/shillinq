<?php

/**
 * Slot Service
 *
 * Computes available appointment slots for the public self-service booking
 * widget (REQ-WSW-002). A slot is available when it falls inside the
 * resource's operational hours, does not overlap a confirmed appointment, and
 * is not in the past. Results are cached for 5 minutes per service/resource/date
 * combination and the cache is invalidated on appointment creation.
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
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes conflict-free time slots from resource hours minus appointments.
 *
 * The pure computation in computeSlots() is dependency-free and unit-tested in
 * isolation; getAvailableSlots() wires it to OpenRegister data and the cache.
 *
 * @spec openspec/changes/bookings-self-service-widget/specs/bookings-self-service-widget/spec.md
 */
class SlotService
{
    /**
     * Slot-cache time-to-live in seconds (5 minutes per design D5).
     *
     * @var int
     */
    private const CACHE_TTL = 300;

    /**
     * Construct the service with lazy DI of OR's ObjectService.
     *
     * @param ContainerInterface $container    DI container for OR's ObjectService.
     * @param IAppConfig         $appConfig    App config for register-slug resolution.
     * @param ICacheFactory      $cacheFactory Distributed cache factory for slot caching.
     * @param LoggerInterface    $logger       Nextcloud logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Return the configured register slug, falling back to 'shillinq'.
     *
     * @return string
     */
    private function getRegisterSlug(): string
    {
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
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'SlotService: ObjectService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Resolve the distributed slot cache, or null when unavailable.
     *
     * @return ICache|null
     */
    private function getCache(): ?ICache
    {
        if ($this->cacheFactory->isAvailable() === false) {
            return null;
        }

        return $this->cacheFactory->createDistributed('shillinq_widget_slots');

    }//end getCache()

    /**
     * Build the cache key for a service/resource/date combination.
     *
     * @param string $serviceSlug The booked service.
     * @param string $resourceId  The resource.
     * @param string $date        The ISO date (YYYY-MM-DD).
     *
     * @return string
     */
    private function cacheKey(string $serviceSlug, string $resourceId, string $date): string
    {
        return 'slots_'.md5($serviceSlug.'|'.$resourceId.'|'.$date);

    }//end cacheKey()

    /**
     * Invalidate the cached slots for a service/resource/date combination.
     *
     * Called after an appointment is created so the next read recomputes
     * availability (REQ-WSW-002 cache-invalidation scenario).
     *
     * @param string $serviceSlug The booked service.
     * @param string $resourceId  The resource.
     * @param string $date        The ISO date (YYYY-MM-DD).
     *
     * @return void
     */
    public function invalidate(string $serviceSlug, string $resourceId, string $date): void
    {
        $cache = $this->getCache();
        if ($cache !== null) {
            $cache->remove($this->cacheKey(serviceSlug: $serviceSlug, resourceId: $resourceId, date: $date));
        }

    }//end invalidate()

    /**
     * Pure slot computation: gaps in operational hours minus booked intervals.
     *
     * Times are expressed as integer minutes-from-midnight in the resource's
     * local timezone. A candidate slot [start, start+duration) is available iff
     * it stays within [open, close), starts at or after `nowMinutes` (past
     * filtering — pass -1 to disable), and does not overlap any booked interval.
     *
     * @param int                   $openMinutes  Operational start (minutes-from-midnight).
     * @param int                   $closeMinutes Operational end (minutes-from-midnight).
     * @param int                   $duration     Service duration in minutes (> 0).
     * @param array<array{int,int}> $booked       List of [start, end) booked intervals.
     * @param int                   $nowMinutes   Earliest permitted start; -1 disables past filtering.
     * @param int                   $step         Slot granularity in minutes (defaults to duration).
     *
     * @return array<array{startMinutes:int,endMinutes:int}> Available slots, ascending.
     */
    public function computeSlots(
        int $openMinutes,
        int $closeMinutes,
        int $duration,
        array $booked,
        int $nowMinutes=-1,
        int $step=0
    ): array {
        if ($duration <= 0 || $closeMinutes <= $openMinutes) {
            return [];
        }

        if ($step <= 0) {
            $step = $duration;
        }

        $slots = [];
        for ($start = $openMinutes; ($start + $duration) <= $closeMinutes; $start += $step) {
            $end = ($start + $duration);

            if ($nowMinutes >= 0 && $start < $nowMinutes) {
                continue;
            }

            $overlaps = false;
            foreach ($booked as $interval) {
                $bStart = (int) $interval[0];
                $bEnd   = (int) $interval[1];
                // Half-open overlap test: [start,end) intersects [bStart,bEnd).
                if ($start < $bEnd && $bStart < $end) {
                    $overlaps = true;
                    break;
                }
            }

            if ($overlaps === false) {
                $slots[] = ['startMinutes' => $start, 'endMinutes' => $end];
            }
        }//end for

        return $slots;

    }//end computeSlots()

    /**
     * Convert an HH:MM time string to minutes-from-midnight.
     *
     * @param string $hhmm The 24h time (e.g. '09:00').
     *
     * @return int Minutes-from-midnight, or 0 when unparseable.
     */
    private function toMinutes(string $hhmm): int
    {
        $parts = explode(':', $hhmm);
        if (count($parts) < 2) {
            return 0;
        }

        return (((int) $parts[0]) * 60) + (int) $parts[1];

    }//end toMinutes()

    /**
     * Compute available slots for a service on a given date (REQ-WSW-002).
     *
     * Loads the service (for duration + resource), the resource (for hours +
     * timezone) and existing confirmed appointments, then derives conflict-free
     * slots. Results are returned as UTC ISO start/end pairs (REQ-WSW-007) and
     * cached for 5 minutes.
     *
     * @param string $serviceSlug      The service to book.
     * @param string $date             The ISO date (YYYY-MM-DD).
     * @param string $administrationId The owning administration (tenant scope).
     *
     * @return array<string,mixed> ['slots' => array, 'etag' => string, 'resourceId' => string]
     *                             or ['error' => string] on lookup failure.
     */
    public function getAvailableSlots(string $serviceSlug, string $date, string $administrationId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'service_unavailable'];
        }

        $service = $this->findOne(
            objectService: $objectService,
            schema: 'Service',
            slug: $serviceSlug,
            administrationId: $administrationId
        );
        if ($service === null || (bool) ($service['isPublic'] ?? false) === false) {
            return ['error' => 'service_not_found'];
        }

        $resourceId = (string) ($service['resourceId'] ?? '');
        if ($resourceId === '') {
            return ['error' => 'resource_not_found'];
        }

        $resource = $this->findOneByField(
            objectService: $objectService,
            schema: 'Resource',
            field: 'resourceId',
            value: $resourceId,
            administrationId: $administrationId
        );
        if ($resource === null) {
            return ['error' => 'resource_not_found'];
        }

        $cache    = $this->getCache();
        $cacheKey = $this->cacheKey(serviceSlug: $serviceSlug, resourceId: $resourceId, date: $date);
        if ($cache !== null) {
            $cached = $cache->get($cacheKey);
            if ($cached !== null) {
                $decoded = json_decode((string) $cached, true);
                if (is_array($decoded) === true) {
                    return $decoded;
                }
            }
        }

        $timezone = (string) ($resource['timezone'] ?? 'Europe/Amsterdam');
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('Europe/Amsterdam');
        }

        $duration = (int) ($service['duration'] ?? 0);
        $open     = $this->toMinutes(hhmm: (string) ($resource['openingTime'] ?? '09:00'));
        $close    = $this->toMinutes(hhmm: (string) ($resource['closingTime'] ?? '18:00'));

        // Build booked intervals (in resource-local minutes) for the date.
        $booked = $this->bookedIntervalsForDate(
            objectService: $objectService,
            resourceId: $resourceId,
            administrationId: $administrationId,
            date: $date,
            tz: $tz
        );

        // Past filtering: if the requested date is today (resource-local), exclude
        // slots starting before now; otherwise allow the full day. Past dates yield none.
        $nowLocal   = new \DateTimeImmutable('now', $tz);
        $todayLocal = $nowLocal->format('Y-m-d');
        $nowMinutes = -1;
        if ($date === $todayLocal) {
            $nowMinutes = ((int) $nowLocal->format('H') * 60) + (int) $nowLocal->format('i');
        } else if ($date < $todayLocal) {
            return $this->emptyResult(resourceId: $resourceId, cache: $cache, cacheKey: $cacheKey);
        }

        $rawSlots = $this->computeSlots(
            openMinutes: $open,
            closeMinutes: $close,
            duration: $duration,
            booked: $booked,
            nowMinutes: $nowMinutes
        );

        $slots = [];
        foreach ($rawSlots as $slot) {
            $startLocal = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i',
                $date.' '.sprintf('%02d:%02d', intdiv($slot['startMinutes'], 60), ($slot['startMinutes'] % 60)),
                $tz
            );
            if ($startLocal === false) {
                continue;
            }

            $endLocal = $startLocal->modify('+'.$duration.' minutes');
            $slots[]  = [
                'startTime'  => $startLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'endTime'    => $endLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'resourceId' => $resourceId,
            ];
        }//end foreach

        $slotsJson = json_encode($slots);
        if ($slotsJson === false) {
            $slotsJson = '[]';
        }

        $result = [
            'slots'      => $slots,
            'resourceId' => $resourceId,
            'etag'       => md5($slotsJson),
        ];

        if ($cache !== null) {
            $cache->set($cacheKey, json_encode($result), self::CACHE_TTL);
        }

        return $result;

    }//end getAvailableSlots()

    /**
     * Build an empty (cached) result for a resource.
     *
     * @param string      $resourceId The resource id.
     * @param ICache|null $cache      The slot cache, if any.
     * @param string      $cacheKey   The cache key.
     *
     * @return array<string,mixed>
     */
    private function emptyResult(string $resourceId, ?ICache $cache, string $cacheKey): array
    {
        $result = ['slots' => [], 'resourceId' => $resourceId, 'etag' => md5('[]')];
        if ($cache !== null) {
            $cache->set($cacheKey, json_encode($result), self::CACHE_TTL);
        }

        return $result;

    }//end emptyResult()

    /**
     * Load confirmed-appointment intervals (resource-local minutes) for a date.
     *
     * @param object        $objectService    OR ObjectService.
     * @param string        $resourceId       The resource.
     * @param string        $administrationId Tenant scope.
     * @param string        $date             ISO date (YYYY-MM-DD).
     * @param \DateTimeZone $tz               Resource-local timezone.
     *
     * @return array<array{int,int}> List of [startMinutes, endMinutes) intervals.
     */
    private function bookedIntervalsForDate(
        object $objectService,
        string $resourceId,
        string $administrationId,
        string $date,
        \DateTimeZone $tz
    ): array {
        try {
            $appointments = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema('Appointment')
                ->findAll(
                    [
                        'filters' => [
                            'resourceId'       => $resourceId,
                            'administrationId' => $administrationId,
                            'status'           => 'confirmed',
                        ],
                        'limit'   => 500,
                    ]
                );
        } catch (\Throwable $e) {
            // Fail-closed: on a lookup error treat the whole day as booked so we
            // never double-book by silently returning an empty conflict set.
            $this->logger->error(
                'SlotService: appointment lookup failed',
                ['exception' => $e->getMessage()]
            );
            return [[0, (24 * 60)]];
        }//end try

        $intervals = [];
        foreach ($appointments as $appointment) {
            if (is_object($appointment) === true && method_exists($appointment, 'jsonSerialize') === true) {
                $appointment = $appointment->jsonSerialize();
            }

            if (is_array($appointment) === false) {
                continue;
            }

            $startUtc = (string) ($appointment['startTime'] ?? '');
            $endUtc   = (string) ($appointment['endTime'] ?? '');
            if ($startUtc === '' || $endUtc === '') {
                continue;
            }

            try {
                $start = (new \DateTimeImmutable($startUtc))->setTimezone($tz);
                $end   = (new \DateTimeImmutable($endUtc))->setTimezone($tz);
            } catch (\Throwable) {
                continue;
            }

            if ($start->format('Y-m-d') !== $date) {
                continue;
            }

            $intervals[] = [
                (((int) $start->format('H')) * 60) + (int) $start->format('i'),
                (((int) $end->format('H')) * 60) + (int) $end->format('i'),
            ];
        }//end foreach

        return $intervals;

    }//end bookedIntervalsForDate()

    /**
     * Find a single object by slug within an administration.
     *
     * @param object $objectService    OR ObjectService.
     * @param string $schema           The schema slug.
     * @param string $slug             The object slug.
     * @param string $administrationId Tenant scope.
     *
     * @return array<string,mixed>|null
     */
    private function findOne(object $objectService, string $schema, string $slug, string $administrationId): ?array
    {
        try {
            $matches = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema($schema)
                ->findAll(
                    [
                        'filters' => ['administrationId' => $administrationId],
                        'limit'   => 200,
                    ]
                );
        } catch (\Throwable $e) {
            $this->logger->error(
                'SlotService: '.$schema.' lookup failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        foreach ($matches as $match) {
            if (is_object($match) === true && method_exists($match, 'jsonSerialize') === true) {
                $match = $match->jsonSerialize();
            }

            if (is_array($match) === false) {
                continue;
            }

            $matchSlug = (string) ($match['@self']['slug'] ?? ($match['slug'] ?? ''));
            if ($matchSlug === $slug) {
                return $match;
            }
        }

        return null;

    }//end findOne()

    /**
     * Find a single object by an arbitrary scalar field within an administration.
     *
     * Used to resolve a canonical Resource by its operator-assigned resourceId
     * (the Service's additive resourceId points at Resource.resourceId, not the
     * OpenRegister slug).
     *
     * @param object $objectService    OR ObjectService.
     * @param string $schema           The schema slug.
     * @param string $field            The field to match on.
     * @param string $value            The expected field value.
     * @param string $administrationId Tenant scope.
     *
     * @return array<string,mixed>|null
     */
    private function findOneByField(
        object $objectService,
        string $schema,
        string $field,
        string $value,
        string $administrationId
    ): ?array {
        try {
            $matches = $objectService
                ->setRegister($this->getRegisterSlug())
                ->setSchema($schema)
                ->findAll(
                    [
                        'filters' => [
                            'administrationId' => $administrationId,
                            $field             => $value,
                        ],
                        'limit'   => 200,
                    ]
                );
        } catch (\Throwable $e) {
            $this->logger->error(
                'SlotService: '.$schema.' lookup failed',
                ['exception' => $e->getMessage()]
            );
            return null;
        }

        foreach ($matches as $match) {
            if (is_object($match) === true && method_exists($match, 'jsonSerialize') === true) {
                $match = $match->jsonSerialize();
            }

            if (is_array($match) === false) {
                continue;
            }

            if ((string) ($match[$field] ?? '') === $value) {
                return $match;
            }
        }

        return null;

    }//end findOneByField()
}//end class
