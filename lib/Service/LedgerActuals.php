<?php

/**
 * Ledger Actuals
 *
 * Turns posted GL transactions and their lines into actuals: the posting
 * month per transaction reference, and the signed cents per account and
 * month. Pure functions over rows the caller has already read.
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Posting-month index and per-account monthly cents of GL actuals.
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 */
final class LedgerActuals {

	/**
	 * Index the posting month (`YYYY-MM`) of every transaction with a posting
	 * date, under each reference a GL line may use for it. A later
	 * transaction with the same reference wins.
	 *
	 * @param list<array<string,mixed>> $transactions The posted GL transactions.
	 *
	 * @return array<string,string> Transaction reference => posting month.
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function postingMonths(array $transactions): array {
		$months = [];
		foreach ($transactions as $transaction) {
			$postingDate = (string)($transaction['postingDate'] ?? '');
			if ($postingDate === '') {
				continue;
			}

			foreach ($this->transactionRefs(transaction: $transaction) as $ref) {
				$months[$ref] = substr($postingDate, 0, 7);
			}
		}

		return $months;
	}//end postingMonths()

	/**
	 * Every reference a GL line may use to name a transaction: its object id
	 * (`@self.id`, else `id`), then its `transactionNumber`, when non-empty.
	 *
	 * @param array<string,mixed> $transaction The GL transaction.
	 *
	 * @return list<string> The non-empty references.
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function transactionRefs(array $transaction): array {
		$refs = [
			(string)($transaction['@self']['id'] ?? $transaction['id'] ?? ''),
			(string)($transaction['transactionNumber'] ?? ''),
		];

		return array_values(array_filter($refs, static fn (string $ref): bool => $ref !== ''));
	}//end transactionRefs()

	/**
	 * Bucket every GL line's signed amount by `(accountNumber, monthKey)`, in
	 * EUR cents. A line whose transaction is not in `$monthByRef` (unposted,
	 * or a different administration) or that has no account is skipped.
	 *
	 * @param list<array<string,mixed>> $lines      The GL lines.
	 * @param array<string,string>      $monthByRef Transaction reference => posting month.
	 *
	 * @return array<string,array<string,int>> accountNumber => monthKey => signed cents.
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function centsByAccountMonth(array $lines, array $monthByRef): array {
		$buckets = [];
		foreach ($lines as $line) {
			$ref = (string)($line['transactionId'] ?? '');
			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($ref === '' || isset($monthByRef[$ref]) === false || $accountNumber === '') {
				continue;
			}

			$month = $monthByRef[$ref];
			$buckets[$accountNumber][$month] = (($buckets[$accountNumber][$month] ?? 0) + $this->signedCents(line: $line));
		}

		return $buckets;
	}//end centsByAccountMonth()

	/**
	 * A line's amount in cents, negative for a credit.
	 *
	 * @param array<string,mixed> $line The GL line.
	 *
	 * @return int The signed cents.
	 */
	private function signedCents(array $line): int {
		$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);
		if ((string)($line['side'] ?? 'debit') === 'credit') {
			return -$cents;
		}

		return $cents;
	}//end signedCents()
}//end class
