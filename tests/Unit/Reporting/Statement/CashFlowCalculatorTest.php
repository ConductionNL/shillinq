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
 * @spec openspec/changes/reporting-cash-flow-statement
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Statement;

use OCA\Shillinq\Reporting\CashFlowCalculator;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;

/**
 * The cash flow statement by the indirect method (REQ-RCF-002), over the
 * design's seed: Adviesbureau Van Dijk, 1 January to 30 September 2026.
 *
 * @covers \OCA\Shillinq\Reporting\CashFlowCalculator
 * @uses   \OCA\Shillinq\Reporting\StatementCalculator
 * @uses   \OCA\Shillinq\Service\FiscalYearContextService
 */
class CashFlowCalculatorTest extends TestCase {

	private const ADM = 'adm-adviesbureau-van-dijk';

	/**
	 * Transactions built so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $transactions = [];

	/**
	 * Lines built so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $lines = [];

	/**
	 * Add a posted transaction with its lines.
	 *
	 * @param string                                            $id          Transaction id.
	 * @param string                                            $date        Posting date.
	 * @param array<int, array{0: string, 1: string, 2: float}> $lines       Account, side, amount.
	 * @param string                                            $journalType The journal type.
	 *
	 * @return void
	 */
	private function post(string $id, string $date, array $lines, string $journalType = 'manual'): void {
		$this->transactions[] = ['id' => $id, 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => $date, 'journalType' => $journalType];
		foreach ($lines as [$account, $side, $amount]) {
			$this->lines[] = [
				'administrationId' => self::ADM,
				'transactionId' => $id,
				'accountNumber' => $account,
				'side' => $side,
				'amount' => $amount,
				'signedAmount' => ($side === 'credit' ? $amount : -$amount),
				'countsInResult' => true,
			];
		}
	}

	/**
	 * The seed: cash 20,000 at the start of 2026, then result 48,000,
	 * depreciation 6,000, debtors up 10,000, creditors up 4,000, a laptop fleet
	 * of 12,000 and a loan repayment of 8,000.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function seed(): array {
		$this->post('tx-capital', '2025-01-02', [['1000', 'debit', 10000.0], ['0500', 'credit', 10000.0]]);
		$this->post('tx-loan', '2025-01-03', [['1000', 'debit', 30000.0], ['0700', 'credit', 30000.0]]);
		$this->post('tx-fit-out', '2025-02-01', [['0100', 'debit', 20000.0], ['1000', 'credit', 20000.0]]);

		$this->post('tx-sales', '2026-03-31', [['1300', 'debit', 100000.0], ['8000', 'credit', 100000.0]]);
		$this->post('tx-receipts', '2026-06-30', [['1000', 'debit', 90000.0], ['1300', 'credit', 90000.0]]);
		$this->post('tx-costs', '2026-04-30', [['4000', 'debit', 46000.0], ['1600', 'credit', 46000.0]]);
		$this->post('tx-payments', '2026-07-31', [['1600', 'debit', 42000.0], ['1000', 'credit', 42000.0]]);
		$this->post('tx-depreciation', '2026-09-30', [['4800', 'debit', 6000.0], ['0110', 'credit', 6000.0]]);
		$this->post('tx-laptops', '2026-05-15', [['0100', 'debit', 12000.0], ['1000', 'credit', 12000.0]]);
		$this->post('tx-repayment', '2026-08-01', [['0700', 'debit', 8000.0], ['1000', 'credit', 8000.0]]);
		$this->post('tx-after', '2026-10-05', [['1000', 'debit', 900.0], ['8000', 'credit', 900.0]]);

		return [
			'Administration' => [['id' => self::ADM, 'administrationCode' => 'AVD', 'fiscalYearStartMonth' => 1, 'fiscalYearStartDay' => 1]],
			'FiscalYear' => [],
			'Account' => [
				['id' => 'acc-0100', 'administrationId' => self::ADM, 'accountNumber' => '0100', 'name' => 'Inventaris', 'accountType' => 'assets', 'cashFlowCategory' => 'fixed-assets'],
				['id' => 'acc-0110', 'administrationId' => self::ADM, 'accountNumber' => '0110', 'name' => 'Afschrijving inventaris', 'accountType' => 'assets', 'cashFlowCategory' => 'fixed-assets'],
				['id' => 'acc-0500', 'administrationId' => self::ADM, 'accountNumber' => '0500', 'name' => 'Eigen vermogen', 'accountType' => 'equity', 'cashFlowCategory' => 'equity'],
				['id' => 'acc-0700', 'administrationId' => self::ADM, 'accountNumber' => '0700', 'name' => 'Lening', 'accountType' => 'liabilities', 'cashFlowCategory' => 'financing'],
				['id' => 'acc-1000', 'administrationId' => self::ADM, 'accountNumber' => '1000', 'name' => 'Bank', 'accountType' => 'assets', 'cashFlowCategory' => 'cash'],
				['id' => 'acc-1300', 'administrationId' => self::ADM, 'accountNumber' => '1300', 'name' => 'Debiteuren', 'accountType' => 'assets', 'cashFlowCategory' => 'working-capital'],
				['id' => 'acc-1600', 'administrationId' => self::ADM, 'accountNumber' => '1600', 'name' => 'Crediteuren', 'accountType' => 'liabilities', 'cashFlowCategory' => 'working-capital'],
				['id' => 'acc-4000', 'administrationId' => self::ADM, 'accountNumber' => '4000', 'name' => 'Kosten', 'accountType' => 'expenses'],
				['id' => 'acc-4800', 'administrationId' => self::ADM, 'accountNumber' => '4800', 'name' => 'Afschrijvingskosten', 'accountType' => 'expenses'],
				['id' => 'acc-8000', 'administrationId' => self::ADM, 'accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue'],
			],
			'FixedAsset' => [
				['administrationId' => self::ADM, 'assetNumber' => 'INV-01', 'assetAccountNumber' => '0100', 'accumulatedDepreciationAccountNumber' => '0110'],
			],
		];
	}

	/**
	 * A calculator over the rows built so far plus the given base rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $base The non-ledger rows.
	 *
	 * @return CashFlowCalculator
	 */
	private function calculator(array $base): CashFlowCalculator {
		$rows = $base + ['GLTransaction' => $this->transactions, 'GLLine' => $this->lines];
		$location = $this->getMockBuilder(RegisterLocation::class)->disableOriginalConstructor()->getMock();
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new CashFlowCalculator(
			VanDijkLedger::calculator($this, $rows),
			new InMemoryObjectServiceStub($rows),
			$location
		);
	}

