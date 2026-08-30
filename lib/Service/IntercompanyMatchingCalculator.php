<?php

/**
 * Intercompany Matching Calculator
 *
 * Pure-logic helper for the intercompany elimination engine: side aggregation,
 * mismatch computation, match-status determination, tolerance combination, FX
 * conversion, and balanced elimination-line generation. Contains no I/O — every
 * method is deterministic and unit-testable without a live Nextcloud / OpenRegister
 * instance. The orchestration service (IntercompanyMatchingService) supplies data
 * read from the real OpenRegister ObjectService API and persists the results.
 *
 * All monetary arithmetic uses integer cents to avoid IEEE-754 float equality issues.
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
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure arithmetic + classification helper for intercompany matching.
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
 */
class IntercompanyMatchingCalculator {
	/**
	 * Convert a major-unit amount to integer cents.
	 *
	 * @param float $amount The amount in major units (e.g. EUR).
	 *
	 * @return int The amount in integer cents.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function toCents(float $amount): int {
		return (int)round($amount * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a major-unit amount.
	 *
	 * @param int $cents The amount in integer cents.
	 *
	 * @return float The amount in major units, rounded to 2 decimals.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function fromCents(int $cents): float {
		return round(($cents / 100), 2);
	}//end fromCents()

	/**
	 * Sum one side of a match from its transaction rows (REQ-ICE-003).
	 *
	 * The side amount is the net of debit minus credit across the transactions,
	 * computed in integer cents. Optional FX conversion is applied per transaction
	 * when a non-EUR currency carries a rate in the $rates map (REQ-ICE-009).
	 *
	 * @param array<int,array<string,mixed>> $transactions Transaction rows for the side.
	 * @param array<string,float> $rates currency => rate-to-reporting-currency.
	 *
	 * @return int The net side amount in integer cents (reporting currency).
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function sumSideCents(array $transactions, array $rates = []): int {
		$cents = 0;
		foreach ($transactions as $transaction) {
			$currency = (string)($transaction['currency'] ?? 'EUR');
			$rate = (float)($rates[$currency] ?? 1.0);
			$debit = (float)($transaction['debitAmount'] ?? 0.0) * $rate;
			$credit = (float)($transaction['creditAmount'] ?? 0.0) * $rate;
			$cents += ($this->toCents(amount: $debit) - $this->toCents(amount: $credit));
		}

		return $cents;
	}//end sumSideCents()

	/**
	 * Compute the mismatch in cents between two side totals.
	 *
	 * @param int $totalACents Side A net total in cents.
	 * @param int $totalBCents Side B net total in cents.
	 *
	 * @return int The mismatch (A - B) in cents.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function mismatchCents(int $totalACents, int $totalBCents): int {
		return ($totalACents - $totalBCents);
	}//end mismatchCents()

	/**
	 * Compute the relative mismatch as a percentage of the larger absolute side.
	 *
	 * @param int $totalACents Side A net total in cents.
	 * @param int $totalBCents Side B net total in cents.
	 *
	 * @return float The relative mismatch in percent (0 when both sides are zero).
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function mismatchPercentage(int $totalACents, int $totalBCents): float {
		$larger = max(abs($totalACents), abs($totalBCents));
		if ($larger === 0) {
			return 0.0;
		}

		$delta = abs($this->mismatchCents(totalACents: $totalACents, totalBCents: $totalBCents));
		return round((($delta / $larger) * 100), 4);
	}//end mismatchPercentage()

	/**
	 * Determine whether a mismatch falls within the supplied tolerance (REQ-ICE-004).
	 *
	 * @param int $mismatchCents The mismatch in cents (sign-insensitive).
	 * @param float $mismatchPercentage The relative mismatch in percent.
	 * @param float $toleranceAbsolute Absolute threshold in major units.
	 * @param float $toleranceRelative Relative threshold in percent.
	 * @param string $method max-of-absolute-relative | min-of-absolute-relative | absolute-only.
	 *
	 * @return bool True when the mismatch is within tolerance.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-13
	 */
	public function isWithinTolerance(
		int $mismatchCents,
		float $mismatchPercentage,
		float $toleranceAbsolute,
		float $toleranceRelative,
		string $method,
	): bool {
		$absolutePass = abs($mismatchCents) <= $this->toCents(amount: $toleranceAbsolute);
		$relativePass = $mismatchPercentage <= $toleranceRelative;

		if ($method === 'absolute-only') {
			return $absolutePass;
		}

		if ($method === 'min-of-absolute-relative') {
			return $absolutePass === true && $relativePass === true;
		}

		return $absolutePass === true || $relativePass === true;
	}//end isWithinTolerance()

