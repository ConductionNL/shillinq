<?php

/**
 * Contract of ConflictDetectionService
 *
 * The public surface of ConflictDetectionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use InvalidArgumentException;

/**
 * Contract of ConflictDetectionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ConflictDetectionServiceInterface {

	/**
	 * Minimum booking duration in minutes (REQ-007 of bookings-resource-calendar).
	 */
	public const MIN_DURATION_MINUTES = 15;

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
	public function checkConflicts(string $resourceId, string $proposedStart, string $proposedEnd, ?string $excludeBookingId = null): array;

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
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function lockResource(string $resourceId): bool;
}//end interface
