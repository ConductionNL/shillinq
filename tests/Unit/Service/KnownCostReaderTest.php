<?php

/**
 * Unit tests for KnownCostReader.
 *
 * Covers `budget-known-costs` REQ-BKC-008: LedgerGroup membership
 * resolution (ranges + explicit include/exclude), default-AnnualBudget
 * per-fiscal-year resolution, and the exactly-6-`findAll()`-calls query
 * budget regardless of scope.
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KnownCostReader;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the reader's LedgerGroup-membership resolution, default-AnnualBudget
 * resolution, and query-budget behaviour.
 */
final class KnownCostReaderTest extends TestCase {

	/**
	 * Build the reader over a seeded fixture store, returning both the
	 * reader and the call-counting decorator so tests can assert on
	 * `$decorator->findAllCalls`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: KnownCostReader, 1: CallCountingObjectServiceDecorator}
	 */
	private function buildReader(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$decorator = new CallCountingObjectServiceDecorator(new FilteredObjectServiceStub($data));

		$reader = new KnownCostReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		return [$reader, $decorator];
	}//end buildReader()

	/**
	 * A fixture: one administration, one recurring row, one Account
	 * (accountNumber 4300) in a LedgerGroup range, one default AnnualBudget
	 * for fiscal year 2027, and no pre-existing BudgetLine/derivation.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function fixture(): array {
		return [
			'CashflowRecurring' => [
				[
					'recurId' => 'rec-1',
					'administrationId' => 'adm-1',
					'accountNumberExpense' => '4300',
					'frequency' => 'MONTHLY',
					'standardAmount' => 850.0,
					'validFrom' => '2027-01-01',
				],
			],
			'Account' => [
				['accountNumber' => '4300', 'administrationId' => 'adm-1'],
			],
			'LedgerGroup' => [
				[
					'id' => 'lg-1',
					'@self' => ['slug' => 'lg-huisvesting'],
					'administrationId' => 'adm-1',
					'accountRanges' => [['from' => '4000', 'to' => '4399']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
			'AnnualBudget' => [
				[
					'id' => 'ab-2027',
					'administrationId' => 'adm-1',
					'fiscalYear' => 2027,
					'isDefault' => true,
					'state' => 'active',
				],
			],
			'BudgetLine' => [],
			'BudgetLineDerivation' => [],
		];
	}//end fixture()

	/**
	 * The full read issues exactly 6 `findAll()` calls (REQ-BKC-008).
	 *
	 * @return void
	 */
	public function testQueryCountIsFixed(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadContext('adm-1');

		self::assertSame(6, $decorator->findAllCalls);

	}//end testQueryCountIsFixed()

	/**
	 * Doubling the number of recurring rows, accounts and LedgerGroups does
	 * not add any further `findAll()` calls — the bound is on CALL COUNT,
	 * not data volume.
	 *
	 * @return void
	 */
	public function testCallCountDoesNotScaleWithDataVolume(): void {
		$data = $this->fixture();
		for ($i = 0; $i < 20; $i++) {
			$data['CashflowRecurring'][] = [
				'recurId' => 'rec-extra-' . $i,
				'administrationId' => 'adm-1',
				'accountNumberExpense' => '4300',
				'frequency' => 'MONTHLY',
				'standardAmount' => 10.0,
				'validFrom' => '2027-01-01',
			];
			$data['Account'][] = ['accountNumber' => (string)(5000 + $i), 'administrationId' => 'adm-1'];
		}

		[$reader, $decorator] = $this->buildReader($data);
		$reader->loadContext('adm-1');

		self::assertSame(6, $decorator->findAllCalls);

	}//end testCallCountDoesNotScaleWithDataVolume()

	/**
	 * A range-based LedgerGroup resolves account 4300 into its membership
	 * index, honouring an explicit exclude.
	 *
	 * @return void
	 */
	public function testLedgerGroupMembershipResolvesRangeHonouringExclude(): void {
		$data = $this->fixture();
		$data['Account'][] = ['accountNumber' => '4350', 'administrationId' => 'adm-1'];
		$data['LedgerGroup'][0]['excludedAccountNumbers'] = ['4350'];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertSame('lg-1', $context['ledgerGroupIdByAccount']['4300']);
		self::assertArrayNotHasKey('4350', $context['ledgerGroupIdByAccount']);

	}//end testLedgerGroupMembershipResolvesRangeHonouringExclude()

	/**
	 * An `includedAccountNumbers` entry resolves membership even outside
	 * any declared range.
	 *
	 * @return void
	 */
	public function testLedgerGroupMembershipHonoursExplicitInclude(): void {
		$data = $this->fixture();
		$data['Account'][] = ['accountNumber' => '9999', 'administrationId' => 'adm-1'];
		$data['LedgerGroup'][0]['includedAccountNumbers'] = ['9999'];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertSame('lg-1', $context['ledgerGroupIdByAccount']['9999']);

	}//end testLedgerGroupMembershipHonoursExplicitInclude()

