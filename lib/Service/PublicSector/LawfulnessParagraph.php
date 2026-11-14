<?php

/**
 * Lawfulness Paragraph
 *
 * Computes the totals of a year's lawfulness paragraph (rechtmatigheids-
 * paragraaf): the total expenses including reserve mutations, the tolerance
 * amounts for errors and uncertainties, the sums of the year's findings and
 * whether both sums stay within tolerance (REQ-RV-011).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DomainException;
use OCA\Shillinq\Kernel\Contracts\LawfulnessParagraphInterface;
use OCA\Shillinq\Service\LedgerActuals;

/**
 * The totals of a lawfulness paragraph, computed from the posted ledger.
 *
 * Every amount is summed in whole cents and written back in euros, so a sum of
 * many lines does not drift by a cent.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 */
class LawfulnessParagraph implements LawfulnessParagraphInterface {

	/**
	 * The account type whose net debits are expenses.
	 *
	 * @var string
	 */
	private const EXPENSES = 'expenses';

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords $records The register's records.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
	) {
	}//end __construct()

	/**
	 * The paragraph totals of one administration's financial year.
	 *
	 * Expenses are the net debits on expense accounts of the transactions
	 * posted in the year, plus the debits on the result accounts of the
	 * administration's reserves (additions to reserves count as expenses;
	 * withdrawals are income and do not reduce them). An account that is both
	 * counts once, as an expense account.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $financialYear    The financial year.
	 *
	 * @return array<string, mixed> The paragraph fields to store.
	 *
	 * @throws DomainException When the year has no tolerance.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function compute(string $administrationId, int $financialYear): array {
		if ($administrationId === '' || $financialYear < 1900) {
			throw new DomainException('A lawfulness paragraph needs an administration and a financial year before it can be computed.');
		}

		$tolerance = $this->tolerance(administrationId: $administrationId, financialYear: $financialYear);
		$expenseCents = $this->expenseCents(administrationId: $administrationId, financialYear: $financialYear);
		$errorPercentage = (float)($tolerance['error_percentage'] ?? 0);
		$uncertaintyPercentage = (float)($tolerance['uncertainty_percentage'] ?? 0);
		$errorToleranceCents = (int)round($expenseCents * $errorPercentage / 100);
		$uncertaintyToleranceCents = (int)round($expenseCents * $uncertaintyPercentage / 100);

		$findings = $this->findings(administrationId: $administrationId, financialYear: $financialYear);
		$errorCents = 0;
		$uncertaintyCents = 0;
		$findingIds = [];
		foreach ($findings as $finding) {
			$errorCents += self::cents(amount: $finding['amount_error'] ?? 0);
			$uncertaintyCents += self::cents(amount: $finding['amount_uncertainty'] ?? 0);
			$findingIds[] = (string)$finding['id'];
		}

		return [
			'total_expenses_including_reserve_movements' => self::euros(cents: $expenseCents),
			'tolerance_limit_error_percentage' => $errorPercentage,
			'tolerance_error_amount' => self::euros(cents: $errorToleranceCents),
			'tolerance_limit_uncertainty_percentage' => $uncertaintyPercentage,
			'tolerance_uncertainty_amount' => self::euros(cents: $uncertaintyToleranceCents),
			'total_identified_errors' => self::euros(cents: $errorCents),
			'total_identified_uncertainties' => self::euros(cents: $uncertaintyCents),
			'within_tolerance' => ($errorCents <= $errorToleranceCents && $uncertaintyCents <= $uncertaintyToleranceCents),
			'bevindingen' => $findingIds,
		];
	}//end compute()

	/**
	 * The year's tolerance record of the administration.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $financialYear    The financial year.
	 *
	 * @return array<string, mixed> The tolerance.
	 *
	 * @throws DomainException When there is none.
	 */
	private function tolerance(string $administrationId, int $financialYear): array {
		$filters = ['administrationId' => $administrationId, 'financialYear' => $financialYear];
		foreach ($this->records->records(schema: 'Tolerantiegrens', filters: $filters) as $tolerance) {
			if (self::belongs(record: $tolerance, administrationId: $administrationId, financialYear: $financialYear) === true) {
				return $tolerance;
			}
		}

		throw new DomainException(sprintf('Record the tolerance for %d before the lawfulness paragraph can be computed.', $financialYear));
	}//end tolerance()

	/**
	 * The findings of the administration's year, in any status.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $financialYear    The financial year.
	 *
	 * @return list<array<string, mixed>> The findings.
	 */
	private function findings(string $administrationId, int $financialYear): array {
		$filters = ['administrationId' => $administrationId, 'financialYear' => $financialYear];
		$findings = [];
		foreach ($this->records->records(schema: 'Rechtmatigheidsbevinding', filters: $filters) as $finding) {
			if (self::belongs(record: $finding, administrationId: $administrationId, financialYear: $financialYear) === true) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}//end findings()

	/**
	 * The year's expenses including additions to reserves, in cents.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $financialYear    The financial year.
	 *
	 * @return int The expenses in cents.
	 */
	private function expenseCents(string $administrationId, int $financialYear): int {
		$posted = [];
		$transactions = $this->records->records(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']);
		foreach ($transactions as $transaction) {
			$year = substr((string)($transaction['postingDate'] ?? ''), 0, 4);
			if ((string)($transaction['administrationId'] ?? '') === $administrationId
				&& ($transaction['state'] ?? '') === 'posted'
				&& $year === (string)$financialYear
			) {
				$posted[] = $transaction;
			}
		}

		$refs = (new LedgerActuals())->postingMonths(transactions: $posted);
		if ($refs === []) {
			return 0;
		}

		$expenseAccounts = $this->expenseAccounts(administrationId: $administrationId);
		$reserveAccounts = array_diff_key($this->reserveResultAccounts(administrationId: $administrationId), $expenseAccounts);
		$cents = 0;
		foreach ($this->records->records(schema: 'GLLine', filters: []) as $line) {
			$account = (string)($line['accountNumber'] ?? '');
			if (isset($refs[(string)($line['transactionId'] ?? '')]) === false || $account === '') {
				continue;
			}

			$debit = ((string)($line['side'] ?? 'debit') !== 'credit');
			$amount = self::cents(amount: $line['amount'] ?? 0);
			if (isset($expenseAccounts[$account]) === true && $debit === true) {
				$cents += $amount;
			} else if (isset($expenseAccounts[$account]) === true) {
				$cents -= $amount;
			} else if (isset($reserveAccounts[$account]) === true && $debit === true) {
				$cents += $amount;
			}
		}

		return $cents;
	}//end expenseCents()

	/**
	 * The administration's expense accounts, by account number.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, true> Account numbers.
	 */
	private function expenseAccounts(string $administrationId): array {
		$accounts = [];
		$filters = ['administrationId' => $administrationId, 'accountType' => self::EXPENSES];
		foreach ($this->records->records(schema: 'Account', filters: $filters) as $account) {
			$number = trim((string)($account['accountNumber'] ?? ''));
			if ($number !== '' && ($account['accountType'] ?? '') === self::EXPENSES
				&& (string)($account['administrationId'] ?? $administrationId) === $administrationId
			) {
				$accounts[$number] = true;
			}
		}

		return $accounts;
	}//end expenseAccounts()

	/**
	 * The result accounts the administration's reserve mutations post on.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, true> Account numbers.
	 */
	private function reserveResultAccounts(string $administrationId): array {
		$accounts = [];
		foreach ($this->records->records(schema: 'Reserve', filters: ['administrationId' => $administrationId]) as $reserve) {
			$number = trim((string)($reserve['resultAccountNumber'] ?? ''));
			if ($number !== '' && (string)($reserve['administrationId'] ?? '') === $administrationId) {
				$accounts[$number] = true;
			}
		}

		return $accounts;
	}//end reserveResultAccounts()

	/**
	 * Whether a record is the administration's, for the year. The read is
	 * filtered already; this keeps a filter OpenRegister ignored from
	 * letting another administration's rows in.
	 *
	 * @param array<string, mixed> $record           The record.
	 * @param string               $administrationId The administration.
	 * @param int                  $financialYear    The financial year.
	 *
	 * @return bool
	 */
	private static function belongs(array $record, string $administrationId, int $financialYear): bool {
		return (string)($record['administrationId'] ?? '') === $administrationId
			&& (int)($record['financialYear'] ?? 0) === $financialYear;
	}//end belongs()

	/**
	 * An amount in euros as whole cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int The cents.
	 */
	private static function cents(mixed $amount): int {
		if (is_numeric($amount) === false) {
			return 0;
		}

		return (int)round(((float)$amount) * 100);
	}//end cents()

	/**
	 * Whole cents as euros.
	 *
	 * @param int $cents The cents.
	 *
	 * @return float The euros.
	 */
	private static function euros(int $cents): float {
		return ($cents / 100);
	}//end euros()
}//end class
