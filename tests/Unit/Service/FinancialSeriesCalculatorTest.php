<?php

/**
 * Unit tests for FinancialSeriesCalculator.
 *
 * PHPUnit port of the client-side vitest suite
 * (tests/vitest/financialSeries.spec.js) covering the Financial overview
 * dashboard's computation layer: month bucketing, GL classification, margin
 * %, open AR/AP row mapping and the KPI aggregations. The fixtures and the
 * expected values are identical to the vitest suite so the server-side port
 * is proven to mirror the client exactly.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Shillinq\Service\FinancialSeriesCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure financial-series arithmetic (port of financialSeries.js).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FinancialSeriesCalculatorTest extends TestCase {

	/**
	 * Chart-of-accounts fixture (identical to the vitest ACCOUNTS fixture).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const ACCOUNTS = [
		['accountNumber' => '8000', 'name' => 'Omzet consultancy', 'accountType' => 'revenue'],
		['accountNumber' => '4000', 'name' => 'Personeelskosten', 'accountType' => 'expenses'],
		['accountNumber' => '1010', 'name' => 'Zakelijke rekening', 'accountType' => 'assets'],
		['accountNumber' => '1300', 'name' => 'Debiteuren', 'accountType' => 'assets'],
		['accountNumber' => '1600', 'name' => 'Crediteuren', 'accountType' => 'liabilities'],
		['accountNumber' => '2900', 'name' => 'Kas reserve', 'accountType' => 'assets'],
	];

	/**
	 * GLTransaction fixture (identical to the vitest TRANSACTIONS fixture).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const TRANSACTIONS = [
		['id' => 'tx-1', 'transactionNumber' => 'TX-1', 'postingDate' => '2026-03-05 00:00:00', 'state' => 'posted'],
		['id' => 'tx-2', 'transactionNumber' => 'TX-2', 'postingDate' => '2026-03-20 00:00:00', 'state' => 'posted'],
		['id' => 'tx-3', 'transactionNumber' => 'TX-3', 'postingDate' => '2026-04-02 00:00:00', 'state' => 'posted'],
		['id' => 'tx-draft', 'transactionNumber' => 'TX-D', 'postingDate' => '2026-03-09 00:00:00', 'state' => 'draft'],
	];

	/**
	 * GLLine fixture (identical to the vitest LINES fixture): March revenue
	 * 1000 + costs 400 (cash out), April customer payment 1000 (cash in),
	 * plus a draft-parent line and an orphan line that must be ignored.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private const LINES = [
		['transactionId' => 'tx-1', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 1000],
		['transactionId' => 'tx-1', 'accountNumber' => '1300', 'side' => 'debit', 'amount' => 1000],
		['transactionId' => 'tx-2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 400],
		['transactionId' => 'tx-2', 'accountNumber' => '1010', 'side' => 'credit', 'amount' => 400],
		['transactionId' => 'TX-3', 'accountNumber' => '1010', 'side' => 'debit', 'amount' => 1000],
		['transactionId' => 'TX-3', 'accountNumber' => '1300', 'side' => 'credit', 'amount' => 1000],
		['transactionId' => 'tx-draft', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 9999],
		['transactionId' => 'tx-missing', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 5555],
	];

	/**
	 * The calculator under test.
	 *
	 * @var FinancialSeriesCalculator
	 */
	private FinancialSeriesCalculator $calculator;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new FinancialSeriesCalculator();

	}//end setUp()

	/**
	 * monthKey parses ISO and SQL-ish dates and rejects garbage.
	 *
	 * @return void
	 */
	public function testMonthKeyParsesIsoAndSqlDatesAndRejectsGarbage(): void {
		$this->assertSame('2026-03', $this->calculator->monthKey('2026-03-05'));
		$this->assertSame('2026-03', $this->calculator->monthKey('2026-03-05 12:30:00'));
		$this->assertSame('2026-03', $this->calculator->monthKey('2026-03-05T12:30:00Z'));
		$this->assertNull($this->calculator->monthKey('not a date'));
		$this->assertNull($this->calculator->monthKey(null));

	}//end testMonthKeyParsesIsoAndSqlDatesAndRejectsGarbage()

	/**
	 * lastMonths returns an ascending trailing window crossing year boundaries.
	 *
	 * @return void
	 */
	public function testLastMonthsReturnsAscendingTrailingWindowCrossingYearBoundaries(): void {
		$this->assertSame(
			['2025-11', '2025-12', '2026-01'],
			$this->calculator->lastMonths(3, new DateTimeImmutable('2026-01-15'))
		);

	}//end testLastMonthsReturnsAscendingTrailingWindowCrossingYearBoundaries()

	/**
	 * monthsInRange spans whole months, tolerates time suffixes, rejects
	 * garbage and caps at 60 buckets.
	 *
	 * @return void
	 */
	public function testMonthsInRangeSpansMonthsAndRejectsGarbage(): void {
		$this->assertSame(
			['2026-02', '2026-03', '2026-04'],
			$this->calculator->monthsInRange('2026-02-15', '2026-04-02')
		);
		$this->assertSame(
			['2025-12', '2026-01'],
			$this->calculator->monthsInRange('2025-12-31T23:00:00Z', '2026-01-01')
		);
		$this->assertSame([], $this->calculator->monthsInRange('', '2026-01-01'));
		$this->assertSame([], $this->calculator->monthsInRange('garbage', '2026-01-01'));
		$this->assertSame([], $this->calculator->monthsInRange('2026-02-30', '2026-03-01'));
		$this->assertSame([], $this->calculator->monthsInRange('2026-05-01', '2026-01-01'));
		$this->assertCount(60, $this->calculator->monthsInRange('2000-01-01', '2026-01-01'));

	}//end testMonthsInRangeSpansMonthsAndRejectsGarbage()

	/**
	 * classifyAccounts buckets revenue, expenses and liquid assets: 1010 by
	 * number prefix, 2900 by "kas" name; 1300 (debtors) is NOT liquid.
	 *
	 * @return void
	 */
	public function testClassifyAccountsBucketsRevenueExpensesAndLiquidAssets(): void {
		$classes = $this->calculator->classifyAccounts(self::ACCOUNTS);

		$this->assertSame(['8000'], array_map('strval', array_keys($classes['revenue'])));
		$this->assertSame(['4000'], array_map('strval', array_keys($classes['expenses'])));

		$liquid = array_map('strval', array_keys($classes['liquid']));
		sort($liquid);
		$this->assertSame(['1010', '2900'], $liquid);

	}//end testClassifyAccountsBucketsRevenueExpensesAndLiquidAssets()

	/**
	 * postedLinesByMonth matches lines via object id and transactionNumber,
	 * posted parents only; draft-parent and orphan lines are dropped.
	 *
	 * @return void
	 */
	public function testPostedLinesByMonthMatchesViaIdAndTransactionNumberPostedOnly(): void {
		$buckets = $this->calculator->postedLinesByMonth(self::TRANSACTIONS, self::LINES);

		$this->assertCount(4, $buckets['2026-03']);
		$this->assertCount(2, $buckets['2026-04']);

		$all = array_merge(...array_values($buckets));
		$amounts = array_column($all, 'amount');
		$this->assertNotContains(9999, $amounts);
		$this->assertNotContains(5555, $amounts);

	}//end testPostedLinesByMonthMatchesViaIdAndTransactionNumberPostedOnly()

	/**
	 * signedAmount grows revenue on credit and expenses/liquid on debit.
	 *
	 * @return void
	 */
	public function testSignedAmountGrowsRevenueOnCreditAndExpensesLiquidOnDebit(): void {
		$this->assertSame(100.0, $this->calculator->signedAmount(['side' => 'credit', 'amount' => 100], 'revenue'));
		$this->assertSame(-100.0, $this->calculator->signedAmount(['side' => 'debit', 'amount' => 100], 'revenue'));
		$this->assertSame(100.0, $this->calculator->signedAmount(['side' => 'debit', 'amount' => 100], 'expenses'));
		$this->assertSame(100.0, $this->calculator->signedAmount(['side' => 'debit', 'amount' => 100], 'liquid'));
		$this->assertSame(-100.0, $this->calculator->signedAmount(['side' => 'credit', 'amount' => 100], 'liquid'));

	}//end testSignedAmountGrowsRevenueOnCreditAndExpensesLiquidOnDebit()

	/**
	 * monthlyFinancialSeries computes turnover, margin, margin% and cashflow.
	 *
	 * @return void
	 */
	public function testMonthlyFinancialSeriesComputesTurnoverMarginPctAndCashflow(): void {
		$series = $this->calculator->monthlyFinancialSeries(
			[
				'accounts' => self::ACCOUNTS,
				'transactions' => self::TRANSACTIONS,
				'lines' => self::LINES,
				'months' => ['2026-02', '2026-03', '2026-04'],
			]
		);

		$this->assertSame([0.0, 1000.0, 0.0], $series['revenue']);
		$this->assertSame([0.0, 400.0, 0.0], $series['costs']);
		$this->assertSame([0.0, 600.0, 0.0], $series['margin']);
		$this->assertSame([null, 60.0, null], $series['marginPct']);
		$this->assertSame([0.0, 0.0, 1000.0], $series['cashIn']);
		$this->assertSame([0.0, 400.0, 0.0], $series['cashOut']);
		$this->assertSame([0.0, -400.0, 1000.0], $series['cashNet']);

	}//end testMonthlyFinancialSeriesComputesTurnoverMarginPctAndCashflow()

	/**
	 * billableSeries splits on recognisedRate and computes the share.
	 *
	 * @return void
	 */
	public function testBillableSeriesSplitsOnRecognisedRateAndComputesShare(): void {
		$entries = [
			['date' => '2026-03-02', 'hours' => 6, 'recognisedRate' => 95],
			['date' => '2026-03-03', 'hours' => 2, 'recognisedRate' => 0],
			['date' => '2026-03-04', 'hours' => 2],
			['date' => '2026-04-01', 'hours' => 8, 'recognisedRate' => 110],
		];

		$series = $this->calculator->billableSeries($entries, ['2026-03', '2026-04', '2026-05']);

		$this->assertSame([6.0, 8.0, 0.0], $series['billable']);
		$this->assertSame([4.0, 0.0, 0.0], $series['nonBillable']);
		$this->assertSame([60.0, 100.0, null], $series['pct']);

	}//end testBillableSeriesSplitsOnRecognisedRateAndComputesShare()

	/**
	 * openArRows filters open states, resolves names, flags overdue and
	 * sorts by due date.
	 *
	 * @return void
	 */
	public function testOpenArRowsFiltersOpenStatesResolvesNamesFlagsOverdueSortsByDueDate(): void {
		$now = new DateTimeImmutable('2026-06-12');
		$invoices = [
			[
				'id' => 'a',
				'invoiceNumber' => 'F-3',
				'customerId' => 'C1',
				'dueDate' => '2026-07-01',
				'grossAmount' => 300,
				'lifecycleState' => 'issued',
			],
			[
				'id' => 'b',
				'invoiceNumber' => 'F-1',
				'customerId' => 'C1',
				'dueDate' => '2026-05-01',
				'grossAmount' => 100,
				'lifecycleState' => 'overdue',
			],
			[
				'id' => 'c',
				'invoiceNumber' => 'F-2',
				'customerId' => 'C2',
				'dueDate' => '2026-06-01',
				'grossAmount' => 200,
				'lifecycleState' => 'issued',
			],
			[
				'id' => 'd',
				'invoiceNumber' => 'F-0',
				'customerId' => 'C1',
				'dueDate' => '2026-01-01',
				'grossAmount' => 999,
				'lifecycleState' => 'paid',
			],
		];

		$customers = [
			['customerId' => 'C1', 'legalName' => 'Acme BV'],
		];

		$rows = $this->calculator->openArRows($invoices, $customers, $now);

		$this->assertSame(['F-1', 'F-2', 'F-3'], array_column($rows, 'invoiceNumber'));
		$this->assertTrue($rows[0]['overdue']);
		// Issued but past due is flagged too.
		$this->assertTrue($rows[1]['overdue']);
		$this->assertFalse($rows[2]['overdue']);
		$this->assertSame('Acme BV', $rows[0]['party']);
		$this->assertSame('C2', $rows[1]['party']);

	}//end testOpenArRowsFiltersOpenStatesResolvesNamesFlagsOverdueSortsByDueDate()

	/**
	 * openApRows filters the open AP states including partially-paid.
	 *
	 * @return void
	 */
	public function testOpenApRowsFiltersOpenApStatesIncludingPartiallyPaid(): void {
		$now = new DateTimeImmutable('2026-06-12');
		$transactions = [
			[
				'id' => 'a',
				'invoiceNumber' => 'I-1',
				'vendorId' => 'V1',
				'dueDate' => '2026-06-20',
				'totalAmount' => 500,
				'state' => 'received',
			],
			[
				'id' => 'b',
				'invoiceNumber' => 'I-2',
				'vendorId' => 'V1',
				'dueDate' => '2026-06-01',
				'totalAmount' => 250,
				'state' => 'partially-paid',
			],
			[
				'id' => 'c',
				'invoiceNumber' => 'I-3',
				'vendorId' => 'V2',
				'dueDate' => '2026-05-01',
				'totalAmount' => 100,
				'state' => 'paid',
			],
			[
				'id' => 'd',
				'invoiceNumber' => 'I-4',
				'vendorId' => 'V2',
				'dueDate' => '2026-04-01',
				'totalAmount' => 100,
				'state' => 'voided',
			],
		];

		$rows = $this->calculator->openApRows(
			$transactions,
			[
				['vendorNumber' => 'V1', 'name' => 'Hosting BV'],
			],
			$now
		);

		$this->assertSame(['I-2', 'I-1'], array_column($rows, 'invoiceNumber'));
		$this->assertTrue($rows[0]['overdue']);
		$this->assertSame('Hosting BV', $rows[0]['party']);

	}//end testOpenApRowsFiltersOpenApStatesIncludingPartiallyPaid()

	/**
	 * computeKpis aggregates YTD turnover/margin, open AR/AP, billable share
	 * and the all-time cash position.
	 *
	 * @return void
	 */
	public function testComputeKpisAggregatesYtdOpenArApBillableShareAndCashPosition(): void {
		// April 2026.
		$now = new DateTimeImmutable('2026-04-15');

		$kpis = $this->calculator->computeKpis(
			[
				'accounts' => self::ACCOUNTS,
				'transactions' => self::TRANSACTIONS,
				'lines' => self::LINES,
				'arInvoices' => [
					['id' => 'a', 'dueDate' => '2026-05-01', 'grossAmount' => 1210, 'lifecycleState' => 'issued'],
				],
				'apTransactions' => [
					['id' => 'b', 'dueDate' => '2026-05-01', 'totalAmount' => 484, 'state' => 'received'],
				],
				'hourEntries' => [
					['date' => '2026-04-02', 'hours' => 30, 'recognisedRate' => 95],
					['date' => '2026-04-03', 'hours' => 10],
				],
			],
			$now
		);

		$this->assertSame(1000.0, $kpis['turnoverYtd']);
		$this->assertSame(600.0, $kpis['marginYtd']);
		$this->assertSame(60.0, $kpis['marginPctYtd']);
		$this->assertSame(1210.0, $kpis['openArAmount']);
		$this->assertSame(1, $kpis['openArCount']);
		$this->assertSame(484.0, $kpis['openApAmount']);
		$this->assertSame(1, $kpis['openApCount']);
		$this->assertSame(30.0, $kpis['billableHours']);
		$this->assertSame(75.0, $kpis['billablePct']);
		$this->assertSame(600.0, $kpis['cashPosition']);

	}//end testComputeKpisAggregatesYtdOpenArApBillableShareAndCashPosition()

	/**
	 * computeRangeKpis aggregates turnover/margin/billable over the given
	 * months and varies by range.
	 *
	 * @return void
	 */
	public function testComputeRangeKpisAggregatesOverGivenMonthsAndVariesByRange(): void {
		$hourEntries = [
			['date' => '2026-03-02', 'hours' => 6, 'recognisedRate' => 95],
			['date' => '2026-03-03', 'hours' => 2, 'recognisedRate' => 0],
			['date' => '2026-04-01', 'hours' => 8, 'recognisedRate' => 110],
		];

		$data = [
			'accounts' => self::ACCOUNTS,
			'transactions' => self::TRANSACTIONS,
			'lines' => self::LINES,
			'hourEntries' => $hourEntries,
		];

		// March + April: revenue 1000 (March), margin 600; billable 6+8 of 16 total.
		$wide = $this->calculator->computeRangeKpis($data, ['2026-03', '2026-04']);
		$this->assertSame(1000.0, $wide['turnover']);
		$this->assertSame(600.0, $wide['margin']);
		$this->assertSame(60.0, $wide['marginPct']);
		$this->assertSame(14.0, $wide['billableHours']);
		$this->assertSame(87.5, $wide['billablePct']);

		// April only: no posted revenue/cost so turnover 0, marginPct null;
		// proves the metrics shrink with a narrower range.
		$aprilOnly = $this->calculator->computeRangeKpis($data, ['2026-04']);
		$this->assertSame(0.0, $aprilOnly['turnover']);
		$this->assertSame(0.0, $aprilOnly['margin']);
		$this->assertNull($aprilOnly['marginPct']);
		$this->assertSame(8.0, $aprilOnly['billableHours']);
		$this->assertSame(100.0, $aprilOnly['billablePct']);

	}//end testComputeRangeKpisAggregatesOverGivenMonthsAndVariesByRange()
}//end class
