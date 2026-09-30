<?php

/**
 * Tests for LedgerPivotService (reporting-custom-analysis REQ-RCA-002).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Pivot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-custom-analysis/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Pivot;

use InvalidArgumentException;
use OCA\Shillinq\Service\Pivot\LedgerPivotService;
use OCA\Shillinq\Service\Ledger\GlLineResultStamps;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * The pivot sums what the segment stamps say counts, on the axes asked for.
 */
class LedgerPivotServiceTest extends TestCase {

	private const ADM = 'adm-van-dijk';

	/**
	 * A pivot service over the given register rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $data Rows per schema.
	 *
	 * @return LedgerPivotService The service.
	 */
	private function service(array $data): LedgerPivotService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new LedgerPivotService(new InMemoryObjectServiceStub($data), $settings);
	}

	/**
	 * A posted line, stamped the way GlLineResultStamps stamps it.
	 *
	 * @param string $transactionId The transaction.
	 * @param string $account       The account number.
	 * @param string $side          debit or credit.
	 * @param float  $amount        The amount.
	 * @param string $class         pnl or balance.
	 * @param array  $extra         Further fields.
	 *
	 * @return array<string, mixed> The line.
	 */
	private function line(string $transactionId, string $account, string $side, float $amount, string $class = 'pnl', array $extra = []): array {
		return array_merge(
			[
				'administrationId' => self::ADM,
				'transactionId' => $transactionId,
				'accountNumber' => $account,
				'side' => $side,
				'amount' => $amount,
				'signedAmount' => $side === 'credit' ? $amount : -$amount,
				'accountClass' => $class,
				'countsInResult' => true,
			],
			$extra
		);
	}

	/**
	 * Adviesbureau Van Dijk, 2026: revenue 60,000 / 58,000 / 62,000 and staff costs 30,000 per quarter.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rows per schema.
	 */
	private function vanDijk(): array {
		$transactions = [];
		$lines = [];
		foreach (['2026-02-15' => 60000.0, '2026-05-15' => 58000.0, '2026-08-15' => 62000.0] as $date => $revenue) {
			$sale = 'tx-sale-' . $date;
			$wage = 'tx-wage-' . $date;
			$transactions[] = ['id' => $sale, 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => $date];
			$transactions[] = ['id' => $wage, 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => $date];
			$lines[] = $this->line($sale, '1300', 'debit', $revenue, 'balance', ['subLedgerType' => 'ar', 'subLedgerRef' => 'cust-gemeente']);
			$lines[] = $this->line($sale, '8000', 'credit', $revenue, 'pnl', ['costCenterCode' => 'KP-ADV']);
			$lines[] = $this->line($wage, '4000', 'debit', 30000.0);
			$lines[] = $this->line($wage, '1100', 'credit', 30000.0, 'balance');
		}

		// A draft transaction's line carries no stamp; a reversed one's stamp says it does not count.
		$transactions[] = ['id' => 'tx-draft', 'administrationId' => self::ADM, 'state' => 'draft', 'postingDate' => '2026-03-01'];
		$lines[] = $this->line('tx-draft', '8000', 'credit', 999.0, 'pnl', ['countsInResult' => null]);
		$transactions[] = ['id' => 'tx-reversed', 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => '2026-03-02'];
		$lines[] = $this->line('tx-reversed', '8000', 'credit', 500.0, 'pnl', ['countsInResult' => false]);
		// Another administration's revenue never shows.
		$transactions[] = ['id' => 'tx-other', 'administrationId' => 'adm-other', 'state' => 'posted', 'postingDate' => '2026-03-03'];
		$lines[] = array_merge($this->line('tx-other', '8000', 'credit', 7000.0), ['administrationId' => 'adm-other']);

		return [
			'GLTransaction' => $transactions,
			'GLLine' => $lines,
			'Account' => [
				['administrationId' => self::ADM, 'accountNumber' => '80', 'name' => 'Omzet', 'accountType' => 'revenue'],
				['administrationId' => self::ADM, 'accountNumber' => '8000', 'name' => 'Omzet advies', 'parentAccountNumber' => '80', 'accountType' => 'revenue'],
				['administrationId' => self::ADM, 'accountNumber' => '40', 'name' => 'Personeelskosten', 'accountType' => 'expenses'],
				['administrationId' => self::ADM, 'accountNumber' => '4000', 'name' => 'Lonen', 'parentAccountNumber' => '40', 'accountType' => 'expenses'],
			],
			'CustomerMaster' => [
				['id' => 'cust-gemeente', 'administrationId' => self::ADM, 'customerId' => 'cust-gemeente', 'legalName' => 'Gemeente Tiel'],
			],
		];
	}

	/**
	 * Scenario "Revenue by quarter".
	 *
	 * @return void
	 */
	public function testRevenueByQuarter(): void {
		$pivot = $this->service($this->vanDijk())->pivot(self::ADM, 'accountGroup', 'quarter', '2026-01-01', '2026-12-31');

		$this->assertSame(
			[['key' => '40', 'label' => 'Personeelskosten'], ['key' => '80', 'label' => 'Omzet']],
			$pivot['rows']
		);
		$this->assertSame(['2026-Q1', '2026-Q2', '2026-Q3'], array_column($pivot['columns'], 'key'));
		$this->assertSame(['2026-Q1' => 60000.0, '2026-Q2' => 58000.0, '2026-Q3' => 62000.0], $pivot['cells']['80']);
		$this->assertSame(180000.0, $pivot['rowTotals']['80']);
		$this->assertSame(-90000.0, $pivot['rowTotals']['40']);
		$this->assertSame(90000.0, $pivot['total']);
		$this->assertSame(['rows' => false, 'columns' => false], $pivot['capped']);
		$this->assertFalse($pivot['truncated']);
	}

	/**
	 * Revenue is attributed to the customer on the receivables line of its transaction.
	 *
	 * @return void
	 */
	public function testRevenueLandsOnTheCustomerOfItsTransaction(): void {
		$pivot = $this->service($this->vanDijk())->pivot(self::ADM, 'customer', 'costCenter', '2026-01-01', '2026-06-30');

		$this->assertSame([['key' => 'cust-gemeente', 'label' => 'Gemeente Tiel'], ['key' => '', 'label' => null]], $pivot['rows']);
		$this->assertSame(118000.0, $pivot['cells']['cust-gemeente']['KP-ADV']);
		$this->assertSame(-60000.0, $pivot['cells']['']['']);
	}

	/**
	 * Scenario "A large pivot is capped visibly": 250 customers, 200 shown, totals over all.
	 *
	 * @return void
	 */
	public function testALargePivotIsCappedVisibly(): void {
		$data = ['GLTransaction' => [], 'GLLine' => [], 'Account' => [], 'CustomerMaster' => []];
		for ($i = 1; $i <= 250; $i++) {
			$tx = 'tx-' . $i;
			$data['GLTransaction'][] = ['id' => $tx, 'administrationId' => self::ADM, 'state' => 'posted', 'postingDate' => '2026-04-01'];
			$data['GLLine'][] = $this->line($tx, '1300', 'debit', (float)$i, 'balance', ['subLedgerType' => 'ar', 'subLedgerRef' => 'cust-' . $i]);
			$data['GLLine'][] = $this->line($tx, '8000', 'credit', (float)$i);
		}

		$pivot = $this->service($data)->pivot(self::ADM, 'customer', 'period', '2026-01-01', '2026-12-31');

		$this->assertCount(LedgerPivotService::MAX_GROUPS, $pivot['rows']);
		$this->assertTrue($pivot['capped']['rows']);
		$this->assertNotContains('cust-1', array_column($pivot['rows'], 'key'), 'the smallest customers are the ones left out');
		$this->assertSame(31375.0, $pivot['total'], 'the total counts every line, shown or not');
	}

	/**
	 * An axis outside the vocabulary, one axis twice, a bad date and a too long range are refused.
	 *
	 * @return void
	 */
	public function testARequestOutsideTheVocabularyIsRefused(): void {
		$service = $this->service([]);
		foreach ([
			['vatCode', 'period', '2026-01-01', '2026-12-31'],
			['period', 'period', '2026-01-01', '2026-12-31'],
			['account', 'period', '2026-13-01', '2026-12-31'],
			['account', 'period', '2026-12-31', '2026-01-01'],
			['account', 'period', '2023-01-01', '2026-12-31'],
		] as $request) {
			try {
				$service->pivot(self::ADM, ...$request);
				$this->fail('accepted ' . implode(' ', $request));
			} catch (InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}

	/**
	 * The fields the pivot reads are the ones the register declares, and the stamps it trusts are GlLineResultStamps'.
	 *
	 * @return void
	 */
	public function testTheLinesItSumsAreValidRegisterLines(): void {
		$line = $this->line('9e7f2f8a-1111-4b3c-9d2e-000000000001', '8000', 'credit', 100.0, 'pnl', ['subLedgerType' => 'ar', 'subLedgerRef' => 'cust-1', 'costCenterCode' => 'KP-ADV', 'projectCode' => 'P-1']);
		$line['lineNumber'] = 1;
		$this->assertSame([], RegisterSchema::errors('GLLine', $line));
		$this->assertSame(['revenue', 'expenses'], GlLineResultStamps::PNL_ACCOUNT_TYPES);
	}
}
