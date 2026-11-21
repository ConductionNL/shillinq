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
 * @spec openspec/changes/reporting-financial-statements/tasks.md#task-2.3
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
		$calculator = VanDijkLedger::calculator($this, self::rows());
		$row = $calculator->resultsBetween(VanDijkLedger::ADM, '2026-01-01', '2026-09-30')['expense'][1];

		$found = $calculator->accountLines(VanDijkLedger::ADM, $row['linesKey']);

		self::assertSame(['4100', '2026-01-01', '2026-09-30', null], [$found['accountNumber'], $found['from'], $found['to'], $found['periodId']]);
		self::assertSame($row['amount'], $found['total']);
		self::assertSame(36000.0, $found['total']);
		self::assertSame(['tx-rent-2026'], array_column($found['lines'], 'transactionId'));
		self::assertSame('4100 Huisvesting', $found['lines'][0]['account']);
	}

	/**
	 * Revenue is shown credit positive; its lines add up to the figure the
	 * same way, and a line after the range stays out.
	 *
	 * @return void
	 */
	public function testRevenueLinesAddUpCreditPositive(): void {
		$calculator = VanDijkLedger::calculator($this, self::rows());

		$found = $calculator->accountLines(VanDijkLedger::ADM, StatementCalculator::accountLinesKey('8000', '2026-01-01', '2026-09-30'));

		self::assertSame(180000.0, $found['total']);
		self::assertSame(['tx-sale-2026'], array_column($found['lines'], 'transactionId'));
	}

	/**
	 * A balance sheet figure keys the fiscal year start to the as-of date,
	 * and an asset account's lines read debit positive like the figure.
	 *
	 * @return void
	 */
	public function testABalanceSheetFigureOpensTheYearsLines(): void {
		$calculator = VanDijkLedger::calculator($this, self::rows());
		$assets = $calculator->balancesAsOf(VanDijkLedger::ADM, '2026-09-30')['assets'];
		$bank = $assets[array_search('1100', array_column($assets, 'accountNumber'), true)];

		self::assertSame('1100~2026-01-01~2026-09-30', $bank['linesKey']);
		$found = $calculator->accountLines(VanDijkLedger::ADM, $bank['linesKey']);
		self::assertSame(['tx-sale-2026', 'tx-wages-2026'], array_column($found['lines'], 'transactionId'));
		self::assertSame(84000.0, $found['total']);
	}

	/**
	 * The running result line names no account and so opens no lines.
	 *
	 * @return void
	 */
	public function testTheRunningResultLineCarriesNoKey(): void {
		$equity = VanDijkLedger::calculator($this, self::rows())->balancesAsOf(VanDijkLedger::ADM, '2026-09-30')['equity'];
		$running = $equity[array_search(StatementCalculator::RUNNING_RESULT_LABEL, array_column($equity, 'label'), true)];

		self::assertArrayNotHasKey('linesKey', $running);
	}

	/**
	 * A trial balance line opens its account's lines in its period, debit
	 * positive like the trial balance.
	 *
	 * @return void
	 */
	public function testATrialBalanceLineOpensItsPeriodsLines(): void {
		$found = VanDijkLedger::calculator($this, self::rows())->accountLines(VanDijkLedger::ADM, 'tb-4100-q2');

		self::assertSame(['4100', '2026-Q2', null, null], [$found['accountNumber'], $found['periodId'], $found['from'], $found['to']]);
		self::assertSame(['tx-rent-2026'], array_column($found['lines'], 'transactionId'));
		self::assertSame(36000.0, $found['total']);
	}

	/**
	 * Another administration's trial balance line is not found, and a
	 * malformed key or range is refused.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$calculator = VanDijkLedger::calculator($this, self::rows());
		$refusals = [];
		foreach (['tb-other', '4100~2026-09-30~2026-01-01', '4100~2026-1-1~2026-09-30', 'a b'] as $key) {
			try {
				$calculator->accountLines(VanDijkLedger::ADM, $key);
				$refusals[] = null;
			} catch (InvalidArgumentException $e) {
				$refusals[] = $e->getMessage();
			}
		}

		self::assertSame(
			[
				'No such trial balance line.',
				'to must not be before from.',
				'key must be account~from~to or a trial balance line id.',
				'key must be account~from~to or a trial balance line id.',
			],
			$refusals
		);
	}

	/**
	 * The compared profit and loss keys each row on the current column's range.
	 *
	 * @return void
	 */
	public function testTheComparedRowsKeyTheCurrentRange(): void {
		$calculator = VanDijkLedger::calculator($this, self::rows());
		$statement = (new ProfitLossComparison($calculator))->compare(VanDijkLedger::ADM, '2026-01-01', '2026-09-30', ['previous-year']);

		self::assertSame(['4000~2026-01-01~2026-09-30', '4100~2026-01-01~2026-09-30'], array_column($statement['expense'], 'linesKey'));
	}
}
