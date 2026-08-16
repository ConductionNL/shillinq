<?php

/**
 * Tax Report Calculator
 *
 * Pure-logic helper for the Tier-2 Vpb quarterly tax statement (REQ-VPB-003,
 * REQ-VPB-009, REQ-VPB-010). Holds the side-effect-free arithmetic that
 * TaxReportService applies after fetching GLLine + Account data via the
 * OpenRegister ObjectService: classifying a posting's signed contribution by
 * account type and taxTreatment, summing into the revenue / operatingExpenses /
 * nonOperating / specialDeductions buckets, computing net taxable income, and
 * counting tax-relevant postings that lack a taxTreatment tag. All money
 * arithmetic is performed in integer cents to avoid IEEE-754 drift, mirroring
 * TrialBalanceCalculator.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free Vpb quarterly-statement arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays and returns plain
 * arrays/scalars so the logic is unit-testable in isolation. TaxReportService
 * wires this helper to live GLLine + Account data.
 *
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
 */
class TaxReportCalculator {
	/**
	 * Account types treated as revenue when computing taxable income.
	 *
	 * @var array<int,string>
	 */
	private const REVENUE_TYPES = ['revenue'];

	/**
	 * Account types treated as deductible operating expenses.
	 *
	 * @var array<int,string>
	 */
	private const EXPENSE_TYPES = ['expenses'];

	/**
	 * Convert a money amount to integer cents (REQ-VPB-009 precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
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
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Whether an account type is tax-relevant (revenue or expense).
	 *
	 * Untagged tax-relevant postings drive the REQ-VPB-010 warning count.
	 *
	 * @param string $accountType The account classification.
	 *
	 * @return bool True for revenue/expense account types.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-23
	 */
	public function isTaxRelevant(string $accountType): bool {
		return in_array($accountType, array_merge(self::REVENUE_TYPES, self::EXPENSE_TYPES), true);
	}//end isTaxRelevant()

	/**
	 * Aggregate GL rows into a quarterly Vpb statement (REQ-VPB-003, REQ-VPB-009).
	 *
	 * Each input row carries: amount, side ('debit'|'credit'), accountType,
	 * taxTreatment ('normal'|'deductible'|'nonDeductible'|'special'|''). The signed
	 * contribution of a row is +amount for a credit (income side) and -amount for a
	 * debit (expense side); buckets are filled per account type and tax treatment:
	 *  - revenue           — sum of revenue-account postings (credit positive)
	 *  - operatingExpenses — sum of expense-account postings (taxTreatment normal/deductible)
	 *  - nonOperating      — sum of expense postings tagged nonDeductible (added back)
	 *  - specialDeductions — sum of postings tagged special
	 * netTaxableIncome = revenue - operatingExpenses + nonOperating - specialDeductions.
	 * untaggedCount counts tax-relevant postings whose taxTreatment is blank.
	 *
	 * @param array<int,array<string,mixed>> $rows GLLine rows joined with accountType.
	 *
	 * @return array<string,mixed> Keys: revenue, operatingExpenses, nonOperating,
	 *                             specialDeductions, netTaxableIncome (floats),
	 *                             untaggedCount (int) and breakdown (per-account rows).
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
	 */
	public function aggregate(array $rows): array {
		$revenue = 0;
		$operating = 0;
		$nonOperating = 0;
		$special = 0;
		$untagged = 0;
		$byAccount = [];

		foreach ($rows as $row) {
			$accountType = (string)($row['accountType'] ?? '');
			$taxTreatment = (string)($row['taxTreatment'] ?? '');
			$amountCents = abs($this->toCents(amount: ($row['amount'] ?? 0)));

			$bucket = $this->bucketFor(accountType: $accountType, taxTreatment: $taxTreatment);
			if ($bucket === 'special') {
				$special += $amountCents;
			} elseif ($bucket === 'nonOperating') {
				$nonOperating += $amountCents;
			} elseif ($bucket === 'revenue') {
				$revenue += $amountCents;
			} elseif ($bucket === 'operating') {
				$operating += $amountCents;
			}

			if ($this->isTaxRelevant(accountType: $accountType) === true && $taxTreatment === '') {
				$untagged++;
			}

			$byAccount = $this->accumulateAccount(
				byAccount: $byAccount,
				row: $row,
				amountCents: $amountCents
			);
		}//end foreach

		$netCents = ($revenue - $operating + $nonOperating - $special);

		$breakdown = [];
		ksort($byAccount);
		foreach ($byAccount as $entry) {
			$breakdown[] = [
				'accountNumber' => $entry['accountNumber'],
				'accountName' => $entry['accountName'],
				'accountType' => $entry['accountType'],
				'taxTreatment' => $entry['taxTreatment'],
				'amount' => $this->fromCents(cents: (int)$entry['amountCents']),
			];
		}

		return [
			'revenue' => $this->fromCents(cents: $revenue),
			'operatingExpenses' => $this->fromCents(cents: $operating),
			'nonOperating' => $this->fromCents(cents: $nonOperating),
			'specialDeductions' => $this->fromCents(cents: $special),
			'netTaxableIncome' => $this->fromCents(cents: $netCents),
			'untaggedCount' => $untagged,
			'breakdown' => $breakdown,
		];

	}//end aggregate()

