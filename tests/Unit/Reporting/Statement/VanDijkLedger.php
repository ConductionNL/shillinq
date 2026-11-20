<?php

/**
 * Seed ledger of Adviesbureau Van Dijk for the statement tests.
 *
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
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\FiscalYearContextService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The design's seed: fiscal year 2025 closed with its closing entry, 2026 open.
 *
 * 2025 January to September: revenue 150,000, costs 120,000; 2025 October to
 * December another 10,000 revenue, closed into equity on 2025-12-31.
 * 2026 to 30 September: revenue 180,000 on 8000, costs 132,000 of which
 * 36,000 on 4100 Huisvesting, so the running result is 48,000. A posting of
 * 2026-10-02, a draft, a reversed transaction and another administration's
 * line must never count.
 */
final class VanDijkLedger {

	public const ADM = 'adm-van-dijk';

	/**
	 * The register rows per schema.
	 *
	 * @param bool $closed2025 Whether 2025 carries its closing entry and is closed.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function rows(bool $closed2025 = true): array {
		$ledger = new self();
		$ledger->post('tx-capital', '2025-01-02', [['1100', 'debit', 50000.0], ['0500', 'credit', 50000.0]]);
		$ledger->post('tx-sale-2025', '2025-05-15', [['1100', 'debit', 150000.0], ['8000', 'credit', 150000.0]]);
		$ledger->post('tx-wages-2025', '2025-05-15', [['4000', 'debit', 90000.0], ['1100', 'credit', 90000.0]]);
		$ledger->post('tx-rent-2025', '2025-05-15', [['4100', 'debit', 30000.0], ['1100', 'credit', 30000.0]]);
		$ledger->post('tx-sale-2025-q4', '2025-11-15', [['1100', 'debit', 10000.0], ['8000', 'credit', 10000.0]]);
		if ($closed2025 === true) {
			$ledger->post(
				'tx-closing-2025',
				'2025-12-31',
				[['8000', 'debit', 160000.0], ['4000', 'credit', 90000.0], ['4100', 'credit', 30000.0], ['0500', 'credit', 40000.0]]
			);
		}

		$ledger->post('tx-sale-2026', '2026-03-15', [['1100', 'debit', 180000.0], ['8000', 'credit', 180000.0]]);
		$ledger->post('tx-wages-2026', '2026-04-15', [['4000', 'debit', 96000.0], ['1100', 'credit', 96000.0]]);
		$ledger->post('tx-rent-2026', '2026-04-15', [['4100', 'debit', 36000.0], ['1600', 'credit', 36000.0]]);
		$ledger->post('tx-after-date', '2026-10-02', [['1100', 'debit', 5000.0], ['8000', 'credit', 5000.0]]);
		$ledger->post('tx-draft', '2026-05-01', [['1100', 'debit', 999.0], ['8000', 'credit', 999.0]], 'draft', null);
		$ledger->post('tx-reversed', '2026-05-02', [['1100', 'debit', 700.0], ['8000', 'credit', 700.0]], 'posted', false);
		$ledger->post('tx-other', '2026-05-03', [['1100', 'debit', 7000.0], ['8000', 'credit', 7000.0]], 'posted', true, 'adm-other');

		return [
			'Administration' => [['id' => self::ADM, 'administrationCode' => 'VDIJK', 'fiscalYearStartMonth' => 1, 'fiscalYearStartDay' => 1]],
			'FiscalYear' => [
				['administrationId' => self::ADM, 'yearNumber' => 2025, 'startDate' => '2025-01-01', 'endDate' => '2025-12-31', 'state' => ($closed2025 === true ? 'closed' : 'open')],
				['administrationId' => self::ADM, 'yearNumber' => 2026, 'startDate' => '2026-01-01', 'endDate' => '2026-12-31', 'state' => 'open'],
			],
			'Account' => [
				['administrationId' => self::ADM, 'accountNumber' => '0500', 'name' => 'Eigen vermogen', 'accountType' => 'equity', 'balance' => 1],
				['administrationId' => self::ADM, 'accountNumber' => '1100', 'name' => 'Bank', 'accountType' => 'assets', 'balance' => 999999],
				['administrationId' => self::ADM, 'accountNumber' => '1600', 'name' => 'Crediteuren', 'accountType' => 'liabilities'],
				['administrationId' => self::ADM, 'accountNumber' => '4000', 'name' => 'Lonen', 'accountType' => 'expenses'],
				['administrationId' => self::ADM, 'accountNumber' => '4100', 'name' => 'Huisvesting', 'accountType' => 'expenses'],
				['administrationId' => self::ADM, 'accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'balance' => 123],
			],
			'GLTransaction' => $ledger->transactions,
			'GLLine' => $ledger->lines,
		];
	}//end rows()

	/**
	 * A calculator over the rows.
	 *
	 * @param TestCase                                         $test The test, for its mocks.
	 * @param array<string, array<int, array<string, mixed>>> $rows The register rows.
	 *
	 * @return StatementCalculator
	 */
	public static function calculator(TestCase $test, array $rows): StatementCalculator {
		$location = $test->getMockBuilder(RegisterLocation::class)->disableOriginalConstructor()->getMock();
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new StatementCalculator(new InMemoryObjectServiceStub($rows), $location, self::fiscalYears(test: $test));
	}//end calculator()

	/**
	 * A fiscal year service; the calculator uses only its pure window methods.
	 *
	 * @param TestCase $test The test, for its mocks.
	 *
	 * @return FiscalYearContextService
	 */
	public static function fiscalYears(TestCase $test): FiscalYearContextService {
		return new FiscalYearContextService(
			$test->getMockBuilder(ContainerInterface::class)->getMock(),
			$test->getMockBuilder(AdministrationContextService::class)->disableOriginalConstructor()->getMock(),
			$test->getMockBuilder(IAppConfig::class)->getMock(),
			$test->getMockBuilder(LoggerInterface::class)->getMock()
		);
	}//end fiscalYears()

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
	 * Add a transaction with its lines, stamped as GlLineResultStamps stamps them.
	 *
	 * @param string                                  $id     Transaction id.
	 * @param string                                  $date   Posting date.
	 * @param array<int, array{0: string, 1: string, 2: float}> $lines  Account, side, amount.
	 * @param string                                  $state  Transaction state.
	 * @param bool|null                               $counts The countsInResult stamp.
	 * @param string                                  $adm    Administration.
	 *
	 * @return void
	 */
	private function post(string $id, string $date, array $lines, string $state = 'posted', ?bool $counts = true, string $adm = self::ADM): void {
		$this->transactions[] = ['id' => $id, 'administrationId' => $adm, 'state' => $state, 'postingDate' => $date];
		foreach ($lines as [$account, $side, $amount]) {
			$this->lines[] = [
				'administrationId' => $adm,
				'transactionId' => $id,
				'accountNumber' => $account,
				'side' => $side,
				'amount' => $amount,
				'signedAmount' => ($side === 'credit' ? $amount : -$amount),
				'accountClass' => (in_array($account[0], ['4', '8'], true) === true ? 'pnl' : 'balance'),
				'countsInResult' => $counts,
			];
		}
	}//end post()
}//end class
