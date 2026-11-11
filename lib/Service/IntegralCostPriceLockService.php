<?php

/**
 * IntegralCostPrice Year-End Lock Service (REQ-WMO-002 §year-end lock)
 *
 * Pure-logic service that takes the monthly voorlopig IKP records for a fiscal
 * year and aggregates them into a single definitief IKP record signed by the
 * accountant on 31 March of the following year. Component sums are
 * re-aggregated server-side (not trusting the voorlopig totals) so the lock is
 * an authoritative re-statement.
 *
 * Side-effect-free; the caller persists the result via OR ObjectService and
 * marks the source voorlopig records as superseded.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Side-effect-free year-end IKP definitief lock (REQ-WMO-002 §year-end lock).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-8
 */
class IntegralCostPriceLockService {
	/**
	 * Aggregate monthly voorlopig records into a single definitief record (REQ-WMO-002).
	 *
	 * @param array<string,mixed> $input Lock inputs (commercialActivityId,
	 *                                   fiscalYear, voorlopigRecords, signedBy,
	 *                                   signatureFingerprint, administrationId,
	 *                                   gehanteerdTarief, verkochteEenheden,
	 *                                   eenheidLabel).
	 *
	 * @return array<string,mixed> Definitief IKP record.
	 *
	 * @throws InvalidArgumentException When inputs are invalid.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function lock(array $input): array {
		$this->assertLockable(input: $input);

		$totals = new ProvisionalCostTotals();
		foreach ((array)($input['voorlopigRecords'] ?? []) as $record) {
			if (is_array($record) === true) {
				$totals->add(record: $record);
			}
		}

		return $this->finalRecord(input: $input, totals: $totals);

	}//end lock()

	/**
	 * Refuse a lock without provisional records, without a signing
	 * accountant or with a malformed fiscal year.
	 *
	 * @param array<string,mixed> $input Lock inputs.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When inputs are invalid.
	 */
	private function assertLockable(array $input): void {
		if ((array)($input['voorlopigRecords'] ?? []) === []) {
			throw new InvalidArgumentException('Cannot lock without any voorlopig IKP records');
		}

		if (trim((string)($input['signedBy'] ?? '')) === '') {
			throw new InvalidArgumentException('Year-end lock requires an accountant user-id (signedBy)');
		}

		$fiscalYear = (string)($input['fiscalYear'] ?? '');
		if (preg_match('/^[0-9]{4}$/', $fiscalYear) !== 1) {
			throw new InvalidArgumentException('Invalid fiscalYear (expected YYYY): ' . $fiscalYear);
		}
	}//end assertLockable()

	/**
	 * The definitief record: identity, totals, price fields and signature.
	 *
	 * @param array<string,mixed>   $input  Lock inputs.
	 * @param ProvisionalCostTotals $totals The aggregated provisional records.
	 *
	 * @return array<string,mixed> Definitief IKP record.
	 */
	private function finalRecord(array $input, ProvisionalCostTotals $totals): array {
		$pricing = new UnitCostPricing(
			input: $input,
			costPricePerUnit: $totals->costPricePerUnit(soldUnits: (float)($input['soldUnits'] ?? 0))
		);
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);

		return [
			'commercialActivityId' => (string)$input['commercialActivityId'],
			'period' => (string)($input['fiscalYear'] ?? '') . '-YTD',
			'calculatedOn' => $now,
			'status' => 'final',
			'componenten' => $totals->componenten(),
			'totalCost' => round($totals->totalCost, 2),
		] + $pricing->fields() + [
			'compliant' => $pricing->coversCostPrice(),
			'finalSignedBy' => (string)($input['signedBy'] ?? ''),
			'finalSignedAt' => $now,
			'administrationId' => (string)$input['administrationId'],
		];
	}//end finalRecord()

	/**
	 * Determine whether today is past the year-end lock trigger date (31 March of FY+1).
	 *
	 * @param string $fiscalYear Fiscal year (YYYY).
	 * @param string $today Today's ISO date.
	 *
	 * @return bool True when lock should run.
	 */
	public function shouldLock(string $fiscalYear, string $today): bool {
		if (preg_match('/^[0-9]{4}$/', $fiscalYear) !== 1) {
			return false;
		}

		try {
			$lockDate = new DateTimeImmutable(((int)$fiscalYear + 1) . '-03-31');
			$now = new DateTimeImmutable($today);
		} catch (\Throwable) {
			return false;
		}

		return $now >= $lockDate;
	}//end shouldLock()
}//end class