	/**
	 * The default AnnualBudget for fiscal year 2027 resolves to its id; a
	 * non-default sibling for the same year is ignored.
	 *
	 * @return void
	 */
	public function testDefaultAnnualBudgetResolvesPerFiscalYear(): void {
		$data = $this->fixture();
		$data['AnnualBudget'][] = [
			'id' => 'ab-2027-draft',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2027,
			'isDefault' => false,
			'state' => 'draft',
		];
		$data['AnnualBudget'][] = [
			'id' => 'ab-2028',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2028,
			'isDefault' => true,
			'state' => 'draft',
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertSame('ab-2027', $context['annualBudgetIdByYear'][2027]);
		self::assertSame('ab-2028', $context['annualBudgetIdByYear'][2028]);

	}//end testDefaultAnnualBudgetResolvesPerFiscalYear()

	/**
	 * A fiscal year with no `isDefault: true` AnnualBudget has no entry in
	 * the resolved index — the writer's own "skip" rule (REQ-BKC-007) reads
	 * absence, not a fabricated id.
	 *
	 * @return void
	 */
	public function testFiscalYearWithNoDefaultBudgetHasNoIndexEntry(): void {
		$data = $this->fixture();
		$data['AnnualBudget'][0]['isDefault'] = false;

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertArrayNotHasKey(2027, $context['annualBudgetIdByYear']);

	}//end testFiscalYearWithNoDefaultBudgetHasNoIndexEntry()

	/**
	 * `BudgetLine`/`BudgetLineDerivation` are loaded via the
	 * `{annualBudgetId: {in: [...]}}` filter, scoped to the resolved
	 * default AnnualBudget ids only.
	 *
	 * @return void
	 */
	public function testBudgetLinesAndDerivationsScopedToDefaultAnnualBudgetIds(): void {
		$data = $this->fixture();
		$data['BudgetLine'][] = [
			'id' => 'bl-1',
			'annualBudgetId' => 'ab-2027',
			'ledgerGroupId' => 'lg-1',
			'source' => 'recurring',
		];
		$data['BudgetLine'][] = [
			'id' => 'bl-other',
			'annualBudgetId' => 'ab-OTHER',
			'ledgerGroupId' => 'lg-1',
			'source' => 'manual',
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertCount(1, $context['budgetLines']);
		self::assertSame('bl-1', $context['budgetLines'][0]['id']);

	}//end testBudgetLinesAndDerivationsScopedToDefaultAnnualBudgetIds()

	/**
	 * No AnnualBudget at all in scope means no BudgetLine/derivation query
	 * runs (the `if ($annualBudgetIds !== [])` guard), so the query budget
	 * drops accordingly rather than issuing an `{in: []}` query.
	 *
	 * @return void
	 */
	public function testNoDefaultAnnualBudgetSkipsBudgetLineQueries(): void {
		$data = $this->fixture();
		$data['AnnualBudget'][0]['isDefault'] = false;

		[$reader, $decorator] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertSame([], $context['budgetLines']);
		self::assertSame([], $context['derivations']);
		self::assertSame(4, $decorator->findAllCalls);

	}//end testNoDefaultAnnualBudgetSkipsBudgetLineQueries()
	/**
	 * The full membership and default-budget indexes over edge data: ranges
	 * compared as numbers, malformed ranges and groups skipped, includes and
	 * excludes, first group and first default winning, slug fallbacks.
	 *
	 * @return void
	 */
	public function testIndexesOverEdgeData(): void {
		$data = $this->fixture();
		$data['Account'] = [
			['accountNumber' => '4300', 'administrationId' => 'adm-1'],
			['accountNumber' => '43000', 'administrationId' => 'adm-1'],
			['accountNumber' => '4A00', 'administrationId' => 'adm-1'],
			['accountNumber' => '', 'administrationId' => 'adm-1'],
			['accountNumber' => '4100', 'administrationId' => 'adm-1'],
			['accountNumber' => '8000', 'administrationId' => 'adm-1'],
			['administrationId' => 'adm-1'],
		];
		$data['LedgerGroup'] = [
			['administrationId' => 'adm-1', 'accountRanges' => [['from' => '0', 'to' => '9999']]],
			[
				'@self' => ['slug' => 'lg-slug'],
				'administrationId' => 'adm-1',
				'accountRanges' => [['from' => '4000', 'to' => '4199'], ['from' => '', 'to' => '9'], ['from' => 'x', 'to' => '9999'], ['to' => '9999']],
				'includedAccountNumbers' => [9100, '', '4100', '7000'],
				'excludedAccountNumbers' => [7000],
			],
			[
				'id' => 'lg-2',
				'administrationId' => 'adm-1',
				'accountRanges' => [['from' => '4000', 'to' => '4399']],
				'includedAccountNumbers' => 'not-a-list',
				'excludedAccountNumbers' => ['4300'],
			],
			['id' => 'lg-3', 'administrationId' => 'adm-1', 'accountRanges' => 'none', 'includedAccountNumbers' => ['8000', '4300']],
		];
		$data['AnnualBudget'] = [
			['id' => 'ab-x', 'administrationId' => 'adm-1', 'fiscalYear' => 2026, 'isDefault' => 'true'],
			['@self' => ['slug' => 'ab-slug'], 'administrationId' => 'adm-1', 'fiscalYear' => '2026', 'isDefault' => true],
			['id' => 'ab-late', 'administrationId' => 'adm-1', 'fiscalYear' => 2026, 'isDefault' => true],
			['id' => 'ab-zero', 'administrationId' => 'adm-1', 'fiscalYear' => 0, 'isDefault' => true],
			['administrationId' => 'adm-1', 'fiscalYear' => 2028, 'isDefault' => true],
			['id' => 'ab-2029', 'administrationId' => 'adm-1', 'fiscalYear' => 2029, 'isDefault' => true],
		];

		[$reader] = $this->buildReader($data);
		$context = $reader->loadContext('adm-1');

		self::assertSame(['4100' => 'lg-slug', '9100' => 'lg-slug', '8000' => 'lg-3', '4300' => 'lg-3'], $context['ledgerGroupIdByAccount']);
		self::assertSame([2026 => 'ab-slug', 2029 => 'ab-2029'], $context['annualBudgetIdByYear']);

	}//end testIndexesOverEdgeData()
}//end class
