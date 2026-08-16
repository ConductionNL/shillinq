<?php

/**
 * SEPA Submission-Window Guard
 *
 * ADR-031 exception-path guard enforcing the SDD submission windows when a
 * batch is generated (REQ-SDD-004): D-5 business days for FRST/OOFF on CORE,
 * D-2 business days for RCUR/FNAL on CORE, D-1 business day for any B2B
 * sequence type. A business day is any day except Saturday, Sunday and a
 * Dutch public holiday.
 *
 * ADR-031 exception reason: Dutch business-day arithmetic (holiday calendar,
 * weekend skipping) is not expressible in the declarative lifecycle DSL.
 * When OpenRegister exposes a calendar abstraction, replace this with a
 * declarative precondition and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Business-day submission-window enforcement for SEPA Direct Debit batches.
 *
 * Pure arithmetic over (scheme, sequenceType, requestedCollectionDate,
 * generationDate) so it unit-tests directly without external dependencies.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class SubmissionWindowGuard {

	/**
	 * Required business-day lead per (scheme, sequenceType) per REQ-SDD-004.
	 *
	 * @var array<string,int>
	 */
	private const REQUIRED_LEAD = [
		'CORE:FRST' => 5,
		'CORE:OOFF' => 5,
		'CORE:RCUR' => 2,
		'CORE:FNAL' => 2,
		'B2B:FRST' => 1,
		'B2B:OOFF' => 1,
		'B2B:RCUR' => 1,
		'B2B:FNAL' => 1,
	];

	/**
	 * Dutch (TARGET2-relevant) public holidays as Y-m-d strings, 2025-2027.
	 *
	 * Maintained inline pending an OpenRegister calendar abstraction. Update
	 * cadence: extend each year; Easter-derived dates (Good Friday, Easter
	 * Monday, Ascension, Whit Monday) recomputed from easter_date().
	 *
	 * @var array<int,string>
	 */
	private const FIXED_HOLIDAYS = [
		// 2025.
		'2025-01-01',
		'2025-04-18',
		'2025-04-21',
		'2025-04-27',
		'2025-05-05',
		'2025-05-29',
		'2025-06-09',
		'2025-12-25',
		'2025-12-26',
		// 2026.
		'2026-01-01',
		'2026-04-03',
		'2026-04-06',
		'2026-04-27',
		'2026-05-05',
		'2026-05-14',
		'2026-05-25',
		'2026-12-25',
		'2026-12-26',
		// 2027.
		'2027-01-01',
		'2027-03-26',
		'2027-03-29',
		'2027-04-27',
		'2027-05-05',
		'2027-05-06',
		'2027-05-17',
		'2027-12-25',
		'2027-12-26',
	];

	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * True iff generationDate leaves enough business-day lead before the due date.
	 *
	 * REQ-SDD-004: the number of business days strictly between
	 * generationDate (exclusive) and requestedCollectionDate (exclusive) must
	 * be at least the required lead for the (scheme, sequenceType) pair. Fails
	 * closed on malformed input or an unknown scheme/sequence pair.
	 *
	 * @param string $scheme CORE or B2B.
	 * @param string $sequenceType FRST, RCUR, OOFF or FNAL.
	 * @param string $collectionDate ISO date funds are due (requestedCollectionDate).
	 * @param string $generationDate ISO date the batch is generated (today).
	 *
	 * @return bool True when the batch is within its submission window.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function isWithinWindow(
		string $scheme,
		string $sequenceType,
		string $collectionDate,
		string $generationDate,
	): bool {
		try {
			$key = $scheme . ':' . $sequenceType;
			if (isset(self::REQUIRED_LEAD[$key]) === false) {
				return false;
			}

			$required = self::REQUIRED_LEAD[$key];

			$due = new DateTimeImmutable($collectionDate);
			$gen = new DateTimeImmutable($generationDate);
			if ($gen >= $due) {
				return false;
			}

			$lead = $this->businessDaysBetween(from: $gen, to: $due);
			return $lead >= $required;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SubmissionWindowGuard: window check failed — denying batch (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end isWithinWindow()

	/**
	 * Count business days strictly between two dates (both exclusive).
	 *
	 * Iterates from the day after `from` up to the day before `to`, counting
	 * each weekday that is not a Dutch public holiday.
	 *
	 * @param DateTimeImmutable $from Lower bound (exclusive).
	 * @param DateTimeImmutable $to Upper bound (exclusive).
	 *
	 * @return int The number of business days in the open interval.
	 */
	private function businessDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int {
		$count = 0;
		$cursor = $from->modify('+1 day');
		while ($cursor < $to) {
			if ($this->isBusinessDay(date: $cursor) === true) {
				$count++;
			}

			$cursor = $cursor->modify('+1 day');
		}

		return $count;
	}//end businessDaysBetween()

	/**
	 * True iff the given date is a weekday and not a Dutch public holiday.
	 *
	 * @param DateTimeImmutable $date The date to test.
	 *
	 * @return bool True when the date is a business day.
	 */
	private function isBusinessDay(DateTimeImmutable $date): bool {
		$dow = (int)$date->format('N');
		if ($dow >= 6) {
			return false;
		}

		return in_array($date->format('Y-m-d'), self::FIXED_HOLIDAYS, true) === false;
	}//end isBusinessDay()
}//end class
