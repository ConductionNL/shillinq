<?php

/**
 * Sluitend-criterium calculator.
 *
 * ADR-031 exception-path calculator for the BBV sluitend-criterium and
 * toezichtregime. Invoked by ProgrammabegrotingGuard on the in-behandeling →
 * vastgesteld transition (and documented as the `sluitendByBegroting`
 * aggregation on the Programmabegroting schema). The declarative aggregation
 * engine cannot yet express the per-year all-quantifier combined with the
 * cross-schema nominale-ontwikkeling correction and the 4-year toezicht
 * history, so this pure integer-cent calculator provides the fallback per
 * ADR-031 §"PHP guards remain a legitimate seam". No persistence, no I/O.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator for sluitend-flags, per-year sluitend and toezichtregime.
 *
 * All arithmetic is performed in integer euro-cents to avoid IEEE-754 float
 * equality drift (same pattern as BalanceGuard / EmuCalculator). The reëel
 * correction applies the nominaleOntwikkeling percentage to the structural
 * lasten before comparing against the structural baten, per the Commissie BBV
 * notitie "Structureel en reëel evenwicht".
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
 */
class SluitendCalculator {
	/**
	 * Evaluate a single meerjarenraming year.
	 *
	 * Computes saldoStructureel, saldoIncidenteel, saldoReëel (after applying
	 * the nominale-ontwikkeling correction to structural lasten) and the
	 * per-year sluitend flag (struktureel AND reëel must both hold).
	 *
	 * @param array<string,mixed> $year The meerjarenraming year row.
	 * @param float $nominalDevelopment The loon- en prijsindexatie percentage (e.g. 2.0).
	 *
	 * @return array{balanceStructural:float,balanceIncidental:float,saldoReëel:float,structurallyBalanced:bool,sluitendReëel:bool,sluitend:bool}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
	 */
	public function evaluateYear(array $year, float $nominalDevelopment): array {
		$revenueStrucCents = $this->toCents(amount: $year['revenueStructural'] ?? 0);
		$expensesStrucCents = $this->toCents(amount: $year['expensesStructural'] ?? 0);
		$revenueIncCents = $this->toCents(amount: $year['revenueIncidental'] ?? 0);
		$expensesIncCents = $this->toCents(amount: $year['expensesIncidental'] ?? 0);

		$balanceStrucCents = ($revenueStrucCents - $expensesStrucCents);
		$balanceIncCents = ($revenueIncCents - $expensesIncCents);

		// Reëel correction: structural lasten are uplifted by the nominale
		// ontwikkeling (prices rise faster than the nominal budget), so the
		// reëel saldo subtracts that uplift from the nominal saldo.
		$upliftCents = (int)round($expensesStrucCents * ($nominalDevelopment / 100.0));
		$balanceReelCents = ($balanceStrucCents + $balanceIncCents - $upliftCents);

		$structurallyBalanced = ($expensesStrucCents <= $revenueStrucCents);
		$sluitendReeel = ($balanceReelCents >= 0);

		return [
			'balanceStructural' => $this->toEuro(cents: $balanceStrucCents),
			'balanceIncidental' => $this->toEuro(cents: $balanceIncCents),
			'saldoReëel' => $this->toEuro(cents: $balanceReelCents),
			'structurallyBalanced' => $structurallyBalanced,
			'sluitendReëel' => $sluitendReeel,
			'sluitend' => ($structurallyBalanced === true && $sluitendReeel === true),
		];

	}//end evaluateYear()

	/**
	 * Evaluate the overall sluitend-flags across all meerjarenraming years.
	 *
	 * The begroting is sluitendStructureel iff every year is structurally
	 * balanced; sluitendReëel iff every year is reëel balanced (REQ-008,
	 * REQ-011). An empty year set is not sluitend (fail-closed).
	 *
	 * @param array<int,array<string,mixed>> $years The meerjarenraming year rows.
	 * @param float $nominalDevelopment The loon- en prijsindexatie percentage.
	 *
	 * @return array{structurallyBalanced:bool,sluitendReëel:bool}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
	 */
	public function evaluateBegroting(array $years, float $nominalDevelopment): array {
		if ($years === []) {
			return ['structurallyBalanced' => false, 'sluitendReëel' => false];
		}

		$allStructureel = true;
		$allReeel = true;
		foreach ($years as $year) {
			$result = $this->evaluateYear(year: $year, nominalDevelopment: $nominalDevelopment);
			if ($result['structurallyBalanced'] === false) {
				$allStructureel = false;
			}

			if ($result['sluitendReëel'] === false) {
				$allReeel = false;
			}
		}

		return ['structurallyBalanced' => $allStructureel, 'sluitendReëel' => $allReeel];
	}//end evaluateBegroting()

	/**
	 * Determine the toezichtregime from the sluitend-flags and 4-year history.
	 *
	 * Per design D5 / the IPO beoordelingskader:
	 *  - repressief requires both sluitend-flags AND a weerstandsverhouding ≥ 1.0
	 *    AND no sustained tekort in the preceding 4 years;
	 *  - a structural tekort without dekkingsplan, or a weerstandsverhouding < 1.0,
	 *    yields preventief;
	 *  - a negative algemene reserve (vermogenstekort) yields artikel-12.
	 *
	 * @param bool $structurallyBalanced The overall structural flag.
	 * @param bool $sluitendReeel The overall reëel
	 *                            flag.
	 * @param array<int,float> $historyResultaten Resultaat of the preceding years (negative = tekort).
	 * @param float $weerstandsverhouding Algemene reserve / totale lasten ratio (default 1.0).
	 *
	 * @return string One of `repressief`, `preventief`, `artikel-12`.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-20
	 */
	public function determineToezichtRegime(
		bool $structurallyBalanced,
		bool $sluitendReeel,
		array $historyResultaten = [],
		float $weerstandsverhouding = 1.0,
	): string {
		// A negative weerstandsverhouding signals an exhausted algemene reserve
		// (vermogenstekort) — the artikel-12 distressed regime.
		if ($weerstandsverhouding < 0.0) {
			return 'artikel-12';
		}

		// Sustained tekort = every one of (at least four) prior years negative.
		$sustainedTekort = false;
		if (count($historyResultaten) >= 4) {
			$sustainedTekort = true;
			foreach ($historyResultaten as $result) {
				if ($result >= 0.0) {
					$sustainedTekort = false;
					break;
				}
			}
		}

		if ($structurallyBalanced === true
			&& $sluitendReeel === true
			&& $weerstandsverhouding >= 1.0
			&& $sustainedTekort === false
		) {
			return 'repressief';
		}

		return 'preventief';
	}//end determineToezichtRegime()

	/**
	 * Convert a euro amount to integer cents (round-half-up).
	 *
	 * @param mixed $amount The euro amount (numeric or numeric string).
	 *
	 * @return int The amount in integer cents.
	 */
	private function toCents(mixed $amount): int {
		return (int)round(((float)$amount) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a euro float.
	 *
	 * @param int $cents The amount in integer cents.
	 *
	 * @return float The euro amount.
	 */
	private function toEuro(int $cents): float {
		return (float)($cents / 100);
	}//end toEuro()
}//end class
