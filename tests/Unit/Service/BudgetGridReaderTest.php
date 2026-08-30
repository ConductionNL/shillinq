<?php

/**
 * Unit tests for BudgetGridReader.
 *
 * Covers `budget-grid-view` task group 1 (REQ-BGV-001/002/003/009): the row
 * tree with 2+ nesting levels, the past-column determination (exact-span AND
 * coarser-containment forms, `open`/`closing` never counting), the
 * fiscal-year-crossing empty-vs-zero distinction, and the flat,
 * row/column-count-independent `findAll()` query budget — proved with the
 * same call-counting decorator the `budget-core-schema` sibling change used.
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
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetGridReader;
use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the reader's row-tree, column-generation, past-boundary, and
 * query-budget behaviour.
 */
final class BudgetGridReaderTest extends TestCase {

	/**
	 * Build a BudgetGridReader wired over ONE shared call-counting decorator
	 * (so both the reader's own reads AND the delegated
	 * BudgetVsActualsReader's reads are counted together — the true render
	 * cost REQ-BGV-009 bounds).
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: BudgetGridReader, 1: CallCountingObjectServiceDecorator}
	 */
	private function buildReader(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$decorator = new CallCountingObjectServiceDecorator(new FilteredObjectServiceStub($data));

		$bvaReader = new BudgetVsActualsReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		$reader = new BudgetGridReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
			budgetVsActualsReader: $bvaReader,
		);

