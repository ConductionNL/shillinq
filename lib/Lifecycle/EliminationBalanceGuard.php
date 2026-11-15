<?php

/**
 * Elimination Balance Guard
 *
 * ADR-031 exception-path lifecycle guard for the EliminationJournal create
 * transition (REQ-ICE-006). Returns true only when the journal's lines balance —
 * the sum of debit amounts equals the sum of credit amounts — so an unbalanced
 * elimination can never be persisted into the consolidation layer.
 *
 * ADR-031 exception reason: cross-line aggregation (SUM of embedded line debit and
 * credit amounts) inside a declarative `requires:` clause is not yet expressible in
 * OpenRegister's lifecycle DSL. When the engine gains that capability, replace this
 * reference with a declarative condition and delete this file.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for elimination-journal balance.
 *
 * Referenced from the EliminationJournal schema's x-openregister-lifecycle
 * transitions.create.requires as
 * OCA\Shillinq\Lifecycle\EliminationBalanceGuard::isBalanced.
 *
 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
 */
class EliminationBalanceGuard {
	/**
	 * Construct the guard with DI dependencies.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Returns true iff the elimination journal's lines balance (debit == credit).
	 *
	 * Computes SUM(line.debitAmount) and SUM(line.creditAmount) over the embedded
	 * `lines` array using integer-cent arithmetic to avoid IEEE-754 float equality
	 * issues, then requires the two sums to be equal. An elimination with no lines is
	 * rejected (an empty journal is meaningless). When `totalDebit`/`totalCredit` are
	 * supplied they must also be internally consistent with the line sums.
	 *
	 * Fail-closed: returns false on any exception (REQ-ICE-006 / CWE-863).
	 *
	 * @param array<string, mixed> $journal The EliminationJournal object array.
	 *
	 * @return bool True when the journal is balanced and may be persisted.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
	 */
	public function isBalanced(array $journal): bool {
		try {
			return $this->journalBalances(journal: $journal);
		} catch (\Throwable $e) {
			$this->logger->error(
				'EliminationBalanceGuard: balance check failed — denying create (fail-closed)',
				['eliminationId' => ($journal['eliminationId'] ?? 'unknown'), 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end isBalanced()

	/**
	 * The journal has lines, its debit and credit sums are equal, and any totals agree.
	 *
	 * @param array<string, mixed> $journal The EliminationJournal object array.
	 *
	 * @return bool True when the journal is balanced.
	 */
	private function journalBalances(array $journal): bool {
		$lines = ($journal['lines'] ?? []);
		if (is_array($lines) === false || $lines === []) {
			$this->logger->warning(
				'EliminationBalanceGuard: journal has no lines — denying create (fail-closed)',
				['eliminationId' => ($journal['eliminationId'] ?? 'unknown')]
			);
			return false;
		}

		$sums = $this->lineSums(lines: $lines);
		if ($sums['debit'] !== $sums['credit']) {
			$this->logger->warning(
				'EliminationBalanceGuard: lines do not balance — denying create',
				[
					'eliminationId' => ($journal['eliminationId'] ?? 'unknown'),
					'debitCents' => $sums['debit'],
					'creditCents' => $sums['credit'],
				]
			);
			return false;
		}

		// When totals are supplied, verify they agree with the line sums.
		return $this->totalsConsistent(journal: $journal, sums: $sums);

	}//end journalBalances()

	/**
	 * Sum the array lines' debit and credit amounts in integer cents.
	 *
	 * @param array<int|string, mixed> $lines The journal lines.
	 *
	 * @return array{debit: int, credit: int} The sums.
	 */
	private function lineSums(array $lines): array {
		$sums = ['debit' => 0, 'credit' => 0];
		foreach ($lines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$sums['debit'] += (int)round((float)($line['debitAmount'] ?? 0) * 100);
			$sums['credit'] += (int)round((float)($line['creditAmount'] ?? 0) * 100);
		}

		return $sums;

	}//end lineSums()

	/**
	 * Verify that any supplied totalDebit / totalCredit agree with the line sums.
	 *
	 * When the journal omits the totals this is vacuously true; when present they
	 * must equal the integer-cent line sums or the journal is rejected.
	 *
	 * @param array<string, mixed> $journal The EliminationJournal object array.
	 * @param array{debit: int, credit: int} $sums The line sums in cents.
	 *
	 * @return bool True when the supplied totals are internally consistent.
	 *
	 * @spec openspec/changes/bookkeeping-intercompany-elimination/tasks.md#task-16
	 */
	private function totalsConsistent(array $journal, array $sums): bool {
		if (array_key_exists('totalDebit', $journal) === true
			&& (int)round((float)$journal['totalDebit'] * 100) !== $sums['debit']
		) {
			return false;
		}

		return array_key_exists('totalCredit', $journal) === false
			|| (int)round((float)$journal['totalCredit'] * 100) === $sums['credit'];

	}//end totalsConsistent()
}//end class
