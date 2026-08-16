<?php

/**
 * Aansluiting Calculator
 *
 * Pure-logic helper for the Aansluiting (tie-out) framework (REQ-AANS-003,
 * REQ-AANS-004, REQ-AANS-005). Holds the side-effect-free arithmetic
 * AansluitingService applies after resolving a source A / source B total
 * pair: computing the signed difference per the declared expected
 * relationship (equal / equal-with-sign-flip), deciding whether the
 * difference is within the declared tolerance, and diffing two bucket
 * lists into a generic drill-down shape shared by every aansluitingType.
 * All money arithmetic is performed in integer cents to avoid IEEE-754
 * equality drift, mirroring TrialBalanceCalculator and IcpCalculator.
 *
 * No OpenRegister dependency: every method takes plain scalars/arrays and
 * returns plain arrays/scalars so the logic is unit-testable in isolation.
 * AansluitingService wires this helper to live Aansluiting/AansluitingResult
 * data and the per-type source resolvers.
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
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free Aansluiting arithmetic and diff helper.
 *
 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
 */
class AansluitingCalculator {
	/**
	 * Convert a money amount to integer cents (half-even rounding), matching
	 * the codebase-wide money convention (TrialBalanceCalculator, IcpCalculator).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100, 0, PHP_ROUND_HALF_EVEN);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Compute the signed difference (in whole cents) between source A and
	 * source B per the declared expected relationship (REQ-AANS-003).
	 *
	 * 'equal': sourceATotal - sourceBTotal (both are expected to carry the
	 * same sign convention, e.g. an asset control account vs. its debit-
	 * balance subledger).
	 *
	 * 'equal-with-sign-flip': sourceATotal + sourceBTotal (source A is
	 * expected to carry the opposite sign convention from source B, e.g. a
	 * liability control account, which nets negative under a debit-positive
	 * convention, against its positive-sum subledger total).
	 *
	 * @param float $sourceATotal Source A total (EUR).
	 * @param float $sourceBTotal Source B total (EUR).
	 * @param string $relationship 'equal' or 'equal-with-sign-flip'.
	 *
	 * @return int The signed difference in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function differenceCents(float $sourceATotal, float $sourceBTotal, string $relationship): int {
		$aCents = $this->toCents(amount: $sourceATotal);
		$bCents = $this->toCents(amount: $sourceBTotal);

		if ($relationship === 'equal-with-sign-flip') {
			return ($aCents + $bCents);
		}

		return ($aCents - $bCents);
	}//end differenceCents()

	/**
	 * Decide whether a difference is within the declared tolerance
	 * (REQ-AANS-003).
	 *
	 * @param int $differenceCents The signed difference in whole cents.
	 * @param int $toleranceCents The maximum absolute difference still considered within tolerance.
	 *
	 * @return bool True when abs(differenceCents) <= toleranceCents.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function isWithinTolerance(int $differenceCents, int $toleranceCents): bool {
		return (abs($differenceCents) <= abs($toleranceCents));
	}//end isWithinTolerance()

	/**
	 * Diff two bucket lists (each keyed by an arbitrary bucketKey, e.g. a
	 * "type:taxRate" rubriek key) into the generic AansluitingResult
	 * lineDeltas shape (REQ-AANS-005). A bucket present in only one list is
	 * still emitted, with the other side's amount reported as null. Results are
	 * sorted by bucketKey ascending and exclude TOTAL — callers prepend their own
	 * TOTAL row.
	 *
	 * @param array<string,float> $bucketsA Source A amounts keyed by bucketKey.
	 * @param array<string,float> $bucketsB Source B amounts keyed by bucketKey.
	 * @param string $relationship 'equal' or 'equal-with-sign-flip'; controls how deltaAmount
	 *                             is computed per bucket, consistent with differenceCents().
	 *
	 * @return array<int,array{bucketKey:string,sourceAAmount:?float,sourceBAmount:?float,deltaAmount:float}>
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function diffBuckets(array $bucketsA, array $bucketsB, string $relationship = 'equal'): array {
		$keys = array_unique(array_merge(array_keys($bucketsA), array_keys($bucketsB)));
		sort($keys);

		$rows = [];
		foreach ($keys as $key) {
			$hasA = array_key_exists($key, $bucketsA);
			$hasB = array_key_exists($key, $bucketsB);

			$aAmount = null;
			if ($hasA === true) {
				$aAmount = (float)$bucketsA[$key];
			}

			$bAmount = null;
			if ($hasB === true) {
				$bAmount = (float)$bucketsB[$key];
			}

			$deltaCents = $this->differenceCents(
				sourceATotal: ($aAmount ?? 0.0),
				sourceBTotal: ($bAmount ?? 0.0),
				relationship: $relationship
			);

			$rows[] = [
				'bucketKey' => $key,
				'sourceAAmount' => $aAmount,
				'sourceBAmount' => $bAmount,
				'deltaAmount' => $this->fromCents(cents: $deltaCents),
			];
		}//end foreach

		return $rows;
	}//end diffBuckets()
}//end class
