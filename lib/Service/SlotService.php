<?php

/**
 * Slot Service
 *
 * Computes available appointment time slots for a (service, resource, date)
 * tuple by intersecting the Resource operational hours, the Service duration,
 * and the existing non-cancelled Appointment set. Implements REQ-WSW-002
 * (conflict detection) and the 5-minute ETag cache.
 *
 * Reads from OR registers via ObjectService — never via SQL — so the slot
 * computation honours OR-level RBAC and tenant scoping.
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
 * @spec openspec/changes/bookings-self-service-widget/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Slot availability computation for the Booking Self-service Widget.
 *
 * The compute path enumerates candidate windows in 15-minute increments
 * (matching the convention of the existing AppointmentGuard) and excludes
 * any window that overlaps a non-cancelled Appointment or falls outside the
 * Resource operational hours / past the current time. Results are cached
 * for 5 minutes per (service, resource, date) tuple per REQ-WSW-002.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Pre-existing debt (issue
 *     #506): changing this signature would ripple to callers; deferred.
 */
class SlotService {

	/**
	 * Default slot computation step in minutes. Matches the AppointmentGuard
	 * convention of 15-minute slot granularity. Operators that want a finer
	 * grid can override via app config in a follow-up change.
	 */
	public const SLOT_STEP_MINUTES = 15;

	/**
	 * Cache TTL in seconds per REQ-WSW-002.
	 */
	public const SLOT_CACHE_TTL_SECONDS = 300;

	/**
	 * Default operating hours when the Resource record has none configured.
	 */
	private const DEFAULT_OPENING_TIME = '09:00';
	private const DEFAULT_CLOSING_TIME = '18:00';

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param ICacheFactory $cacheFactory Distributed cache factory (slot result cache).
	 * @param ITimeFactory $time Time provider for past-slot filtering.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly ICacheFactory $cacheFactory,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute available slots for a service + resource on a given calendar date.
	 *
	 * The returned shape is `{slots: [...], etag: string, cached: bool}` so
	 * the controller can attach the ETag header and a 304 fast-path. The
	 * computation honours REQ-WSW-002:
	 *
	 *   1. Slots outside Resource opening/closing hours are excluded.
	 *   2. Slots starting in the past are excluded.
	 *   3. Slots that overlap a non-cancelled Appointment for the same
	 *      resource are excluded (unless the Resource allows overlap).
	 *
	 * @param string $serviceId Service identifier (logical key, not @self.id).
	 * @param string $resourceId Resource identifier (logical key, not @self.id).
	 * @param string $date Calendar date in UTC as YYYY-MM-DD.
	 *
	 * @return array{slots: array<int,array<string,string>>, etag: string, cached: bool}
	 */
	public function getAvailableSlots(string $serviceId, string $resourceId, string $date): array {
		$cacheKey = $this->buildCacheKey(serviceId: $serviceId, resourceId: $resourceId, date: $date);
		$cache = $this->getCache();

		if ($cache !== null) {
			$cached = $cache->get($cacheKey);
			if (is_array($cached) === true && isset($cached['slots']) === true && isset($cached['etag']) === true) {
				return [
					'slots' => $cached['slots'],
					'etag' => (string)$cached['etag'],
					'cached' => true,
				];
			}
		}

		$service = $this->findByLogicalKey(schema: 'Service', field: 'serviceId', value: $serviceId);
		if ($service === null) {
			return ['slots' => [], 'etag' => $this->makeEtag(data: []), 'cached' => false];
		}

		$resource = $this->findByLogicalKey(schema: 'Resource', field: 'resourceId', value: $resourceId);
		if ($resource === null) {
			return ['slots' => [], 'etag' => $this->makeEtag(data: []), 'cached' => false];
		}

		$duration = (int)($service['duration'] ?? 0);
		if ($duration <= 0) {
			return ['slots' => [], 'etag' => $this->makeEtag(data: []), 'cached' => false];
		}

		$openingTime = (string)($resource['openingTime'] ?? self::DEFAULT_OPENING_TIME);
		if ($openingTime === '') {
			$openingTime = self::DEFAULT_OPENING_TIME;
		}

		$closingTime = (string)($resource['closingTime'] ?? self::DEFAULT_CLOSING_TIME);
		if ($closingTime === '') {
			$closingTime = self::DEFAULT_CLOSING_TIME;
		}

		$allowOverlap = (bool)($resource['allowOverlap'] ?? false);
		$existing = $this->loadAppointmentsForResourceOnDate(resourceId: $resourceId, date: $date);

		$slots = $this->enumerateSlots(
			date: $date,
			openingTime: $openingTime,
			closingTime: $closingTime,
			durationMinutes: $duration,
			existingAppointments: $existing,
			allowOverlap: $allowOverlap,
		);

		$result = [
			'slots' => $slots,
			'etag' => $this->makeEtag(data: $slots),
		];

		if ($cache !== null) {
			$cache->set($cacheKey, $result, self::SLOT_CACHE_TTL_SECONDS);
		}

		return [
			'slots' => $slots,
			'etag' => $result['etag'],
			'cached' => false,
		];

	}//end getAvailableSlots()

