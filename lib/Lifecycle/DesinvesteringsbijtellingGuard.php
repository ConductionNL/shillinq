<?php

/**
 * Desinvesteringsbijtelling + RvO meldingstermijn guard — ADR-031 exception path.
 *
 * Two deadline-critical fiscal computations that the declarative calculations
 * engine cannot express:
 *  - The RvO meldingstermijn deadline = opdrachtverleningDatum + 3 months
 *    (REQ-INV-007), and whether a melding may still be marked definitief.
 *  - The desinvesteringsbijtelling on early disposal within 5 jaar na aanvang
 *    kalenderjaar van investering (REQ-INV-010, art. 3.47 Wet IB 2001):
 *    bijtelling = oorspronkelijk-aftrek-percentage x min(opbrengst,
 *    aanschafwaarde), capped at the original aftrek.
 *
 * Exception documented in
 * openspec/changes/bookkeeping-investeringsaftrek/design.md §D4/D7.
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
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * ADR-031 guard for RvO deadline computation and desinvesteringsbijtelling.
 *
 * All monetary amounts are integer EUR cents. Pure logic, no persistence.
 *
 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
 */
class DesinvesteringsbijtellingGuard {
	/**
	 * GL account for desinvesteringsbijtelling postings (REQ-INV-010).
	 *
	 * @var string
	 */
	public const GL_ACCOUNT_DESINVESTERING = '8120';

	/**
	 * Disposal-watch window length in years (art. 3.47 Wet IB 2001).
	 *
	 * @var int
	 */
	public const DISPOSAL_WATCH_YEARS = 5;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for computation diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the RvO meldingstermijn deadline (REQ-INV-007).
	 *
	 * Deadline = opdrachtverleningDatum + 3 calendar months. The order date is
	 * authoritative — NOT the invoice or delivery date. Malformed input yields
	 * null so callers fail closed rather than computing a bogus deadline.
	 *
	 * @param string $assignmentDate Order date as YYYY-MM-DD.
	 *
	 * @return string|null Deadline as YYYY-MM-DD, or null if the input is malformed.
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function computeMeldingDeadline(string $assignmentDate): ?string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $assignmentDate) !== 1) {
			return null;
		}

		$base = new DateTimeImmutable($assignmentDate . ' 00:00:00', new DateTimeZone('UTC'));

		return $base->add(new DateInterval('P3M'))->format('Y-m-d');
	}//end computeMeldingDeadline()

	/**
	 * Whether a melding may still be marked definitief on a given date (REQ-INV-007).
	 *
	 * Once the deadline has passed the aftrek is irrevocably forfeited; the
	 * system MUST NOT silently proceed.
	 *
	 * @param string $assignmentDate Order date as YYYY-MM-DD.
	 * @param string $onDate The date the melding would be marked, YYYY-MM-DD.
	 *
	 * @return bool True when on/before the deadline; false when past it or input is malformed.
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function canSubmitDefinitief(string $assignmentDate, string $onDate): bool {
		$deadline = $this->computeMeldingDeadline(assignmentDate: $assignmentDate);
		if ($deadline === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) !== 1) {
			return false;
		}

		return ($onDate <= $deadline);
	}//end canSubmitDefinitief()

	/**
	 * Reminder dates at deadline minus 14 days and minus 3 days (REQ-INV-007).
	 *
	 * @param string $assignmentDate Order date as YYYY-MM-DD.
	 *
	 * @return array{0: string, 1: string}|null [reminder-14d, reminder-3d], or null if malformed.
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function reminderDates(string $assignmentDate): ?array {
		$deadline = $this->computeMeldingDeadline(assignmentDate: $assignmentDate);
		if ($deadline === null) {
			return null;
		}

		$base = new DateTimeImmutable($deadline . ' 00:00:00', new DateTimeZone('UTC'));

		return [
			$base->sub(new DateInterval('P14D'))->format('Y-m-d'),
			$base->sub(new DateInterval('P3D'))->format('Y-m-d'),
		];

	}//end reminderDates()

	/**
	 * The disposal-watch expiry date (REQ-INV-010, art. 3.47).
	 *
	 * The clock starts 1 januari of the kalenderjaar in which the asset was
	 * acquired ("aanvang kalenderjaar van investering"), NOT the
	 * opdrachtverleningDatum, and runs 5 years.
	 *
	 * @param int $acquisitionYear The kalenderjaar of investment (e.g. 2026).
	 *
	 * @return string The watch-expiry date as YYYY-MM-DD (1 jan of acquisitionYear + 5).
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function disposalWatchExpiry(int $acquisitionYear): string {
		return sprintf('%04d-01-01', ($acquisitionYear + self::DISPOSAL_WATCH_YEARS));
	}//end disposalWatchExpiry()

	/**
	 * Whether a disposal triggers desinvesteringsbijtelling (REQ-INV-010).
	 *
	 * Triggered when the disposal occurs strictly before the watch expiry date.
	 *
	 * @param int $acquisitionYear The kalenderjaar of investment.
	 * @param string $disposalDate Disposal date as YYYY-MM-DD.
	 *
	 * @return bool True when the disposal falls within the 5-year window.
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function isWithinDisposalWindow(int $acquisitionYear, string $disposalDate): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $disposalDate) !== 1) {
			return false;
		}

		return ($disposalDate < $this->disposalWatchExpiry(acquisitionYear: $acquisitionYear));
	}//end isWithinDisposalWindow()

	/**
	 * Compute the desinvesteringsbijtelling on early disposal (REQ-INV-010).
	 *
	 * Bijtelling = aftrekPercentage% x min(opbrengst, aanschafwaarde),
	 * capped at the original aftrek so er nooit meer wordt teruggepakt dan
	 * oorspronkelijk is afgetrokken.
	 *
	 * @param float $deductionPercentage Original aftrek percentage (e.g. 40 for EIA).
	 * @param int $revenue Disposal proceeds in EUR cents.
	 * @param int $acquisitionValue Original acquisition value in EUR cents.
	 * @param int $origineleDeduction Original aftrek amount in EUR cents (cap).
	 *
	 * @return int Desinvesteringsbijtelling in EUR cents (never negative, never above the cap).
	 *
	 * @spec openspec/specs/bookkeeping-investeringsaftrek/spec.md
	 */
	public function computeBijtelling(
		float $deductionPercentage,
		int $revenue,
		int $acquisitionValue,
		int $origineleDeduction,
	): int {
		$basis = min(max(0, $revenue), max(0, $acquisitionValue));
		$benefitInKind = (int)round(($deductionPercentage / 100.0) * $basis);

		$capped = min($benefitInKind, max(0, $origineleDeduction));

		$this->logger->debug(
			'DesinvesteringsbijtellingGuard: computeBijtelling',
			[
				'percentage' => $deductionPercentage,
				'basis' => $basis,
				'raw' => $benefitInKind,
				'capped' => $capped,
			]
		);

		return max(0, $capped);
	}//end computeBijtelling()
}//end class
