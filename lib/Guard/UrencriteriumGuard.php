<?php

/**
 * Urencriterium YTD-hours aggregation — ADR-031 exception-path guard.
 *
 * Invoked by the x-openregister-calculations engine (via the `guard:` clause on
 * ZzpDeduction.ytdQualifyingHours) when the declarative cross-period sum of
 * qualifying hours cannot be expressed natively. Single public method, no
 * persistence, pure aggregation per ADR-031 §"PHP guards remain a legitimate
 * seam". Excluded categories do not count toward the 1225-urencriterium per
 * Wet IB 2001 art. 3.6 (REQ-ZZP-002/003).
 *
 * Exception documented in
 * openspec/changes/add-shillinq-bookkeeping-operations/design.md §D5.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-zzp-tax-regime/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Guard;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard computing YTD qualifying hours for the urencriterium.
 *
 * Called when the calculation engine passes a pre-fetched set of UrenRegistratie
 * records for a freelancer and calendar year. Sums hours where the category is
 * not "excluded" (sick / parental-leave / vacation / non-billable-admin do not
 * qualify per Wet IB 2001 art. 3.6). Returns the qualifying-hours total.
 *
 * @spec openspec/specs/bookkeeping-zzp-tax-regime/spec.md
 */
class UrencriteriumGuard {
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
	 * Compute YTD qualifying hours for a freelancer in a calendar year.
	 *
	 * @param array<int,array<string,mixed>> $hourRecords Pre-fetched UrenRegistratie records.
	 * @param string $personId The freelancer whose hours are summed.
	 * @param int $year The calendar year to bound the sum.
	 *
	 * @return float Sum of qualifying hours (category != 'excluded') for personId in year.
	 *
	 * @spec openspec/specs/bookkeeping-zzp-tax-regime/spec.md
	 */
	public function currentYtdHours(array $hourRecords, string $personId, int $year): float {
		$this->logger->debug(
			'UrencriteriumGuard: currentYtdHours',
			['personId' => $personId, 'year' => $year, 'records' => count($hourRecords)]
		);

		$total = 0.0;
		foreach ($hourRecords as $record) {
			if (($record['personId'] ?? null) !== $personId) {
				continue;
			}

			$workDate = (string)($record['workDate'] ?? '');
			if (substr($workDate, 0, 4) !== (string)$year) {
				continue;
			}

			if (($record['category'] ?? '') === 'excluded') {
				continue;
			}

			$total += (float)($record['hours'] ?? 0);
		}//end foreach

		return $total;
	}//end currentYtdHours()
}//end class