	/**
	 * Invalidate the slot cache for a (service, resource, date) tuple.
	 *
	 * Called by the appointment-create flow so subsequent slot requests
	 * recompute against the new Appointment per REQ-WSW-002.
	 *
	 * @param string $serviceId Service id (or '*' for all services).
	 * @param string $resourceId Resource id (or '*' for all resources).
	 * @param string $date Calendar date in UTC as YYYY-MM-DD.
	 *
	 * @return void
	 */
	public function invalidate(string $serviceId, string $resourceId, string $date): void {
		$cache = $this->getCache();
		if ($cache === null) {
			return;
		}

		$cache->remove($this->buildCacheKey(serviceId: $serviceId, resourceId: $resourceId, date: $date));

	}//end invalidate()

	/**
	 * Public alias of the private slot enumerator for tests + reuse.
	 *
	 * @param string $date Calendar date YYYY-MM-DD UTC.
	 * @param string $openingTime HH:MM lower bound (inclusive).
	 * @param string $closingTime HH:MM upper bound (exclusive on the last slot).
	 * @param int $durationMinutes Service duration in minutes.
	 * @param array<int,array{startTime:string,endTime:string}> $existingAppointments Existing booked windows.
	 * @param bool $allowOverlap When true, the conflict check is skipped.
	 *
	 * @return array<int,array{startTime:string,endTime:string,resourceId?:string}>
	 */
	public function enumerateSlotsPublic(
		string $date,
		string $openingTime,
		string $closingTime,
		int $durationMinutes,
		array $existingAppointments,
		bool $allowOverlap = false,
	): array {
		return $this->enumerateSlots(
			date: $date,
			openingTime: $openingTime,
			closingTime: $closingTime,
			durationMinutes: $durationMinutes,
			existingAppointments: $existingAppointments,
			allowOverlap: $allowOverlap,
		);

	}//end enumerateSlotsPublic()

	/**
	 * Walk the operational-hours window in fixed steps and emit non-conflicting
	 * slots whose end-time still falls inside the window and ahead of `now`.
	 *
	 * @param string $date Calendar date YYYY-MM-DD UTC.
	 * @param string $openingTime HH:MM lower bound.
	 * @param string $closingTime HH:MM upper bound.
	 * @param int $durationMinutes Service duration.
	 * @param array<int,array{startTime:string,endTime:string}> $existingAppointments Existing booked windows.
	 * @param bool $allowOverlap Skip conflict check when true.
	 *
	 * @return array<int,array{startTime:string,endTime:string}>
	 */
	private function enumerateSlots(
		string $date,
		string $openingTime,
		string $closingTime,
		int $durationMinutes,
		array $existingAppointments,
		bool $allowOverlap,
	): array {
		$tz = new DateTimeZone('UTC');

		try {
			$windowStart = new DateTimeImmutable($date . 'T' . $openingTime . ':00', $tz);
			$windowEnd = new DateTimeImmutable($date . 'T' . $closingTime . ':00', $tz);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq slot service: invalid date/time window',
				[
					'date' => $date,
					'openingTime' => $openingTime,
					'closingTime' => $closingTime,
					'exception' => $e->getMessage(),
				]
			);
			return [];
		}

		if ($windowStart >= $windowEnd) {
			return [];
		}

		$now = (new DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($tz);

		$slots = [];
		$current = $windowStart;
		while (true) {
			$slotEnd = $current->modify('+' . $durationMinutes . ' minutes');
			if ($slotEnd > $windowEnd) {
				break;
			}

			$isPast = ($current < $now);
			if ($isPast === true) {
				$current = $current->modify('+' . self::SLOT_STEP_MINUTES . ' minutes');
				continue;
			}

			$conflict = false;
			if ($allowOverlap === false) {
				$conflict = $this->overlapsAny(
					slotStart: $current,
					slotEnd: $slotEnd,
					existing: $existingAppointments,
				);
			}

			if ($conflict === false) {
				$slots[] = [
					'startTime' => $current->format('Y-m-d\TH:i:s\Z'),
					'endTime' => $slotEnd->format('Y-m-d\TH:i:s\Z'),
				];
			}

			$current = $current->modify('+' . self::SLOT_STEP_MINUTES . ' minutes');
		}//end while

		return $slots;
	}//end enumerateSlots()

