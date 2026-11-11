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
	 * @param RegisterLocation $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param TransactionalGuard $guard Transaction / row-lock facade (production-wired with IDBConnection).
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RegisterLocation $registerLocation,
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
	 *
	 * @spec openspec/specs/bookings/spec.md#req-004
	 */
	public function checkConflicts(string $resourceId, string $proposedStart, string $proposedEnd, ?string $excludeBookingId = null): array {
		$resourceId = trim($resourceId);
		if ($resourceId === '') {
			throw new InvalidArgumentException('resourceId is required for conflict detection');
		}

		$window = $this->proposedWindow(start: $proposedStart, end: $proposedEnd);
		if ($this->registerLocation->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Shillinq: conflict detection skipped, OpenRegister unavailable');
			return [];
		}

		$window += ['resourceId' => $resourceId, 'exclude' => $excludeBookingId];
		return $this->conflictsIn(records: $this->loadBookings(resourceId: $resourceId), window: $window);
	}//end checkConflicts()

	/**
	 * Parse the proposed window; its end must be strictly after its start.
	 *
	 * @param string $start Proposed booking start (ISO 8601 UTC).
	 * @param string $end Proposed booking end (ISO 8601 UTC).
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable} The window in UTC.
	 *
	 * @throws InvalidArgumentException When a time is malformed or end ≤ start.
	 */
	private function proposedWindow(string $start, string $end): array {
		$window = [
			'start' => $this->parseTime(value: $start, label: 'startTime'),
			'end' => $this->parseTime(value: $end, label: 'endTime'),
		];
		if ($window['end'] <= $window['start']) {
			throw new InvalidArgumentException('endTime must be strictly after startTime');
		}

		return $window;
	}//end proposedWindow()

	/**
	 * Read every booking of a resource.
	 *
	 * @param string $resourceId Resource identifier (logical key).
	 *
	 * @return mixed The stored booking records.
	 *
	 * @throws RuntimeException When the lookup fails: the check fails closed so
	 *                          the caller can surface a 503, never a silent pass.
	 */
	private function loadBookings(string $resourceId): mixed {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService')
				->setRegister($this->registerLocation->getRegisterSlug())
				->setSchema('Booking')
				->findAll(['filters' => ['resource' => $resourceId], 'limit' => 1000]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq: conflict detection lookup failed',
				['exception' => $e->getMessage()]
			);
			throw new RuntimeException('Conflict detection lookup failed', 0, $e);
		}//end try

	}//end loadBookings()

	/**
	 * The stored bookings that overlap the proposed window.
	 *
	 * @param mixed $records The stored booking records.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, resourceId: string, exclude: string|null} $window The proposed window.
	 *
	 * @return array<int,array<string,mixed>> The conflicting bookings.
	 */
	private function conflictsIn(mixed $records, array $window): array {
		$conflicts = [];
		foreach ($records as $record) {
			$conflict = $this->conflictFor(row: $this->toArray(object: $record), window: $window);
			if ($conflict !== null) {
				$conflicts[] = $conflict;
			}
		}

		return $conflicts;
	}//end conflictsIn()

	/**
	 * The conflict entry for one stored booking, null when it does not
	 * reserve an overlapping slot. Half-open overlap: adjacent windows do
	 * NOT conflict.
	 *
	 * @param array<string,mixed> $row The stored booking.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, resourceId: string, exclude: string|null} $window The proposed window.
	 *
	 * @return array<string,mixed>|null The conflict entry.
	 */
	private function conflictFor(array $row, array $window): ?array {
		if ($this->reservesSlot(row: $row, exclude: $window['exclude']) === false) {
			return null;
		}

		$existing = $this->existingWindow(row: $row);
		if ($existing === null || $this->overlaps(existing: $existing, window: $window) === false) {
			return null;
		}

		return $this->conflictEntry(row: $row, existing: $existing + ['resourceId' => $window['resourceId']]);
	}//end conflictFor()

	/**
	 * Whether a stored window overlaps the proposed one (half-open).
	 *
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $existing The stored booking's window.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable} $window The proposed window.
	 *
	 * @return bool True when they overlap.
	 */
	private function overlaps(array $existing, array $window): bool {
		return $existing['start'] < $window['end'] && $existing['end'] > $window['start'];
	}//end overlaps()

	/**
	 * The conflict entry reported for an overlapping stored booking.
	 *
	 * @param array<string,mixed> $row The stored booking.
	 * @param array{start: DateTimeImmutable, end: DateTimeImmutable, resourceId: string} $existing Its window and the checked resource.
	 *
	 * @return array<string,string> The conflict entry.
	 */
	private function conflictEntry(array $row, array $existing): array {
		return [
			'bookingId' => $this->field(row: $row, key: 'bookingId'),
			'calendar' => $this->field(row: $row, key: 'calendar'),
			'resource' => (string)($row['resource'] ?? $existing['resourceId']),
			'title' => $this->field(row: $row, key: 'title'),
			'startTime' => $existing['start']->format('Y-m-d\\TH:i:s\\Z'),
			'endTime' => $existing['end']->format('Y-m-d\\TH:i:s\\Z'),
			'status' => $this->field(row: $row, key: 'status'),
		];
	}//end conflictEntry()

	/**
	 * Whether a stored booking reserves its slot: cancelled bookings and the
	 * booking being edited do not.
	 *
	 * @param array<string,mixed> $row The stored booking.
	 * @param string|null $exclude The booking id being edited, if any.
	 *
	 * @return bool True when the booking counts for the check.
	 */
	private function reservesSlot(array $row, ?string $exclude): bool {
		if ($this->field(row: $row, key: 'status') === 'cancelled') {
			return false;
		}

		$rowId = $this->field(row: $row, key: 'bookingId');
		return ($rowId === '' || $rowId !== $exclude);
	}//end reservesSlot()

	/**
	 * The stored booking's window in UTC, null when a time is missing or
	 * malformed (one corrupted row must not poison the whole check).
	 *
	 * @param array<string,mixed> $row The stored booking.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null The window.
	 */
	private function existingWindow(array $row): ?array {
		$startRaw = $this->field(row: $row, key: 'startTime');
		$endRaw   = $this->field(row: $row, key: 'endTime');
		if ($startRaw === '' || $endRaw === '') {
			return null;
		}

		return $this->parsedWindow(row: $row);
	}//end existingWindow()

	/**
	 * Parse a stored booking's start and end, logging and skipping a
	 * malformed one.
	 *
	 * @param array<string,mixed> $row The stored booking, both times present.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null The window.
	 */
	private function parsedWindow(array $row): ?array {
		try {
			return [
				'start' => $this->parseTime(value: $this->field(row: $row, key: 'startTime'), label: 'existing.startTime'),
				'end' => $this->parseTime(value: $this->field(row: $row, key: 'endTime'), label: 'existing.endTime'),
			];
		} catch (InvalidArgumentException $e) {
			$this->logger->warning(
				'Shillinq: skipping booking with malformed time during conflict check',
				['bookingId' => $this->field(row: $row, key: 'bookingId'), 'reason' => $e->getMessage()]
			);
			return null;
		}

	}//end parsedWindow()

	/**
	 * A row field as a string ('' when absent).
	 *
	 * @param array<string,mixed> $row The row.
	 * @param string $key The field.
	 *
	 * @return string
	 */
	private function field(array $row, string $key): string {
		return (string)($row[$key] ?? '');
	}//end field()

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
