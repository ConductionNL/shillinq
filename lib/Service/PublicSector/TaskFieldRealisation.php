<?php

/**
 * Task Field Realisation
 *
 * The realisation of one municipal administration's year per BBV task field:
 * the posted GL lines of the year summed per account and mapped through
 * `BbvAccountMapping` to a task field, as expenses (a debit balance) and
 * income (a credit balance). Every account that carries postings in the year
 * but has no mapping is listed rather than dropped (REQ-BBV-012).
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
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use OCA\Shillinq\Service\LedgerActuals;

/**
 * Sums a year's posted lines per BBV task field.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
 */
class TaskFieldRealisation {

	/**
	 * Where an unmapped account is mapped.
	 */
	public const MAPPING_PAGE = '/overheid/bbv-mapping';

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords $records The register's objects.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
	) {
	}//end __construct()

	/**
	 * The year's expenses and income per task field, and the accounts with
	 * postings but no mapping. Amounts are in euros, rounded to the cent.
	 *
	 * @param string $administrationId The municipal administration.
	 * @param string $fiscalYear       The fiscal year, echoed.
	 * @param string $startDate        The year's first day (`Y-m-d`), inclusive.
	 * @param string $endDate          The day after the year (`Y-m-d`), exclusive.
	 *
	 * @return array{
	 *     fiscalYear: string,
	 *     taskFields: list<array{taskField: string, expenses: float, income: float}>,
	 *     unmapped: list<array{accountNumber: string, amount: float, mappingPage: string}>
	 * }
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
	 */
	public function forYear(string $administrationId, string $fiscalYear, string $startDate, string $endDate): array {
		$result = ['fiscalYear' => $fiscalYear, 'taskFields' => [], 'unmapped' => []];
		if ($administrationId === '') {
			return $result;
		}

		$byAccount = $this->centsByAccount(administrationId: $administrationId, startDate: $startDate, endDate: $endDate);
		$taskFieldOf = $this->taskFieldByAccount(administrationId: $administrationId);

		$fields = [];
		$unmapped = [];
		foreach ($byAccount as $accountNumber => $cents) {
			$accountNumber = (string)$accountNumber;
			$taskField = ($taskFieldOf[$accountNumber] ?? '');
			if ($taskField === '') {
				$unmapped[$accountNumber] = $cents;
				continue;
			}

			$fields[$taskField] ??= ['expenses' => 0, 'income' => 0];
			if ($cents >= 0) {
				$fields[$taskField]['expenses'] += $cents;
			} else {
				$fields[$taskField]['income'] -= $cents;
			}
		}

		uksort($fields, 'strnatcmp');
		foreach ($fields as $taskField => $sums) {
			$result['taskFields'][] = [
				'taskField' => (string)$taskField,
				'expenses' => round($sums['expenses'] / 100, 2),
				'income' => round($sums['income'] / 100, 2),
			];
		}

		uksort($unmapped, 'strnatcmp');
		foreach ($unmapped as $accountNumber => $cents) {
			$result['unmapped'][] = [
				'accountNumber' => (string)$accountNumber,
				'amount' => round($cents / 100, 2),
				'mappingPage' => self::MAPPING_PAGE,
			];
		}

		return $result;
	}//end forYear()

	/**
	 * The signed cents per account of the posted lines in the window; an
	 * account with postings that net to zero is kept, at zero.
	 *
	 * @param string $administrationId The administration.
	 * @param string $startDate        The window's first day, inclusive.
	 * @param string $endDate          The window's end, exclusive.
	 *
	 * @return array<string, int> Account number to signed cents, debit positive.
	 */
	private function centsByAccount(string $administrationId, string $startDate, string $endDate): array {
		$posted = [];
		$transactions = $this->records->records(schema: 'GLTransaction', filters: ['administrationId' => $administrationId, 'state' => 'posted']);
		foreach ($transactions as $transaction) {
			$day = substr((string)($transaction['postingDate'] ?? ''), 0, 10);
			if ((string)($transaction['administrationId'] ?? '') !== $administrationId || ($transaction['state'] ?? '') !== 'posted') {
				continue;
			}

			if ($day !== '' && $day >= $startDate && $day < $endDate) {
				$posted[] = $transaction;
			}
		}

		$ledger = new LedgerActuals();
		$monthByRef = $ledger->postingMonths(transactions: $posted);
		if ($monthByRef === []) {
			return [];
		}

		$byAccount = [];
		$lines = $this->records->records(schema: 'GLLine', filters: []);
		foreach ($ledger->centsByAccountMonth(lines: $lines, monthByRef: $monthByRef) as $accountNumber => $months) {
			$byAccount[(string)$accountNumber] = array_sum($months);
		}

		return $byAccount;
	}//end centsByAccount()

	/**
	 * The task field each account of the administration is mapped to.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, string> Account number to task field.
	 */
	private function taskFieldByAccount(string $administrationId): array {
		$taskFields = [];
		$mappings = $this->records->records(schema: 'BbvAccountMapping', filters: ['administrationId' => $administrationId]);
		foreach ($mappings as $mapping) {
			$accountNumber = trim((string)($mapping['accountNumber'] ?? ''));
			$taskField = trim((string)($mapping['taskField'] ?? ''));
			if ((string)($mapping['administrationId'] ?? '') === $administrationId && $accountNumber !== '' && $taskField !== '') {
				$taskFields[$accountNumber] = $taskField;
			}
		}

		return $taskFields;
	}//end taskFieldByAccount()
}//end class