	/**
	 * Determine the match status (REQ-ICE-003 / REQ-ICE-004).
	 *
	 * Returns one of perfect-match, within-tolerance, outside-tolerance, one-sided-A
	 * (only A booked), or one-sided-B (only B booked).
	 *
	 * @param int $totalACents Side A net total in cents.
	 * @param int $totalBCents Side B net total in cents.
	 * @param bool $withinTolerance Whether the mismatch passed tolerance.
	 *
	 * @return string The match status.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-12
	 */
	public function matchStatus(int $totalACents, int $totalBCents, bool $withinTolerance): string {
		if ($totalACents !== 0 && $totalBCents === 0) {
			return 'one-sided-A';
		}

		if ($totalACents === 0 && $totalBCents !== 0) {
			return 'one-sided-B';
		}

		if ($this->mismatchCents(totalACents: $totalACents, totalBCents: $totalBCents) === 0) {
			return 'perfect-match';
		}

		if ($withinTolerance === true) {
			return 'within-tolerance';
		}

		return 'outside-tolerance';
	}//end matchStatus()

	/**
	 * Convert an amount from a source currency to the reporting currency (REQ-ICE-009).
	 *
	 * @param float $amount The source amount in major units.
	 * @param float $rate The rate from source to reporting currency.
	 *
	 * @return float The converted amount in major units, rounded to 2 decimals.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-20
	 */
	public function convert(float $amount, float $rate): float {
		return round(($amount * $rate), 2);
	}//end convert()

	/**
	 * Build a balanced two-line elimination from a side total (REQ-ICE-006).
	 *
	 * Produces a debit line on the A account and a credit line on the B account for
	 * the matched (common) amount — the minimum of the two absolute side totals so
	 * the elimination never exceeds the booked amount. The returned structure always
	 * balances: totalDebit equals totalCredit.
	 *
	 * @param int $totalACents Side A net total in cents.
	 * @param int $totalBCents Side B net total in cents.
	 * @param string $accountA GL account on side A.
	 * @param string $accountB GL account on side B.
	 * @param string $description Line description.
	 *
	 * @return array{lines: array<int,array<string,mixed>>, totalDebit: float, totalCredit: float}
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
	 */
	public function buildEliminationLines(
		int $totalACents,
		int $totalBCents,
		string $accountA,
		string $accountB,
		string $description,
	): array {
		$commonCents = min(abs($totalACents), abs($totalBCents));
		$amount = $this->fromCents(cents: $commonCents);

		$lines = [
			[
				'glAccount' => $accountA,
				'debitAmount' => $amount,
				'creditAmount' => 0.0,
				'description' => $description,
			],
			[
				'glAccount' => $accountB,
				'debitAmount' => 0.0,
				'creditAmount' => $amount,
				'description' => $description,
			],
		];

		return [
			'lines' => $lines,
			'totalDebit' => $amount,
			'totalCredit' => $amount,
		];

	}//end buildEliminationLines()

	/**
	 * Classify the residual cause for a within-tolerance mismatch (REQ-ICE-005/009).
	 *
	 * A pragmatic default classifier: a residual that arises only because the two
	 * sides carry different currencies is fx-translation; otherwise it is unknown and
	 * left for operator classification. The full domain-expert classification remains
	 * operator-driven per design.md D5.
	 *
	 * @param bool $currencyDiff Whether the two sides use different currencies.
	 *
	 * @return string The cause classification enum value.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-17
	 */
	public function defaultCauseClassification(bool $currencyDiff): string {
		if ($currencyDiff === true) {
			return 'fx-translation';
		}

		return 'unknown';
	}//end defaultCauseClassification()
}//end class
