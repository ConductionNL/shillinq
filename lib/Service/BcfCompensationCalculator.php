<?php

/**
 * BCF Compensation Calculator
 *
 * Pure-logic helper for the Tier-3 Btw-compensatiefonds (BCF) claim
 * (REQ-BCF-002, REQ-BCF-003). Holds the side-effect-free arithmetic that
 * BcfClaimService applies after fetching GLLine + BbvAccountMapping data via the
 * OpenRegister ObjectService: deciding which postings are compensable, weighting
 * each by the account's compensablePercentage, summing to the quarter total, and
 * producing the per-account breakdown. All money arithmetic is performed in
 * integer cents to avoid IEEE-754 equality drift, mirroring TrialBalanceCalculator
 * and BalanceGuard.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free BCF compensable-VAT arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays and returns plain
 * arrays/scalars so the logic is unit-testable in isolation. BcfClaimService
 * wires this helper to live GLLine + BbvAccountMapping data.
 *
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 */
class BcfCompensationCalculator {
	/**
	 * Convert a money amount to integer cents (REQ-BCF-002 precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Clamp a compensable percentage to the closed range 0..100 (REQ-BCF-004).
	 *
	 * Mixed-use accounts carry a compensablePercentage between 0 and 100; values
	 * outside that range (corrupt data, operator error) are clamped so a single
	 * bad mapping can never over-claim. A non-numeric value is treated as 0
	 * (fail-closed: do not claim what we cannot weight).
	 *
	 * @param mixed $percentage Raw compensablePercentage (int|float|numeric-string|null).
	 *
	 * @return int Percentage clamped to 0..100.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-005
	 */
	public function clampPercentage(mixed $percentage): int {
		if (is_numeric($percentage) === false) {
			return 0;
		}

		$value = (int)round((float)$percentage);
		if ($value < 0) {
			return 0;
		}

		if ($value > 100) {
			return 100;
		}

		return $value;
	}//end clampPercentage()

	/**
	 * Weight a posting amount by a compensable percentage, in integer cents.
	 *
	 * The compensable cents equal round(amountCents * percentage / 100). Rounding
	 * is performed once, on the cent result, so a 50%-weighted €40.005 line cannot
	 * leak a sub-cent fraction into the quarter total.
	 *
	 * @param int $amountCents Posting amount in cents (already summed per account).
	 * @param int $percentage Compensable percentage (0..100; caller clamps).
	 *
	 * @return int Compensable amount in cents.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	public function weightedCents(int $amountCents, int $percentage): int {
		return (int)round(($amountCents * $percentage) / 100);
	}//end weightedCents()

	/**
	 * Determine whether a BbvAccountMapping marks its account as BCF-compensable.
	 *
	 * A posting only contributes to the claim when its account's mapping has
	 * bcfCompensable === true AND a positive compensablePercentage (REQ-BCF-002).
	 * A missing or non-true flag excludes the account (fail-closed).
	 *
	 * @param array<string,mixed>|null $mapping The BbvAccountMapping for the account, or null.
	 *
	 * @return bool True when the account's VAT is eligible for BCF compensation.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-003
	 */
	public function isCompensable(?array $mapping): bool {
		if ($mapping === null) {
			return false;
		}

		if (($mapping['bcfCompensable'] ?? false) !== true) {
			return false;
		}

		return $this->clampPercentage(percentage: ($mapping['compensablePercentage'] ?? 0)) > 0;
	}//end isCompensable()

	/**
	 * Compute the compensable-VAT breakdown and total for a quarter (REQ-BCF-002).
	 *
	 * Given the per-account VAT amounts posted in the quarter and the BBV account
	 * mappings (keyed by accountNumber), this:
	 *  - keeps only accounts whose mapping is bcfCompensable with percentage > 0,
	 *  - weights each account's amount by its compensablePercentage,
	 *  - sums the weighted cents to the quarter total,
	 *  - returns one breakdown row per contributing account.
	 *
	 * Non-compensable accounts, accounts without a mapping, and zero/negative
	 * amounts are excluded from the breakdown. The breakdown is sorted by
	 * accountNumber for deterministic output.
	 *
	 * @param array<string,int|float> $amountsByAccount accountNumber => VAT amount posted in the quarter.
	 * @param array<string,array<string,mixed>> $mappingsByAccount accountNumber => BbvAccountMapping.
	 *
	 * @return array{totalCompensableAmount: float, breakdown: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	public function computeCompensation(array $amountsByAccount, array $mappingsByAccount): array {
		$accountNumbers = array_keys($amountsByAccount);
		sort($accountNumbers);

		$totalCents = 0;
		$breakdown = [];
		foreach ($accountNumbers as $accountNumber) {
			$accountNumber = (string)$accountNumber;
			$mapping = ($mappingsByAccount[$accountNumber] ?? null);
			if ($this->isCompensable(mapping: $mapping) === false) {
				continue;
			}

			$amountCents = $this->toCents(amount: ($amountsByAccount[$accountNumber] ?? 0));
			if ($amountCents <= 0) {
				continue;
			}

			$percentage = $this->clampPercentage(percentage: ($mapping['compensablePercentage'] ?? 0));
			$compensableCents = $this->weightedCents(amountCents: $amountCents, percentage: $percentage);
			if ($compensableCents <= 0) {
				continue;
			}

			$totalCents += $compensableCents;
			$breakdown[] = [
				'accountNumber' => $accountNumber,
				'amount' => $this->fromCents(cents: $amountCents),
				'compensablePercentage' => $percentage,
				'compensableAmount' => $this->fromCents(cents: $compensableCents),
			];
		}//end foreach

		return [
			'totalCompensableAmount' => $this->fromCents(cents: $totalCents),
			'breakdown' => $breakdown,
		];

	}//end computeCompensation()

	/**
	 * Decide whether a draft claim may transition to submitted (REQ-BCF-003).
	 *
	 * The submit precondition requires a non-empty claim (totalCompensableAmount
	 * strictly greater than zero) AND a closed claim quarter (period lock from
	 * T2 period-close). An empty or open-quarter claim is rejected fail-closed.
	 *
	 * @param mixed $compensableTotal The claim's computed total (float|int|numeric-string|null).
	 * @param bool $quarterClosed Whether the claim quarter's fiscal period is closed.
	 *
	 * @return bool True when both submit preconditions hold.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-006
	 */
	public function canSubmit(mixed $compensableTotal, bool $quarterClosed): bool {
		if ($quarterClosed === false) {
			return false;
		}

		return $this->toCents(amount: $compensableTotal) > 0;
	}//end canSubmit()
}//end class
