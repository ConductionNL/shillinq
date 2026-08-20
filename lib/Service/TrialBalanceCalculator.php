<?php

/**
 * Trial Balance Calculator
 *
 * Pure-logic helper for the Tier-2 trial balance (REQ-TB-002, REQ-TB-003,
 * REQ-TB-005). Holds the side-effect-free arithmetic that TrialBalanceService
 * applies after fetching GL + Account data via the OpenRegister ObjectService:
 * carrying the opening balance from the prior period's close, computing the
 * closing balance, rolling child-account balances up to their parents, and
 * checking the debit = credit invariant. All money arithmetic is performed in
 * integer cents to avoid IEEE-754 equality drift, mirroring BalanceGuard.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
 * name; the archived REQ-TB-008 that mandates it was never canonical.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free trial-balance arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays and returns plain
 * arrays/scalars so the logic is unit-testable in isolation. TrialBalanceService
 * wires this helper to live GL + Account data.
 *
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
 * name; the archived REQ-TB-008 that mandates it was never canonical.
 */
class TrialBalanceCalculator {
	/**
	 * Convert a money amount to integer cents (REQ-TB-003 precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
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
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Compute the closing balance in cents per REQ-TB-003.
	 *
	 * The closing balance equals openingBalance + (debitMovement - creditMovement).
	 *
	 * @param int $openingCents Opening balance in cents.
	 * @param int $debitCents Period debit movement in cents.
	 * @param int $creditCents Period credit movement in cents.
	 *
	 * @return int Closing balance in cents.
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
	 */
	public function closingCents(int $openingCents, int $debitCents, int $creditCents): int {
		return ($openingCents + ($debitCents - $creditCents));
	}//end closingCents()

	/**
	 * Resolve the opening balance for an account from prior-period rows (REQ-TB-002).
	 *
	 * Opening balance of period N for an account equals the closing balance of
	 * that same account in period N-1. When no prior-period row exists (first
	 * period) the opening balance is zero.
	 *
	 * @param string $accountNumber The account code to resolve.
	 * @param array<int,array<string,mixed>> $priorPeriodRows Closing rows of the prior period,
	 *                                                        each carrying accountNumber + closingBalance.
	 *
	 * @return int Opening balance in cents (zero when no prior row exists).
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
	 */
	public function openingFromPrior(string $accountNumber, array $priorPeriodRows): int {
		foreach ($priorPeriodRows as $row) {
			if ((string)($row['accountNumber'] ?? '') === $accountNumber) {
				return $this->toCents(amount: ($row['closingBalance'] ?? 0));
			}
		}

		return 0;
	}//end openingFromPrior()

	/**
	 * Roll child-account closing balances up to their parents (REQ-TB-005, REQ-TB-020).
	 *
	 * Leaf accounts carry GL movements directly. A parent account's rolled-up
	 * closing balance is its own closing balance plus the closing balances of all
	 * descendants, resolved transitively via parentAccountNumber. The returned map
	 * is keyed by accountNumber and holds the rolled-up closing balance in cents.
	 *
	 * @param array<int,array<string,mixed>> $rows Per-account rows with accountNumber,
	 *                                             parentAccountNumber, closingBalance.
	 *
	 * @return array<string,int> accountNumber => rolled-up closing balance in cents.
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
	 */
	public function rollUpParents(array $rows): array {
		$ownCents = [];
		$parentOf = [];
		foreach ($rows as $row) {
			$account = (string)($row['accountNumber'] ?? '');
			if ($account === '') {
				continue;
			}

			$ownCents[$account] = $this->toCents(amount: ($row['closingBalance'] ?? 0));
			$parent = ($row['parentAccountNumber'] ?? null);
			$parentOf[$account] = null;
			if ($parent !== null) {
				$parentOf[$account] = (string)$parent;
			}
		}

		$rolled = $ownCents;
		foreach ($ownCents as $account => $cents) {
			$parent = $parentOf[$account];
			$guard = 0;
			while ($parent !== null && isset($rolled[$parent]) === true && $guard < 64) {
				$rolled[$parent] += $cents;
				$parent = ($parentOf[$parent] ?? null);
				$guard++;
			}
		}

		return $rolled;
	}//end rollUpParents()

	/**
	 * Determine whether a set of trial-balance rows is balanced (REQ-TB-003 proof).
	 *
	 * The general ledger is balanced when total debit movements equal total credit
	 * movements across all rows. Compared in integer cents to avoid float drift.
	 *
	 * @param array<int,array<string,mixed>> $rows Per-account rows with debitMovement + creditMovement.
	 *
	 * @return bool True when summed debits equal summed credits.
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
	 */
	public function isBalanced(array $rows): bool {
		$debit = 0;
		$credit = 0;
		foreach ($rows as $row) {
			$debit += $this->toCents(amount: ($row['debitMovement'] ?? 0));
			$credit += $this->toCents(amount: ($row['creditMovement'] ?? 0));
		}

		return $debit === $credit;
	}//end isBalanced()

	/**
	 * Sum closing balances per account type for the KPI cards (REQ-TB-011).
	 *
	 * Returns totals (in float money) keyed by the five account types plus the
	 * grand debit/credit movement totals used to render the balanced indicator.
	 *
	 * @param array<int,array<string,mixed>> $rows Per-account trial-balance rows.
	 *
	 * @return array<string,float> Totals: totalAssets, totalLiabilities, totalEquity,
	 *                             totalRevenue, totalExpenses, totalDebit, totalCredit.
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
	 * KNOWINGLY DANGLING until shillinq#500 — REQ-TB-001 forbids this class by
	 * name; the archived REQ-TB-008 that mandates it was never canonical.
	 */
	public function totals(array $rows): array {
		$byType = [
			'assets' => 0,
			'liabilities' => 0,
			'equity' => 0,
			'revenue' => 0,
			'expenses' => 0,
		];
		$debit = 0;
		$credit = 0;
		foreach ($rows as $row) {
			$type = (string)($row['accountType'] ?? '');
			if (isset($byType[$type]) === true) {
				$byType[$type] += $this->toCents(amount: ($row['closingBalance'] ?? 0));
			}

			$debit += $this->toCents(amount: ($row['debitMovement'] ?? 0));
			$credit += $this->toCents(amount: ($row['creditMovement'] ?? 0));
		}

		return [
			'totalAssets' => $this->fromCents(cents: $byType['assets']),
			'totalLiabilities' => $this->fromCents(cents: $byType['liabilities']),
			'totalEquity' => $this->fromCents(cents: $byType['equity']),
			'totalRevenue' => $this->fromCents(cents: $byType['revenue']),
			'totalExpenses' => $this->fromCents(cents: $byType['expenses']),
			'totalDebit' => $this->fromCents(cents: $debit),
			'totalCredit' => $this->fromCents(cents: $credit),
		];

	}//end totals()
}//end class
