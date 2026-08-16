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
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
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
		$provisional = (array)($input['voorlopigRecords'] ?? []);
		if ($provisional === []) {
			throw new InvalidArgumentException('Cannot lock without any voorlopig IKP records');
		}

		$signedBy = (string)($input['signedBy'] ?? '');
		if (trim($signedBy) === '') {
			throw new InvalidArgumentException('Year-end lock requires an accountant user-id (signedBy)');
		}

		$fiscalYear = (string)($input['fiscalYear'] ?? '');
		if (preg_match('/^[0-9]{4}$/', $fiscalYear) !== 1) {
			throw new InvalidArgumentException('Invalid fiscalYear (expected YYYY): ' . $fiscalYear);
		}

		$payrollCostSum = 0.0;
		$materialenSum = 0.0;
		$depreciationsSum = 0.0;
		$vermogensSum = 0.0;
		$profitMarkupSum = 0.0;
		$overheadBuckets = [];
		$totaleCostSum = 0.0;

		foreach ($provisional as $record) {
			if (is_array($record) === false) {
				continue;
			}

			$componenten = (array)($record['componenten'] ?? []);

			$payrollCostSum += (float)($componenten['directPayrollCost'] ?? 0);
			$materialenSum += (float)($componenten['directMaterials'] ?? 0);
			$depreciationsSum += (float)($componenten['directDepreciations'] ?? 0);
			$vermogensSum += (float)($componenten['capitalCost'] ?? 0);
			$profitMarkupSum += (float)($componenten['profitMarkup'] ?? 0);

			$overheadInRecord = (array)($componenten['indirecteOverhead'] ?? []);
			foreach ($overheadInRecord as $bucket => $amount) {
				$overheadBuckets[(string)$bucket] = ($overheadBuckets[(string)$bucket] ?? 0.0) + (float)$amount;
			}

			$totaleCostSum += (float)($record['totalCost'] ?? 0);
		}

		$soldUnits = (float)($input['soldUnits'] ?? 0);
		$costPricePerUnit = null;
		if ($soldUnits > 0.0) {
			$costPricePerUnit = round(($totaleCostSum / $soldUnits), 4);
		}

		$appliedRate = null;
		if (isset($input['appliedRate']) === true) {
			$appliedRate = (float)$input['appliedRate'];
		}

		$marge = null;
		$margePercentage = null;
		if ($appliedRate !== null && $costPricePerUnit !== null) {
			$marge = round(($appliedRate - $costPricePerUnit), 4);
			$base = 1.0;
			if ($costPricePerUnit > 0.0) {
				$base = $costPricePerUnit;
			}

			$margePercentage = round((($marge / $base) * 100), 4);
		}

		$compliant = false;
		if ($appliedRate !== null && $costPricePerUnit !== null) {
			$compliant = ($appliedRate >= $costPricePerUnit);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		$verkochteUnitsOut = null;
		if ($soldUnits > 0.0) {
			$verkochteUnitsOut = $soldUnits;
		}

		return [
			'commercialActivityId' => (string)$input['commercialActivityId'],
			'period' => $fiscalYear . '-YTD',
			'calculatedOn' => $now->format(DateTimeImmutable::ATOM),
			'status' => 'final',
			'componenten' => [
				'directPayrollCost' => round($payrollCostSum, 2),
				'directMaterials' => round($materialenSum, 2),
				'directDepreciations' => round($depreciationsSum, 2),
				'indirecteOverhead' => array_map(fn (float $v): float => round($v, 2), $overheadBuckets),
				'capitalCost' => round($vermogensSum, 2),
				'profitMarkup' => round($profitMarkupSum, 2),
			],
			'totalCost' => round($totaleCostSum, 2),
			'soldUnits' => $verkochteUnitsOut,
			'unitLabel' => ($input['unitLabel'] ?? null),
			'costPricePerUnit' => $costPricePerUnit,
			'appliedRate' => $appliedRate,
			'marge' => $marge,
			'margePercentage' => $margePercentage,
			'compliant' => $compliant,
			'finalSignedBy' => $signedBy,
			'finalSignedAt' => $now->format(DateTimeImmutable::ATOM),
			'administrationId' => (string)$input['administrationId'],
		];

	}//end lock()

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
