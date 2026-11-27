<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Reporting\Statement
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-reporting-financial-statements/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Statement;

use InvalidArgumentException;
use OCA\Shillinq\Reporting\ProfitLossComparison;
use OCA\Shillinq\Reporting\StatementCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Every figure opens the lines behind it (REQ-RFS-003).
 *
 * @covers \OCA\Shillinq\Reporting\StatementCalculator
 * @covers \OCA\Shillinq\Reporting\ProfitLossComparison
 * @uses   \OCA\Shillinq\Reporting\StatementPeriods
 * @uses   \OCA\Shillinq\Service\FiscalYearContextService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class StatementAccountLinesTest extends TestCase {

	/**
	 * The seed with the 2026 rent posted in period 2026-Q2 and a trial
	 * balance line for 4100 in that period.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function rows(): array {
		$rows = VanDijkLedger::rows();
		foreach ($rows['GLLine'] as $index => $line) {
			if ($line['transactionId'] === 'tx-rent-2026' || $line['transactionId'] === 'tx-wages-2026') {
				$rows['GLLine'][$index]['periodId'] = '2026-Q2';
			}
		}

		$rows['TrialBalanceLine'] = [
			['id' => 'tb-4100-q2', 'administrationId' => VanDijkLedger::ADM, 'periodId' => '2026-Q2', 'accountNumber' => '4100'],
			['id' => 'tb-other', 'administrationId' => 'adm-other', 'periodId' => '2026-Q2', 'accountNumber' => '4100'],
		];

		return $rows;
	}

	/**
	 * A bookkeeper checks the housing costs: the profit and loss figure of
	 * EUR 36,000 on 4100 opens the 4100 lines of the range, adding up to it,
	 * each naming its transaction.
	 *
	 * @return void
	 */
	public function testTheHousingCostsFigureOpensItsLines(): void {
		$calculator = VanDijkLedger::calculator(test: $this, rows: self::rows());
		$row = $calculator->resultsBetween(administrationId: VanDijkLedger::ADM, from: '2026-01-01', to: '2026-09-30')['expense'][1];

		$found = $calculator->accountLines(administrationId: VanDijkLedger::ADM, key: $row['linesKey']);

		self::assertSame(
			expected: ['4100', '2026-01-01', '2026-09-30', null],
			actual: [$found['accountNumber'], $found['from'], $found['to'], $found['periodId']]
		);
		self::assertSame(expected: $row['amount'], actual: $found['total']);
		self::assertSame(expected: 36000.0, actual: $found['total']);
		self::assertSame(expected: ['tx-rent-2026'], actual: array_column($found['lines'], 'transactionId'));
		self::assertSame(expected: '4100 Huisvesting', actual: $found['lines'][0]['account']);
	}

	/**
	 * Revenue is shown credit positive; its lines add up to the figure the
	 * same way, and a line after the range stays out.
	 *
	 * @return void
	 */
	public function testRevenueLinesAddUpCreditPositive(): void {
		$calculator = VanDijkLedger::calculator(test: $this, rows: self::rows());
		$key = StatementCalculator::accountLinesKey(account: '8000', from: '2026-01-01', to: '2026-09-30');

		$found = $calculator->accountLines(administrationId: VanDijkLedger::ADM, key: $key);

		self::assertSame(expected: 180000.0, actual: $found['total']);
		self::assertSame(expected: ['tx-sale-2026'], actual: array_column($found['lines'], 'transactionId'));
	}

	/**
	 * A balance sheet figure keys the fiscal year start to the as-of date,
	 * and an asset account's lines read debit positive like the figure.
	 *
	 * @return void
	 */
	public function testABalanceSheetFigureOpensTheYearsLines(): void {
		$calculator = VanDijkLedger::calculator(test: $this, rows: self::rows());
		$assets = $calculator->balancesAsOf(administrationId: VanDijkLedger::ADM, asOf: '2026-09-30')['assets'];
		$bank = $assets[array_search('1100', array_column($assets, 'accountNumber'), true)];

		self::assertSame(expected: '1100~2026-01-01~2026-09-30', actual: $bank['linesKey']);
		$found = $calculator->accountLines(administrationId: VanDijkLedger::ADM, key: $bank['linesKey']);
		self::assertSame(expected: ['tx-sale-2026', 'tx-wages-2026'], actual: array_column($found['lines'], 'transactionId'));
		self::assertSame(expected: 84000.0, actual: $found['total']);
	}

	/**
	 * The running result line names no account and so opens no lines.
	 *
	 * @return void
	 */
	public function testTheRunningResultLineCarriesNoKey(): void {
		$calculator = VanDijkLedger::calculator(test: $this, rows: self::rows());
		$equity = $calculator->balancesAsOf(administrationId: VanDijkLedger::ADM, asOf: '2026-09-30')['equity'];
		$running = $equity[array_search(StatementCalculator::RUNNING_RESULT_LABEL, array_column($equity, 'label'), true)];

		self::assertArrayNotHasKey(key: 'linesKey', array: $running);
	}

	/**
	 * A trial balance line opens its account's lines in its period, debit
	 * positive like the trial balance.
	 *
	 * @return void
	 */
	public function testATrialBalanceLineOpensItsPeriodsLines(): void {
		$calculator = VanDijkLedger::calculator(test: $this, rows: self::rows());
		$found = $calculator->accountLines(administrationId: VanDijkLedger::ADM, key: 'tb-4100-q2');

		self::assertSame(expected: ['4100', '2026-Q2', null, null], actual: [$found['accountNumber'], $found['periodId'], $found['from'], $found['to']]);
		self::assertSame(expected: ['tx-rent-2026'], actual: array_column($found['lines'], 'transactionId'));
		self::assertSame(expected: 36000.0, actual: $found['total']);
	}

	/**
	 * Another administration's trial balance line is not found, and a
	 * malformed key or range is refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$calculator = VanDijkLedger::calculator(test: $this, rows: self::rows());
		$refusals = [];
		foreach (['tb-other', '4100~2026-09-30~2026-01-01', '4100~2026-1-1~2026-09-30', 'a b'] as $key) {
			try {
				$calculator->accountLines(administrationId: VanDijkLedger::ADM, key: $key);
				$refusals[] = null;
			} catch (InvalidArgumentException $e) {
				$refusals[] = $e->getMessage();
			}
		}

		self::assertSame(
			expected: [
				'No such trial balance line.',
				'to must not be before from.',
				'key must be account~from~to or a trial balance line id.',
				'key must be account~from~to or a trial balance line id.',
			],
			actual: $refusals
		);
	}

	/**
	 * The compared profit and loss keys each row on the current column's range.
	 *
	 * @return void
	 */
	public function testTheComparedRowsKeyTheCurrentRange(): void {
		$comparison = new ProfitLossComparison(calculator: VanDijkLedger::calculator(test: $this, rows: self::rows()));
		$statement = $comparison->compare(administrationId: VanDijkLedger::ADM, from: '2026-01-01', to: '2026-09-30', comparisons: ['previous-year']);

		self::assertSame(expected: ['4000~2026-01-01~2026-09-30', '4100~2026-01-01~2026-09-30'], actual: array_column($statement['expense'], 'linesKey'));
	}
}
