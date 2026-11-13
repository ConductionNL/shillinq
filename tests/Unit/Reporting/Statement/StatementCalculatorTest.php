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
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Statement;

use OCA\Shillinq\Reporting\StatementCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Statement figures from posted lines (REQ-RFS-001, REQ-RFS-002).
 *
 * @covers \OCA\Shillinq\Reporting\StatementCalculator
 * @uses   \OCA\Shillinq\Service\FiscalYearContextService
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class StatementCalculatorTest extends TestCase {

	/**
	 * Amount per account number of a statement section.
	 *
	 * @param array<int, array<string, mixed>> $rows The section.
	 *
	 * @return array<string, float>
	 */
	private static function amounts(array $rows): array {
		return array_column($rows, 'amount', 'label');
	}

	/**
	 * September year to date: revenue 180,000, costs 132,000, result 48,000;
	 * the October posting, the draft, the reversal and the other administration stay out.
	 *
	 * @return void
	 */
	public function testResultsBetweenSumsPostedLinesOfTheRange(): void {
		$statement = VanDijkLedger::calculator($this, VanDijkLedger::rows())->resultsBetween(VanDijkLedger::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(['8000 Omzet' => 180000.0], self::amounts($statement['revenue']));
		self::assertSame(['4000 Lonen' => 96000.0, '4100 Huisvesting' => 36000.0], self::amounts($statement['expense']));
		self::assertSame(['revenue' => 180000.0, 'expense' => 132000.0, 'result' => 48000.0], $statement['totals']);
		self::assertSame(
			['accountNumber' => '4100', 'label' => '4100 Huisvesting', 'amount' => 36000.0, 'from' => '2026-01-01', 'to' => '2026-09-30'],
			$statement['expense'][1]
		);
	}

	/**
	 * The same period last year gives the comparison figures of the seed.
	 *
	 * @return void
	 */
	public function testTheSamePeriodLastYear(): void {
		$statement = VanDijkLedger::calculator($this, VanDijkLedger::rows())->resultsBetween(VanDijkLedger::ADM, '2025-01-01', '2025-09-30');

		self::assertSame(['revenue' => 150000.0, 'expense' => 120000.0, 'result' => 30000.0], $statement['totals']);
	}

	/**
	 * A closed year's whole range nets its accounts to zero through the
	 * closing entry, so no account shows.
	 *
	 * @return void
	 */
	public function testAClosedYearIncludingItsClosingEntryShowsNoAccounts(): void {
		$statement = VanDijkLedger::calculator($this, VanDijkLedger::rows())->resultsBetween(VanDijkLedger::ADM, '2025-01-01', '2025-12-31');

		self::assertSame([], $statement['revenue']);
		self::assertSame([], $statement['expense']);
		self::assertSame(0.0, $statement['totals']['result']);
	}

	/**
	 * An account of an unknown type goes where its balance puts it.
	 *
	 * @return void
	 */
	public function testAnUntypedResultAccountIsPlacedByItsSign(): void {
		$rows = VanDijkLedger::rows();
		$rows['GLTransaction'][] = ['id' => 'tx-misc', 'administrationId' => VanDijkLedger::ADM, 'state' => 'posted', 'postingDate' => '2026-06-01'];
		$rows['GLLine'][] = ['administrationId' => VanDijkLedger::ADM, 'transactionId' => 'tx-misc', 'accountNumber' => '9100', 'side' => 'debit', 'amount' => 250.0, 'accountClass' => 'pnl', 'countsInResult' => true];
		$rows['GLLine'][] = ['administrationId' => VanDijkLedger::ADM, 'transactionId' => 'tx-misc', 'accountNumber' => '9200', 'side' => 'credit', 'amount' => 75.0, 'accountClass' => 'pnl', 'countsInResult' => true];

		$statement = VanDijkLedger::calculator($this, $rows)->resultsBetween(VanDijkLedger::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(250.0, self::amounts($statement['expense'])['9100']);
		self::assertSame(75.0, self::amounts($statement['revenue'])['9200']);
		self::assertSame(47825.0, $statement['totals']['result']);
	}

	/**
	 * The balance sheet at the end of September carries the running result
	 * of 48,000 in equity and balances; stored balances are ignored.
	 *
	 * @return void
	 */
	public function testTheBalanceSheetCarriesTheRunningResultAndBalances(): void {
		$sheet = VanDijkLedger::calculator($this, VanDijkLedger::rows())->balancesAsOf(VanDijkLedger::ADM, '2026-09-30');

		self::assertSame(['1100 Bank' => 174000.0], self::amounts($sheet['assets']));
		self::assertSame(['1600 Crediteuren' => 36000.0], self::amounts($sheet['liabilities']));
		self::assertSame(['0500 Eigen vermogen' => 90000.0, StatementCalculator::RUNNING_RESULT_LABEL => 48000.0], self::amounts($sheet['equity']));
		self::assertSame(
			['assets' => 174000.0, 'liabilities' => 36000.0, 'equity' => 138000.0, 'liabilitiesAndEquity' => 174000.0, 'difference' => 0.0],
			$sheet['totals']
		);
		self::assertSame('2026-01-01', $sheet['fiscalYearStart']);
		self::assertFalse($sheet['yearClosed']);
		self::assertSame(['from' => '2026-01-01', 'to' => '2026-09-30'], ['from' => $sheet['assets'][0]['from'], 'to' => $sheet['assets'][0]['to']]);
	}

	/**
	 * A transaction posted after the date is left out of the balance sheet.
	 *
	 * @return void
	 */
	public function testAPostingAfterTheDateIsLeftOut(): void {
		$calculator = VanDijkLedger::calculator($this, VanDijkLedger::rows());

		self::assertSame(174000.0, $calculator->balancesAsOf(VanDijkLedger::ADM, '2026-09-30')['totals']['assets']);
		self::assertSame(179000.0, $calculator->balancesAsOf(VanDijkLedger::ADM, '2026-10-02')['totals']['assets']);
	}

	/**
	 * At the end of a closed year the closing entry has moved the result into
	 * equity, so there is no running result line.
	 *
	 * @return void
	 */
	public function testAClosedYearEndHasNoRunningResultLine(): void {
		$sheet = VanDijkLedger::calculator($this, VanDijkLedger::rows())->balancesAsOf(VanDijkLedger::ADM, '2025-12-31');

		self::assertTrue($sheet['yearClosed']);
		self::assertSame(['0500 Eigen vermogen' => 90000.0], self::amounts($sheet['equity']));
		self::assertSame(0.0, $sheet['totals']['difference']);
	}

	/**
	 * Inside a closed year, before its closing entry, the result to that date
	 * still shows, so the sheet balances.
	 *
	 * @return void
	 */
	public function testInsideAClosedYearBeforeItsClosingEntryTheResultStillShows(): void {
		$sheet = VanDijkLedger::calculator($this, VanDijkLedger::rows())->balancesAsOf(VanDijkLedger::ADM, '2025-09-30');

		self::assertTrue($sheet['yearClosed']);
		self::assertSame(['0500 Eigen vermogen' => 50000.0, StatementCalculator::RUNNING_RESULT_LABEL => 30000.0], self::amounts($sheet['equity']));
		self::assertSame(80000.0, $sheet['totals']['assets']);
		self::assertSame(0.0, $sheet['totals']['difference']);
	}

	/**
	 * An earlier year never closed shows its result as its own equity line.
	 *
	 * @return void
	 */
	public function testAnEarlierYearNeverClosedShowsAsItsOwnEquityLine(): void {
		$sheet = VanDijkLedger::calculator($this, VanDijkLedger::rows(closed2025: false))->balancesAsOf(VanDijkLedger::ADM, '2026-09-30');

		self::assertSame(
			['0500 Eigen vermogen' => 50000.0, StatementCalculator::EARLIER_RESULT_LABEL => 40000.0, StatementCalculator::RUNNING_RESULT_LABEL => 48000.0],
			self::amounts($sheet['equity'])
		);
		self::assertSame(0.0, $sheet['totals']['difference']);
	}

	/**
	 * A fiscal year starting in July starts the running result on 1 July.
	 *
	 * @return void
	 */
	public function testTheRunningResultStartsAtTheAdministrationsFiscalYear(): void {
		$rows = VanDijkLedger::rows();
		$rows['Administration'][0]['fiscalYearStartMonth'] = 7;
		$calculator = VanDijkLedger::calculator($this, $rows);

		self::assertSame('2026-07-01', $calculator->fiscalYearStart(VanDijkLedger::ADM, '2026-09-30'));
		self::assertSame('2025-07-01', $calculator->fiscalYearStart(VanDijkLedger::ADM, '2026-03-31'));
		self::assertSame('2026-01-01', $calculator->fiscalYearStart('adm-unknown', '2026-03-31'));
	}

	/**
	 * Unstamped class and amount fall back to the account type and the side.
	 *
	 * @return void
	 */
	public function testAnUnstampedLineFallsBackToAccountTypeAndSide(): void {
		$rows = VanDijkLedger::rows();
		$rows['GLTransaction'][] = ['id' => 'tx-old', 'administrationId' => VanDijkLedger::ADM, 'state' => 'posted', 'postingDate' => '2026-02-01'];
		$rows['GLLine'][] = ['administrationId' => VanDijkLedger::ADM, 'transactionId' => 'tx-old', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 100.0, 'countsInResult' => true];
		$rows['GLLine'][] = ['administrationId' => VanDijkLedger::ADM, 'transactionId' => 'tx-old', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 100.0, 'countsInResult' => true];

		$calculator = VanDijkLedger::calculator($this, $rows);

		self::assertSame(180100.0, $calculator->resultsBetween(VanDijkLedger::ADM, '2026-01-01', '2026-09-30')['totals']['revenue']);
		self::assertSame(174100.0, $calculator->balancesAsOf(VanDijkLedger::ADM, '2026-09-30')['totals']['assets']);
	}

	/**
	 * An administration without lines gets empty sections and a zero running result.
	 *
	 * @return void
	 */
	public function testAnEmptyAdministration(): void {
		$calculator = VanDijkLedger::calculator($this, VanDijkLedger::rows());

		$sheet = $calculator->balancesAsOf('adm-empty', '2026-09-30');
		self::assertSame([], $sheet['assets']);
		self::assertSame([StatementCalculator::RUNNING_RESULT_LABEL => 0.0], self::amounts($sheet['equity']));
		self::assertSame(0.0, $sheet['totals']['difference']);
		self::assertSame(['revenue' => 0.0, 'expense' => 0.0, 'result' => 0.0], $calculator->resultsBetween('adm-empty', '2026-01-01', '2026-09-30')['totals']);
	}
}