	/**
	 * Classify a posting into a taxable-income bucket (REQ-VPB-009).
	 *
	 * Tax treatment takes precedence over account type: a 'special' or
	 * 'nonDeductible' tag always wins; otherwise the account type decides between
	 * revenue and operating expense. Returns '' when the posting affects no bucket.
	 *
	 * @param string $accountType The account classification.
	 * @param string $taxTreatment The posting's tax-treatment tag.
	 *
	 * @return string One of 'special', 'nonOperating', 'revenue', 'operating', or ''.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
	 */
	private function bucketFor(string $accountType, string $taxTreatment): string {
		if ($taxTreatment === 'special') {
			return 'special';
		}

		if ($taxTreatment === 'nonDeductible') {
			return 'nonOperating';
		}

		if (in_array($accountType, self::REVENUE_TYPES, true) === true) {
			return 'revenue';
		}

		if (in_array($accountType, self::EXPENSE_TYPES, true) === true) {
			return 'operating';
		}

		return '';
	}//end bucketFor()

	/**
	 * Accumulate one posting into the per-account breakdown map.
	 *
	 * @param array<string,array<string,mixed>> $byAccount Accumulated breakdown keyed by accountNumber.
	 * @param array<string,mixed> $row The GLLine row joined with accountType.
	 * @param int $amountCents The posting amount in absolute cents.
	 *
	 * @return array<string,array<string,mixed>> The updated breakdown map.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
	 */
	private function accumulateAccount(array $byAccount, array $row, int $amountCents): array {
		$accountKey = (string)($row['accountNumber'] ?? '');
		if ($accountKey === '') {
			return $byAccount;
		}

		$taxTreatment = (string)($row['taxTreatment'] ?? '');
		$rowTreatment = 'normal';
		if ($taxTreatment !== '') {
			$rowTreatment = $taxTreatment;
		}

		if (isset($byAccount[$accountKey]) === false) {
			$byAccount[$accountKey] = [
				'accountNumber' => $accountKey,
				'accountName' => ($row['accountName'] ?? null),
				'accountType' => (string)($row['accountType'] ?? ''),
				'taxTreatment' => $rowTreatment,
				'amountCents' => 0,
			];
		}

		$byAccount[$accountKey]['amountCents'] += $amountCents;

		return $byAccount;
	}//end accumulateAccount()

	/**
	 * Estimate the Vpb liability from net taxable income (REQ-VPB-012).
	 *
	 * Applies the Dutch 2025 two-bracket corporate-income-tax rates: 19% on the
	 * first EUR 200,000 of net taxable income and 25.8% on the excess. Negative
	 * income yields a zero estimate. The result is informational (the actual
	 * assessment is produced by the Belastingdienst).
	 *
	 * @param float $netTaxableIncome Annual net taxable income.
	 *
	 * @return float Estimated Vpb liability.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-25
	 */
	public function estimateLiability(float $netTaxableIncome): float {
		$netCents = $this->toCents(amount: $netTaxableIncome);
		if ($netCents <= 0) {
			return 0.0;
		}

		$bracketCents = (200000 * 100);
		if ($netCents <= $bracketCents) {
			return $this->fromCents(cents: (int)round($netCents * 0.19));
		}

		$lowCents = (int)round($bracketCents * 0.19);
		$highCents = (int)round(($netCents - $bracketCents) * 0.258);

		return $this->fromCents(cents: ($lowCents + $highCents));
	}//end estimateLiability()
}//end class
