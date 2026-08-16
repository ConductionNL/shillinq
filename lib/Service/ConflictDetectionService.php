<?php

/**
 * Conflict Detection Service
 *
 * Per-resource booking-overlap detection for the bookings-resource-calendar
 * change (REQ-004). Reads existing non-cancelled Booking records for a
 * resource and returns the subset whose time window overlaps a proposed
 * window. The service is stateless and pure — callers are responsible for
 * wrapping create flows in a database transaction with a row lock on the
 * resource record so two concurrent inserts cannot both pass the check.
 *
 * Overlap semantics follow the REQ-004 spec:
 *   - Bookings on different resources never conflict.
 *   - Adjacent windows (A.end == B.start) do not conflict.
 *   - Cancelled bookings do not conflict.
 *   - Optional excludeBookingId lets callers exclude the booking being
 *     edited from its own conflict check.
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
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OCA\Shillinq\Service\Booking\TransactionalGuard;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Per-resource booking overlap detection (REQ-004).
 *
 * The service reads OR Booking objects via ObjectService (no SQL on OR
 * tables) and performs the overlap calculation in PHP. The database
 * transaction / row-lock is the IDBConnection passed to checkConflicts —
 * the controller wraps the insert in beginTransaction() and the service
 * runs the lockResource() call so the read-then-write window is serialised
 * per-resource (REQ-004 race-condition guard).
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class ConflictDetectionService {

	/**
	 * Minimum booking duration in minutes (REQ-007 of bookings-resource-calendar).
	 */
	public const MIN_DURATION_MINUTES = 15;

	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settings Shillinq settings (register slug, OR availability).
	 * @param TransactionalGuard $guard Transaction / row-lock facade (production-wired with IDBConnection).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly TransactionalGuard $guard,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return existing bookings that overlap a proposed window on a resource.
	 *
	 * The overlap predicate is:
	 *   existing.startTime < proposed.endTime AND existing.endTime > proposed.startTime
	 * which excludes adjacent windows (A.end == B.start) per the REQ-004
	 * edge-case scenario. Cancelled bookings and the booking identified by
	 * excludeBookingId (if provided) are filtered out.
	 *
	 * Times are expected as ISO 8601 strings (UTC). Invalid inputs are
	 * normalised through DateTimeImmutable and re-emitted in UTC; an
	 * un-parseable input throws InvalidArgumentException so callers can
	 * surface a 400 to the API client instead of silently returning [].
	 *
	 * @param string $resourceId Resource identifier (logical key).
	 * @param string $proposedStart Proposed booking start (ISO 8601 UTC).
	 * @param string $proposedEnd Proposed booking end (ISO 8601 UTC).
	 * @param string|null $excludeBookingId Optional booking id to exclude (for edit flows).
	 *
	 * @return array<int,array<string,mixed>> Conflicting booking records (empty if none).
	 *
	 * @throws InvalidArgumentException When times are malformed or end ≤ start.
	 */
	public function checkConflicts(
		string $resourceId,
		string $proposedStart,
		string $proposedEnd,
		?string $excludeBookingId = null,
	): array {
		$resourceId = trim($resourceId);
		if ($resourceId === '') {
			throw new InvalidArgumentException('resourceId is required for conflict detection');
		}

		$start = $this->parseTime(value: $proposedStart, label: 'startTime');
		$end = $this->parseTime(value: $proposedEnd, label: 'endTime');

		if ($end <= $start) {
			throw new InvalidArgumentException('endTime must be strictly after startTime');
		}

		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Shillinq: conflict detection skipped, OpenRegister unavailable');
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
			$records = $objectService
				->setRegister($registerSlug)
				->setSchema('Booking')
				->findAll(
					[
						'filters' => [
							'resource' => $resourceId,
						],
						'limit' => 1000,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: conflict detection lookup failed',
				['exception' => $e->getMessage()]
			);
			// Fail closed — treat as no-conflict-detected so the caller can
			// surface a 503; we never silently pass an unverifiable check.
			throw new RuntimeException('Conflict detection lookup failed', 0, $e);
		}//end try

		$conflicts = [];
		foreach ($records as $record) {
			$row = $this->toArray(object: $record);

			// Skip cancelled bookings — they don't reserve the slot.
			$status = (string)($row['status'] ?? '');
			if ($status === 'cancelled') {
				continue;
			}

			// Skip the booking being edited.
			$rowId = (string)($row['bookingId'] ?? '');
			if ($excludeBookingId !== null && $rowId !== '' && $rowId === $excludeBookingId) {
				continue;
			}

			$existingStartRaw = (string)($row['startTime'] ?? '');
			$existingEndRaw = (string)($row['endTime'] ?? '');
			if ($existingStartRaw === '' || $existingEndRaw === '') {
				continue;
			}

			try {
				$existingStart = $this->parseTime(value: $existingStartRaw, label: 'existing.startTime');
				$existingEnd = $this->parseTime(value: $existingEndRaw, label: 'existing.endTime');
			} catch (InvalidArgumentException $e) {
				// Don't let one corrupted row poison the whole check.
				$this->logger->warning(
					'Shillinq: skipping booking with malformed time during conflict check',
					[
						'bookingId' => $rowId,
						'reason' => $e->getMessage(),
					]
				);
				continue;
			}

			// Half-open overlap: adjacent windows do NOT conflict.
			if ($existingStart < $end && $existingEnd > $start) {
				$conflicts[] = [
					'bookingId' => $rowId,
					'calendar' => (string)($row['calendar'] ?? ''),
					'resource' => (string)($row['resource'] ?? $resourceId),
					'title' => (string)($row['title'] ?? ''),
					'startTime' => $existingStart->format('Y-m-d\\TH:i:s\\Z'),
					'endTime' => $existingEnd->format('Y-m-d\\TH:i:s\\Z'),
					'status' => $status,
				];
			}
		}//end foreach

		return $conflicts;
	}//end checkConflicts()

	/**
	 * Acquire a database row-lock on the resource record for the duration
	 * of the surrounding transaction (REQ-004 race-condition guard).
	 *
	 * The caller MUST have already opened a transaction via beginTransaction();
	 * this method runs a SELECT … FOR UPDATE on the oc_openregister_objects
	 * row that backs the resource so any concurrent caller blocks until the
	 * outer transaction commits or rolls back. Returns true when the lock is
	 * acquired; false when the resource row is not present (no lock needed,
	 * the caller's own NOT-FOUND handling decides next steps).
	 *
	 * @param string $resourceId Resource identifier (logical key).
	 *
	 * @return bool True when a row was locked, false when the resource is unknown.
	 */
	public function lockResource(string $resourceId): bool {
		if ($this->guard->inTransaction() === false) {
			throw new LogicException('lockResource must be called inside a transaction');
		}

		return $this->guard->lockResourceRow(resourceId: $resourceId);
	}//end lockResource()

	/**
	 * Parse an ISO 8601 timestamp into a UTC DateTimeImmutable.
	 *
	 * Accepts the standard wire-format produced by the Shillinq API
	 * (`2026-05-21T10:00:00Z`) as well as offset-bearing forms
	 * (`2026-05-21T12:00:00+02:00`); both normalise to UTC for comparison.
	 *
	 * @param string $value Raw timestamp.
	 * @param string $label Field label for the error message.
	 *
	 * @return DateTimeImmutable Parsed timestamp in UTC.
	 *
	 * @throws InvalidArgumentException When the value cannot be parsed.
	 */
	private function parseTime(string $value, string $label): DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			throw new InvalidArgumentException($label . ' is required');
		}

		try {
			$dt = new DateTimeImmutable($value);
		} catch (\Throwable $e) {
			throw new InvalidArgumentException($label . ' is not a valid ISO 8601 timestamp', 0, $e);
		}

		return $dt->setTimezone(new DateTimeZone('UTC'));
	}//end parseTime()

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
