<?php

/**
 * Profit and loss with comparison columns.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

/**
 * Puts the chosen range and up to two comparison ranges side by side: one
 * row per account found in any column, its amount per column and, for each
 * comparison, the chosen amount minus the compared one.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
class ProfitLossComparison {

	/**
	 * Constructor.
	 *
	 * @param StatementCalculator $calculator The statement figures.
	 */
	public function __construct(
		private readonly StatementCalculator $calculator,
	) {
	}//end __construct()

	/**
	 * The profit and loss of a range, compared.
	 *
	 * @param string             $administrationId The administration, already checked for access.
	 * @param string             $from             First posting date, YYYY-MM-DD.
	 * @param string             $to               Last posting date, YYYY-MM-DD.
	 * @param array<int, string> $comparisons      Kinds from StatementPeriods::COMPARISONS.
	 *
	 * @return array<string, mixed> administrationId, columns, revenue, expense, totals.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public function compare(string $administrationId, string $from, string $to, array $comparisons): array {
		$columns = [['key' => 'current', 'from' => $from, 'to' => $to]];
		foreach ($comparisons as $kind) {
			$columns[] = ['key' => $kind] + StatementPeriods::comparisonRange(kind: $kind, from: $from, to: $to);
		}

		$statements = [];
		foreach ($columns as $column) {
			$statements[$column['key']] = $this->calculator->resultsBetween(
				administrationId: $administrationId,
				from: $column['from'],
				to: $column['to']
			);
		}

		return [
			'administrationId' => $administrationId,
			'columns' => $columns,
			'revenue' => $this->section(statements: $statements, columns: $columns, section: 'revenue'),
			'expense' => $this->section(statements: $statements, columns: $columns, section: 'expense'),
			'totals' => [
				'revenue' => $this->totals(statements: $statements, key: 'revenue'),
				'expense' => $this->totals(statements: $statements, key: 'expense'),
				'result' => $this->totals(statements: $statements, key: 'result'),
			],
		];
	}//end compare()

	/**
	 * One section's rows across the columns, by account number.
	 *
	 * @param array<string, array<string, mixed>>    $statements Statement per column key.
	 * @param array<int, array<string, string>>      $columns    The columns.
	 * @param string                                 $section    revenue or expense.
	 *
	 * @return array<int, array<string, mixed>> accountNumber, label, amounts, differences, ranges.
	 */
	private function section(array $statements, array $columns, string $section): array {
		$rows = [];
		foreach ($columns as $column) {
			foreach ($statements[$column['key']][$section] as $line) {
				$account = (string)$line['accountNumber'];
				$rows[$account] ??= ['accountNumber' => $account, 'label' => $line['label'], 'amounts' => [], 'ranges' => []];
				$rows[$account]['amounts'][$column['key']] = $line['amount'];
			}
		}

		ksort($rows, SORT_STRING);
		foreach ($rows as $account => $row) {
			$amounts = [];
			foreach ($columns as $column) {
				$amounts[$column['key']] = ($row['amounts'][$column['key']] ?? 0.0);
				$rows[$account]['ranges'][$column['key']] = ['from' => $column['from'], 'to' => $column['to']];
			}

			$rows[$account]['amounts'] = $amounts;

			$rows[$account]['differences'] = $this->differences(amounts: $rows[$account]['amounts']);
		}

		return array_values($rows);
	}//end section()

	/**
	 * One total across the columns, with its differences.
	 *
	 * @param array<string, array<string, mixed>> $statements Statement per column key.
	 * @param string                              $key        revenue, expense or result.
	 *
	 * @return array<string, array<string, float>> amounts and differences.
	 */
	private function totals(array $statements, string $key): array {
		$amounts = [];
		foreach ($statements as $column => $statement) {
			$amounts[$column] = (float)$statement['totals'][$key];
		}

		return ['amounts' => $amounts, 'differences' => $this->differences(amounts: $amounts)];
	}//end totals()

	/**
	 * The chosen amount minus each compared amount.
	 *
	 * @param array<string, float> $amounts Amount per column key, `current` first.
	 *
	 * @return array<string, float> Difference per comparison key.
	 */
	private function differences(array $amounts): array {
		$differences = [];
		foreach ($amounts as $column => $amount) {
			if ($column !== 'current') {
				$differences[$column] = round(((float)$amounts['current'] - (float)$amount), 2);
			}
		}

		return $differences;
	}//end differences()
}//end class