	/**
	 * Amount per label of a section's rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, float>
	 */
	private static function amounts(array $rows): array {
		return array_column($rows, 'amount', 'label');
	}

	/**
	 * September year to date: operating 48,000, investing -12,000, financing
	 * -8,000, and the change in cash 28,000 with no unclassified line.
	 *
	 * @return void
	 */
	public function testSeptemberYearToDateReconcilesToTheChangeInCash(): void {
		$statement = $this->calculator($this->seed())->between(self::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(
			[
				'operating' => 48000.0,
				'investing' => -12000.0,
				'financing' => -8000.0,
				'unclassified' => 0.0,
				'netCashFlow' => 28000.0,
				'cashChange' => 28000.0,
				'difference' => 0.0,
			],
			$statement['totals']
		);
		self::assertSame(['opening' => 20000.0, 'change' => 28000.0, 'closing' => 48000.0], $statement['cash']);
		self::assertSame('indirect', $statement['method']);
		self::assertSame([], $statement['unclassified']['rows']);
		self::assertSame(
			[
				['label' => 'Kasstroom uit operationele activiteiten', 'amount' => 48000.0],
				['label' => 'Kasstroom uit investeringsactiviteiten', 'amount' => -12000.0],
				['label' => 'Kasstroom uit financieringsactiviteiten', 'amount' => -8000.0],
				['label' => 'Mutatie liquide middelen', 'amount' => 28000.0],
			],
			$statement['summary']
		);
	}

	/**
	 * Operating starts at the result, adds back depreciation, then the working
	 * capital: debtors up is cash not received, creditors up is cash not paid.
	 *
	 * @return void
	 */
	public function testOperatingIsResultPlusDepreciationPlusWorkingCapital(): void {
		$statement = $this->calculator($this->seed())->between(self::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(
			['Resultaat' => 48000.0, 'Afschrijvingen' => 6000.0, '1300 Debiteuren' => -10000.0, '1600 Crediteuren' => 4000.0],
			self::amounts($statement['operating']['rows'])
		);
		self::assertSame(['0100 Inventaris' => -12000.0], self::amounts($statement['investing']['rows']));
		self::assertSame(['0700 Lening' => -8000.0], self::amounts($statement['financing']['rows']));
	}

	/**
	 * Each account row names its account and the range behind the figure, so
	 * the page can open the lines (REQ-RCF-003).
	 *
	 * @return void
	 */
	public function testAnAccountRowCarriesItsAccountAndRange(): void {
		$statement = $this->calculator($this->seed())->between(self::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(
			['accountNumber' => '0100', 'accountId' => 'acc-0100', 'label' => '0100 Inventaris', 'amount' => -12000.0, 'from' => '2026-01-01', 'to' => '2026-09-30'],
			$statement['investing']['rows'][0]
		);
	}

	/**
	 * A balance account with a movement of 500 and no category shows as
	 * "Niet ingedeeld" naming that account, and the statement still adds up.
	 *
	 * @return void
	 */
	public function testAnUnclassifiedAccountShowsAsItsOwnLine(): void {
		$base = $this->seed();
		$base['Account'][] = ['id' => 'acc-1900', 'administrationId' => self::ADM, 'accountNumber' => '1900', 'name' => 'Overige vorderingen', 'accountType' => 'assets'];
		$this->post('tx-refund', '2026-09-01', [['1000', 'debit', 500.0], ['1900', 'credit', 500.0]]);

		$statement = $this->calculator($base)->between(self::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(['1900 Overige vorderingen' => 500.0], self::amounts($statement['unclassified']['rows']));
		self::assertSame(500.0, $statement['unclassified']['total']);
		self::assertSame(28500.0, $statement['totals']['cashChange']);
		self::assertSame(0.0, $statement['totals']['difference']);
		self::assertSame(['Niet ingedeeld', 500.0], [$statement['summary'][3]['label'], $statement['summary'][3]['amount']]);
	}

	/**
	 * A year-closing entry moves no cash: closing the result into equity
	 * inside the period leaves operating and financing as they were.
	 *
	 * @return void
	 */
	public function testAClosingEntryInThePeriodIsLeftOut(): void {
		$base = $this->seed();
		$this->post(
			'tx-close',
			'2026-09-30',
			[['8000', 'debit', 100000.0], ['4000', 'credit', 46000.0], ['4800', 'credit', 6000.0], ['0500', 'credit', 48000.0]],
			'closing'
		);

		$statement = $this->calculator($base)->between(self::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(48000.0, $statement['totals']['operating']);
		self::assertSame(-8000.0, $statement['totals']['financing']);
		self::assertSame(0.0, $statement['totals']['difference']);
	}

	/**
	 * Without a fixed asset naming the accumulated depreciation account there
	 * is nothing to add back: the credit stays an investing movement.
	 *
	 * @return void
	 */
	public function testNoDepreciationIsAddedBackWithoutAFixedAssetNamingTheAccount(): void {
		$base = $this->seed();
		$base['FixedAsset'] = [];

		$statement = $this->calculator($base)->between(self::ADM, '2026-01-01', '2026-09-30');

		self::assertSame(['Resultaat' => 48000.0, '1300 Debiteuren' => -10000.0, '1600 Crediteuren' => 4000.0], self::amounts($statement['operating']['rows']));
		self::assertSame(['0100 Inventaris' => -12000.0, '0110 Afschrijving inventaris' => 6000.0], self::amounts($statement['investing']['rows']));
		self::assertSame(28000.0, $statement['totals']['netCashFlow']);
	}

	/**
	 * Postings after the period stay out; the opening cash carries everything before it.
	 *
	 * @return void
	 */
	public function testTheOpeningCashIsTheBalanceTheDayBefore(): void {
		$statement = $this->calculator($this->seed())->between(self::ADM, '2026-01-01', '2026-12-31');

		self::assertSame(['opening' => 20000.0, 'change' => 28900.0, 'closing' => 48900.0], $statement['cash']);
		self::assertSame(48900.0, $statement['totals']['operating']);
	}
}
