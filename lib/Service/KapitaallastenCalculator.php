<?php

/**
 * Kapitaallasten (depreciation) calculator.
 *
 * ADR-031 exception-path calculator for the Investering kapitaallastenSchedule
 * (REQ-005). Produces a per-year straight-line depreciation schedule from the
 * gross investment, the eersteAfschrijvingsjaar and the afschrijvingstermijn
 * per the BBV notitie Materiële vaste activa (lineaire afschrijving). The last
 * year absorbs any cent-rounding remainder so the schedule sums exactly to the
 * gross amount. No persistence, no I/O.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-25
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator for the straight-line kapitaallasten schedule.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-25
 */
class KapitaallastenCalculator {
	/**
	 * Compute a per-year straight-line depreciation schedule.
	 *
	 * Each year receives floor(bruto / termijn) cents; the final year absorbs
	 * the rounding remainder so Σ(schedule) === bruto exactly (integer-cent
	 * arithmetic avoids IEEE-754 drift).
	 *
	 * @param float $gross The gross investment amount.
	 * @param int $firstDepreciationYear The first depreciation year.
	 * @param int $depreciationTerm The depreciation period in years (> 0).
	 *
	 * @return array<int,float> A {year: amount} schedule keyed by year.
	 *                          Keyed by INT, not string: PHP coerces a numeric
	 *                          string array key back to an integer, so a
	 *                          `(string)` cast on the year cannot survive being
	 *                          used as a key. Callers indexing with `'2027'`
	 *                          still work — the lookup is coerced the same way.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-25
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) BBV domain field names (eersteAfschrijvingsjaar).
	 */
	public function schedule(float $gross, int $firstDepreciationYear, int $depreciationTerm): array {
		if ($depreciationTerm < 1) {
			return [];
		}

		$grossCents = (int)round($gross * 100);
		$perYearCents = intdiv($grossCents, $depreciationTerm);
		$remainder = ($grossCents - ($perYearCents * $depreciationTerm));

		$schedule = [];
		for ($i = 0; $i < $depreciationTerm; $i++) {
			$year = ($firstDepreciationYear + $i);
			$cents = $perYearCents;
			if ($i === ($depreciationTerm - 1)) {
				// Final year absorbs the rounding remainder.
				$cents += $remainder;
			}

			$schedule[$year] = (float)($cents / 100);
		}

		return $schedule;
	}//end schedule()
}//end class
