<?php

/**
 * Retention Guard
 *
 * Lifecycle preconditions for DocumentRetention state transitions referenced
 * from lib/Settings/shillinq_register.json. Thin PHP seam per ADR-031
 * §"PHP guards remain a legitimate seam" — no domain logic, only the
 * cross-period / legal-hold preconditions that the declarative lifecycle
 * engine cannot express until OR's archival-destruction extension is stable.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-archiefwet-retention/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Guards DocumentRetention lifecycle transitions for Archiefwet compliance.
 *
 * Methods are referenced by name from the DocumentRetention schema's
 * x-openregister-lifecycle `requires:` clauses. Each returns true when the
 * precondition is satisfied (transition permitted), false otherwise. The guard
 * is fail-closed: any unexpected condition denies the transition so a document
 * is never disposed of prematurely (REQ-RET-008).
 *
 * @spec openspec/changes/bookkeeping-archiefwet-retention/specs.md
 */
class RetentionGuard {
	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Precondition for the `initiateReview` transition (active → under-review):
	 * the document's review-due date must have been reached AND no legal hold
	 * may be active (REQ-RET-003 / REQ-RET-004 / REQ-RET-008).
	 *
	 * A document whose review is not yet due cannot be advanced toward disposal,
	 * enforcing the Archiefwet "review before disposal" rule. A legal hold
	 * suspends the lifecycle entirely (REQ-RET-005).
	 *
	 * @param array<string, mixed> $doc DocumentRetention object array (loaded by OR)
	 *
	 * @return bool True when review may be initiated.
	 *
	 * @spec openspec/changes/bookkeeping-archiefwet-retention/specs.md (REQ-RET-004)
	 */
	public function requiresReview(array $doc): bool {
		if (($doc['legalHold'] ?? false) === true) {
			// A legal hold suspends the lifecycle — review cannot proceed.
			return false;
		}

		$reviewDueDate = ($doc['reviewDueDate'] ?? null);
		if (is_string($reviewDueDate) === false || $reviewDueDate === '') {
			// No computed review date yet — fail closed, nothing to review.
			$this->logger->debug(
				'RetentionGuard: reviewDueDate not set — review not yet due',
				['documentId' => ($doc['documentId'] ?? 'unknown')]
			);
			return false;
		}

		return $this->dateIsTodayOrPast(isoDate: $reviewDueDate);
	}//end requiresReview()

	/**
	 * Precondition for the `scheduleDisposal` and `executeDisposal` transitions:
	 * the retention end date must have passed AND no legal hold may be active
	 * (REQ-RET-005 / REQ-RET-008).
	 *
	 * Disposal is suspended while a document is on legal hold regardless of the
	 * retention end date, and is never permitted before the retention period
	 * has fully elapsed.
	 *
	 * @param array<string, mixed> $doc DocumentRetention object array (loaded by OR)
	 *
	 * @return bool True when disposal may proceed.
	 *
	 * @spec openspec/changes/bookkeeping-archiefwet-retention/specs.md (REQ-RET-008)
	 */
	public function allowsDisposal(array $doc): bool {
		if (($doc['legalHold'] ?? false) === true) {
			// Legal hold overrides the schedule — disposal is suspended.
			return false;
		}

		$retentionEndDate = ($doc['retentionEndDate'] ?? null);
		if (is_string($retentionEndDate) === false || $retentionEndDate === '') {
			// No computed end date — fail closed, never dispose without a schedule.
			$this->logger->debug(
				'RetentionGuard: retentionEndDate not set — disposal denied',
				['documentId' => ($doc['documentId'] ?? 'unknown')]
			);
			return false;
		}

		return $this->dateIsTodayOrPast(isoDate: $retentionEndDate);
	}//end allowsDisposal()

	/**
	 * Parse an ISO date (YYYY-MM-DD or date-time) and return whether it is today
	 * or in the past, comparing at day granularity in UTC.
	 *
	 * Fails closed: an unparseable date returns false so no transition advances
	 * on malformed data.
	 *
	 * @param string $isoDate The ISO-8601 date or date-time string.
	 *
	 * @return bool True when the date is today or earlier.
	 */
	private function dateIsTodayOrPast(string $isoDate): bool {
		try {
			$utc = new DateTimeZone('UTC');
			$target = new DateTimeImmutable($isoDate, $utc);
			$today = new DateTimeImmutable('today', $utc);

			return $target->setTime(0, 0, 0) <= $today;
		} catch (Throwable $e) {
			$this->logger->error(
				'RetentionGuard: unparseable date — denying transition (fail-closed)',
				['value' => $isoDate, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end dateIsTodayOrPast()
}//end class