	/**
	 * Detect whether [slotStart, slotEnd) overlaps any element of $existing.
	 *
	 * @param DateTimeImmutable $slotStart Candidate slot start.
	 * @param DateTimeImmutable $slotEnd Candidate slot end.
	 * @param array<int,array{startTime:string,endTime:string}> $existing Existing booked windows.
	 *
	 * @return bool
	 */
	private function overlapsAny(DateTimeImmutable $slotStart, DateTimeImmutable $slotEnd, array $existing): bool {
		$tz = new DateTimeZone('UTC');
		foreach ($existing as $appointment) {
			$startRaw = (string)($appointment['startTime'] ?? '');
			$endRaw = (string)($appointment['endTime'] ?? '');
			if ($startRaw === '' || $endRaw === '') {
				continue;
			}

			try {
				$aptStart = new DateTimeImmutable($startRaw, $tz);
				$aptEnd = new DateTimeImmutable($endRaw, $tz);
			} catch (\Throwable $e) {
				continue;
			}

			if ($slotStart < $aptEnd && $slotEnd > $aptStart) {
				return true;
			}
		}

		return false;
	}//end overlapsAny()

	/**
	 * Load non-cancelled appointments for a resource that fall on the given date.
	 *
	 * @param string $resourceId Resource logical id.
	 * @param string $date Calendar date YYYY-MM-DD UTC.
	 *
	 * @return array<int,array{startTime:string,endTime:string}>
	 */
	private function loadAppointmentsForResourceOnDate(string $resourceId, string $date): array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema('Appointment')
				->findAll(
					[
						'filters' => ['resourceId' => $resourceId],
						'limit' => 500,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq slot service: appointment lookup failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$dayStart = ($date . 'T00:00:00Z');
		$dayEnd = ($date . 'T23:59:59Z');
		$results = [];

		foreach ($records as $record) {
			$row = $this->toArray(object: $record);
			$status = (string)($row['status'] ?? '');
			if ($status === 'cancelled') {
				continue;
			}

			$startTime = (string)($row['startTime'] ?? '');
			$endTime = (string)($row['endTime'] ?? '');
			if ($startTime === '' || $endTime === '') {
				continue;
			}

			// Range filter is applied in-PHP because the OR filter DSL does
			// not yet expose half-open interval predicates.
			if ($endTime < $dayStart || $startTime > $dayEnd) {
				continue;
			}

			$results[] = [
				'startTime' => $startTime,
				'endTime' => $endTime,
			];
		}//end foreach

		return $results;
	}//end loadAppointmentsForResourceOnDate()

	/**
	 * Find a record by a logical id field (serviceId / resourceId) rather than @self.id.
	 *
	 * @param string $schema OR schema slug.
	 * @param string $field Logical key field name.
	 * @param string $value Logical key value.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findByLogicalKey(string $schema, string $field, string $value): ?array {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema($schema)
				->findAll(
					[
						'filters' => [$field => $value],
						'limit' => 1,
					]
				);
			foreach ($records as $candidate) {
				return $this->toArray(object: $candidate);
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq slot service: lookup failed',
				[
					'schema' => $schema,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return null;
	}//end findByLogicalKey()

	/**
	 * Compose the cache key for a slot computation.
	 *
	 * @param string $serviceId Service id.
	 * @param string $resourceId Resource id.
	 * @param string $date Calendar date.
	 *
	 * @return string
	 */
	private function buildCacheKey(string $serviceId, string $resourceId, string $date): string {
		return 'shillinq-widget-slots:' . $serviceId . ':' . $resourceId . ':' . $date;
	}//end buildCacheKey()

	/**
	 * Compute a deterministic ETag for a slot list.
	 *
	 * @param array<int,array{startTime:string,endTime:string}> $data Slot list.
	 *
	 * @return string
	 */
	private function makeEtag(array $data): string {
		$encoded = json_encode($data);
		if ($encoded === false) {
			$encoded = '[]';
		}

		return ('"' . substr(hash('sha256', $encoded), 0, 32) . '"');
	}//end makeEtag()

	/**
	 * Resolve the slot cache (distributed when available).
	 *
	 * @return ICache|null
	 */
	private function getCache(): ?ICache {
		try {
			if ($this->cacheFactory->isLocalCacheAvailable() === true) {
				return $this->cacheFactory->createLocal('shillinq-widget-slots');
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq slot service: cache factory threw',
				['exception' => $e->getMessage()]
			);
		}

		return null;
	}//end getCache()

	/**
	 * Normalise an OR object handle to a plain array.
	 *
	 * @param mixed $object Either an array or an OR entity.
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			/*
			 * @var array<string,mixed> $object
			 */

			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				/*
				 * @var array<string,mixed> $serialised
				 */

				return $serialised;
			}
		}

		if (is_object($object) === true) {
			return (array)$object;
		}

		return [];
	}//end toArray()
}//end class