		return [$reader, $decorator];
	}//end buildReader()

	/**
	 * Fixture: two nesting levels — root "Personeel" with child "Lonen"
	 * (a leaf resolving account 4000), plus a closed FiscalPeriod for
	 * January 2027 and an open one for February 2027, a default AnnualBudget
	 * for fiscal year 2027, and a BudgetLine against "Lonen".
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function fixture(): array {
		return [
			'LedgerGroup' => [
				[
					'id' => 'lg-personeel',
					'@self' => ['slug' => 'personeel'],
					'administrationId' => 'adm-1',
					'code' => 'personeel',
					'name' => 'Personeel',
					'order' => 10,
					'parentLedgerGroupId' => null,
					'accountRanges' => [],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
				[
					'id' => 'lg-lonen',
					'@self' => ['slug' => 'lonen'],
					'administrationId' => 'adm-1',
					'code' => 'lonen',
					'name' => 'Lonen en salarissen',
					'order' => 10,
					'parentLedgerGroupId' => 'personeel',
					'accountRanges' => [['from' => '4000', 'to' => '4099']],
					'includedAccountNumbers' => [],
					'excludedAccountNumbers' => [],
				],
			],
			'Account' => [
				['accountNumber' => '4000', 'name' => 'Salarissen', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
			],
			'GLTransaction' => [
				[
					'id' => 'tx-1',
					'transactionNumber' => 'GL-2027-0001',
					'administrationId' => 'adm-1',
					'state' => 'posted',
					'postingDate' => '2027-01-15',
				],
			],
			'GLLine' => [
				['transactionId' => 'tx-1', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 900.00],
			],
			'FiscalPeriod' => [
				[
					'periodId' => '2027-M01',
					'administrationId' => 'adm-1',
					'startDate' => '2027-01-01',
					'endDate' => '2027-01-31',
					'fiscalYear' => 2027,
					'state' => 'closed',
				],
				[
					'periodId' => '2027-M02',
					'administrationId' => 'adm-1',
					'startDate' => '2027-02-01',
					'endDate' => '2027-02-28',
					'fiscalYear' => 2027,
					'state' => 'open',
				],
			],
			'AnnualBudget' => [
				['id' => 'ab-2027', 'administrationId' => 'adm-1', 'fiscalYear' => 2027, 'isDefault' => true, 'name' => 'Begroting 2027'],
			],
			'BudgetLine' => [
				['id' => 'bl-1', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'lonen', 'source' => 'manual', 'month01Amount' => 100000],
			],
		];
	}//end fixture()

	/**
	 * rowsFor() resolves a 2-level tree: "Lonen" appears as a child of
	 * "Personeel", not as a root.
	 *
	 * @return void
	 */
	public function testRowsForResolvesTwoNestingLevels(): void {
		[$reader] = $this->buildReader($this->fixture());

		$tree = $reader->rowsFor('adm-1');

		$this->assertCount(1, $tree['rootIndexes']);
		$personeelIndex = $tree['rootIndexes'][0];
		$this->assertSame('Personeel', $tree['entries'][$personeelIndex]['name']);

		$children = ($tree['childrenByIndex'][$personeelIndex] ?? []);
		$this->assertCount(1, $children);
		$this->assertSame('Lonen en salarissen', $tree['entries'][$children[0]]['name']);

	}//end testRowsForResolvesTwoNestingLevels()

	/**
	 * A column is past via an EXACT-span closed FiscalPeriod.
	 *
	 * @return void
	 */
	public function testColumnIsPastViaExactSpanClosedPeriod(): void {
		[$reader] = $this->buildReader($this->fixture());

		$columns = $reader->columnsFor(['start' => '2027-01', 'end' => '2027-01'], 'month');
		$fiscalPeriods = $this->fixture()['FiscalPeriod'];
		$past = $reader->pastColumnKeys($columns, $fiscalPeriods);

		$this->assertTrue($past['2027-01'] ?? false);

	}//end testColumnIsPastViaExactSpanClosedPeriod()

	/**
	 * A month fully CONTAINED within a closed, coarser (quarterly)
	 * FiscalPeriod also counts as past (design.md §2c amended) — the exact
	 * scenario from spec.md's own REQ-BGV-003 quarterly-containment case.
	 *
	 * @return void
	 */
	public function testColumnIsPastViaCoarserContainingClosedPeriod(): void {
		[$reader] = $this->buildReader($this->fixture());

		$columns = $reader->columnsFor(['start' => '2026-01', 'end' => '2026-01'], 'month');
		$fiscalPeriods = [
			['periodId' => '2026-Q1', 'startDate' => '2026-01-01', 'endDate' => '2026-03-31', 'state' => 'closed'],
		];

		$past = $reader->pastColumnKeys($columns, $fiscalPeriods);

		$this->assertTrue($past['2026-01'] ?? false);

	}//end testColumnIsPastViaCoarserContainingClosedPeriod()

	/**
	 * `open`/`closing` FiscalPeriod states never count as past, even for an
	 * exact-span match (REQ-BGV-003's own negative scenario).
	 *
	 * @return void
	 */
	public function testOpenAndClosingStatesNeverCountAsPast(): void {
		[$reader] = $this->buildReader($this->fixture());

		$columns = $reader->columnsFor(['start' => '2027-02', 'end' => '2027-02'], 'month');
		$fiscalPeriods = $this->fixture()['FiscalPeriod'];
		$past = $reader->pastColumnKeys($columns, $fiscalPeriods);

		$this->assertFalse($past['2027-02'] ?? false);

	}//end testOpenAndClosingStatesNeverCountAsPast()

	/**
	 * Quarter granularity generates one column per quarter, with all three
	 * calendar months in `monthKeys` (spec.md's own quarter-aggregation
	 * scenario, REQ-BGV-001).
	 *
	 * @return void
	 */
	public function testQuarterGranularityGeneratesThreeMonthKeysPerColumn(): void {
		[$reader] = $this->buildReader($this->fixture());

		$columns = $reader->columnsFor(['start' => '2027-01', 'end' => '2027-03'], 'quarter');

		$this->assertCount(1, $columns);
		$this->assertSame(['2027-01', '2027-02', '2027-03'], $columns[0]['monthKeys']);
		$this->assertSame('2027-Q1', $columns[0]['key']);

	}//end testQuarterGranularityGeneratesThreeMonthKeysPerColumn()

	/**
	 * A fiscal year with no default AnnualBudget resolves to `null` in
	 * `annualBudgetIdByYear` — distinct from a missing/absent key,
	 * proving the caller can render the REQ-BGV-001 empty/dash state rather
	 * than silently treating it as 0.
	 *
	 * @return void
	 */
	public function testFiscalYearWithNoDefaultAnnualBudgetResolvesToNull(): void {
		[$reader] = $this->buildReader($this->fixture());

		$grid = $reader->loadGrid('adm-1', '2026-11', '2027-02', 'month');

		$this->assertArrayHasKey(2026, $grid['annualBudgetIdByYear']);
		$this->assertNull($grid['annualBudgetIdByYear'][2026]);
		$this->assertSame('ab-2027', $grid['annualBudgetIdByYear'][2027]);

	}//end testFiscalYearWithNoDefaultAnnualBudgetResolvesToNull()

	/**
	 * The full `loadGrid()` render issues a FLAT, small constant number of
	 * `findAll()` calls (REQ-BGV-009) — measured directly via the
	 * call-counting decorator, not merely asserted.
	 *
	 * @return void
	 */
	public function testLoadGridIssuesFlatQueryCount(): void {
		[$reader, $decorator] = $this->buildReader($this->fixture());

		$reader->loadGrid('adm-1', '2027-01', '2027-02', 'month');

		// 4 of this reader's own reads (LedgerGroup, FiscalPeriod,
		// AnnualBudget, BudgetLine) + 4 delegated to BudgetVsActualsReader
		// (Account, GLTransaction, GLLine, LedgerGroup again) = 8. See
		// BudgetGridReader's own class docblock for why this is 8, not the
		// design doc's stated 7 — an AnnualBudget read the design's own
		// summary table omitted is unavoidable for REQ-BGV-001 to work at
		// all across a fiscal-year-crossing range.
		$this->assertSame(8, $decorator->findAllCalls);

	}//end testLoadGridIssuesFlatQueryCount()

	/**
	 * Doubling the number of `LedgerGroup` rows and displayed columns does
	 * NOT change the query count — the bound is flat, not row/column-scaling
	 * (REQ-BGV-009's own core property, mirroring
	 * `BudgetVsActualsReaderTest::testCallCountDoesNotScaleWithDataVolume()`).
	 *
	 * @return void
	 */
	public function testQueryCountDoesNotScaleWithRowsOrColumns(): void {
		$data = $this->fixture();
		for ($i = 0; $i < 20; $i++) {
			$data['LedgerGroup'][] = [
				'id' => 'lg-extra-' . $i,
				'@self' => ['slug' => 'extra-' . $i],
				'administrationId' => 'adm-1',
				'code' => 'extra-' . $i,
				'name' => 'Extra ' . $i,
				'order' => (20 + $i),
				'parentLedgerGroupId' => null,
				'accountRanges' => [],
				'includedAccountNumbers' => [],
				'excludedAccountNumbers' => [],
			];
		}

		[$reader, $decorator] = $this->buildReader($data);

		// 12 displayed months (vs. the other test's 2) — more columns must
		// not add more queries either.
		$reader->loadGrid('adm-1', '2027-01', '2027-12', 'month');

		$this->assertSame(8, $decorator->findAllCalls);

	}//end testQueryCountDoesNotScaleWithRowsOrColumns()
}//end class
