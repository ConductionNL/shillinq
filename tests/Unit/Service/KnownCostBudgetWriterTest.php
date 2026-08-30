<?php

/**
 * Unit tests for KnownCostBudgetWriter.
 *
 * Covers `budget-known-costs` REQ-BKC-004/005/007: idempotent regeneration
 * (running the writer twice with no upstream change produces byte-identical
 * output and does not double-count), multiple recurring rows targeting the
 * same LedgerGroup summing into one derived line, operator-override
 * detection and respect, the deleted-derived-line reset path, and a fiscal
 * year with no default AnnualBudget being skipped rather than fabricated.
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-004
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-005
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KnownCostBudgetWriter;
use OCA\Shillinq\Service\KnownCostReader;
use OCA\Shillinq\Service\KnownCostScheduleExpander;
use OCA\Shillinq\Tests\Unit\Service\Support\CallCountingObjectServiceDecorator;
use OCA\Shillinq\Tests\Unit\Service\Support\KnownCostFixtureObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for KnownCostBudgetWriter::run().
 */
final class KnownCostBudgetWriterTest extends TestCase {

	/**
	 * Build a writer (and its underlying reader) over a seeded fixture
	 * store, returning the writer, the store (for post-run assertions via
	 * `dump()`) and the call-counting decorator (for query-budget assertions).
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return array{0: KnownCostBudgetWriter, 1: KnownCostFixtureObjectServiceStub, 2: CallCountingObjectServiceDecorator}
	 */
	private function buildWriter(array $data): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$store = new KnownCostFixtureObjectServiceStub($data);
		$decorator = new CallCountingObjectServiceDecorator($store);

		$reader = new KnownCostReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
		);

		$writer = new KnownCostBudgetWriter(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $decorator,
			reader: $reader,
			expander: new KnownCostScheduleExpander(),
		);

		return [$writer, $store, $decorator];
	}//end buildWriter()

	/**
	 * A fixture: one administration, one CashflowRecurring row (no
	 * contractReference, so source: recurring) targeting a LedgerGroup via
	 * accountNumberExpense 4300, and a default AnnualBudget for fiscal year
	 * 2027.
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
					'indexationRule' => 'FIXED',
					'validFrom' => '2027-01-01',
					'dagFromMonth' => 1,
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
	 * Running the writer twice in succession with no upstream change
	 * produces exactly one BudgetLine row with IDENTICAL monthly amounts
	 * after both runs — the idempotency property REQ-BKC-004 names
	 * explicitly. A naive expander would double the total on the second
	 * run; this test fails if that regresses.
	 *
	 * @return void
	 */
	public function testRegenerationIsIdempotent(): void {
		[$writer, $store] = $this->buildWriter($this->fixture());

		$writer->run('adm-1');
		$afterFirstRun = $store->dump('BudgetLine');
		self::assertCount(1, $afterFirstRun, 'exactly one BudgetLine after the first run');
		$firstRunAmount = $afterFirstRun[0]['month01Amount'];
		self::assertSame(85000, $firstRunAmount);

		$writer->run('adm-1');
		$afterSecondRun = $store->dump('BudgetLine');

		self::assertCount(1, $afterSecondRun, 'still exactly one BudgetLine after the second run — no double-count');
		self::assertSame($firstRunAmount, $afterSecondRun[0]['month01Amount'], 'byte-identical amount after both runs');
		self::assertCount(1, $store->dump('BudgetLineDerivation'), 'exactly one derivation row, not one per run');

	}//end testRegenerationIsIdempotent()

	/**
	 * The second run issues the same 6-call query budget as the first — no
	 * accumulating query cost per run.
	 *
	 * @return void
	 */
	public function testSecondRunIssuesSameQueryBudgetAsFirst(): void {
		[$writer, , $decorator] = $this->buildWriter($this->fixture());

		$writer->run('adm-1');
		$firstRunCalls = $decorator->findAllCalls;
		self::assertSame(6, $firstRunCalls);

		$decorator->findAllCalls = 0;
		$writer->run('adm-1');

		self::assertSame(6, $decorator->findAllCalls, 'second run issues the identical 6-call query budget');

	}//end testSecondRunIssuesSameQueryBudgetAsFirst()

	/**
	 * Two CashflowRecurring rows (neither contractReference-tagged) whose
	 * accountNumberExpense both resolve to the same LedgerGroup sum into
	 * ONE derived BudgetLine, whose derivation lists both recurIds
	 * (REQ-BKC-004).
	 *
	 * @return void
	 */
	public function testMultipleRecurringRowsTargetingSameLedgerGroupSum(): void {
		$data = $this->fixture();
		$data['CashflowRecurring'][] = [
			'recurId' => 'rec-2',
			'administrationId' => 'adm-1',
			'accountNumberExpense' => '4300',
			'frequency' => 'MONTHLY',
			'standardAmount' => 150.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2027-01-01',
			'dagFromMonth' => 1,
		];

		[$writer, $store] = $this->buildWriter($data);
		$writer->run('adm-1');

		$budgetLines = $store->dump('BudgetLine');
		self::assertCount(1, $budgetLines, 'one derived line, not two');
		self::assertSame(100000, $budgetLines[0]['month01Amount'], '850.00 + 150.00 = 1000.00 EUR = 100000 cents');

		$derivations = $store->dump('BudgetLineDerivation');
		self::assertCount(1, $derivations);
		sort($derivations[0]['contributingRecurIds']);
		self::assertSame(['rec-1', 'rec-2'], $derivations[0]['contributingRecurIds']);

	}//end testMultipleRecurringRowsTargetingSameLedgerGroupSum()

	/**
	 * A `contractReference`-tagged row derives a `source: "contract"` line;
	 * an untagged row derives `source: "recurring"` — distinguishable, per
	 * REQ-BKC-001.
	 *
	 * @return void
	 */
	public function testContractReferenceTagsSourceContract(): void {
		$data = $this->fixture();
		$data['CashflowRecurring'][0]['contractReference'] = 'contract-1';

		[$writer, $store] = $this->buildWriter($data);
		$writer->run('adm-1');

		$budgetLines = $store->dump('BudgetLine');
		self::assertCount(1, $budgetLines);
		self::assertSame('contract', $budgetLines[0]['source']);

		$derivations = $store->dump('BudgetLineDerivation');
		self::assertSame('contract', $derivations[0]['sourceType']);

	}//end testContractReferenceTagsSourceContract()

	/**
	 * An operator's direct edit to a derived BudgetLine's amounts is
	 * detected on the next run and the derivation is flagged `overridden:
	 * true` — the BudgetLine's amounts are left untouched, never clobbered
	 * back to the machine-computed value (REQ-BKC-005).
	 *
	 * @return void
	 */
	public function testOperatorOverrideIsDetectedAndRespected(): void {
		[$writer, $store] = $this->buildWriter($this->fixture());

		$writer->run('adm-1');
		$budgetLineId = $store->dump('BudgetLine')[0]['id'];

		// Simulate the operator hand-editing month03Amount directly.
		$lines = $store->dump('BudgetLine');
		$lines[0]['month03Amount'] = 999999;
		$store->replace('BudgetLine', $lines);

		$writer->run('adm-1');

		$afterSecondRun = $store->dump('BudgetLine');
		self::assertCount(1, $afterSecondRun, 'still exactly one BudgetLine — no duplicate created');
		self::assertSame($budgetLineId, $afterSecondRun[0]['id']);
		self::assertSame(999999, $afterSecondRun[0]['month03Amount'], "the operator's edit persists, untouched");
		self::assertSame(85000, $afterSecondRun[0]['month01Amount'], 'other months untouched too — the whole line is left alone');

		$derivations = $store->dump('BudgetLineDerivation');
		self::assertCount(1, $derivations);
		self::assertTrue($derivations[0]['overridden']);

	}//end testOperatorOverrideIsDetectedAndRespected()

	/**
	 * Once a derivation is `overridden: true`, a further run does not even
	 * read the BudgetLine back — it skips the combination entirely (no
	 * further writes).
	 *
	 * @return void
	 */
	public function testAlreadyOverriddenDerivationIsSkippedOnFurtherRuns(): void {
		[$writer, $store] = $this->buildWriter($this->fixture());

		$writer->run('adm-1');
		$lines = $store->dump('BudgetLine');
		$lines[0]['month03Amount'] = 999999;
		$store->replace('BudgetLine', $lines);
		$writer->run('adm-1'); // First detection — flips overridden to true.

		$derivationBeforeThirdRun = $store->dump('BudgetLineDerivation')[0];

		$writer->run('adm-1'); // Third run — should be a total no-op for this combination.

		$derivationAfterThirdRun = $store->dump('BudgetLineDerivation')[0];
		self::assertSame($derivationBeforeThirdRun['lastGeneratedAt'], $derivationAfterThirdRun['lastGeneratedAt'], 'no write occurred on the third run');
		self::assertCount(1, $store->dump('BudgetLine'));

	}//end testAlreadyOverriddenDerivationIsSkippedOnFurtherRuns()

	/**
	 * Deleting a derived BudgetLine (an overridden or non-overridden
	 * derivation whose target is gone) causes the next run to recreate it
	 * fresh from the current CashflowRecurring inputs, with a fresh,
	 * non-overridden derivation (REQ-BKC-005, the reset path).
	 *
	 * @return void
	 */
	public function testDeletedDerivedLineIsRecreatedFresh(): void {
		[$writer, $store] = $this->buildWriter($this->fixture());

		$writer->run('adm-1');
		self::assertCount(1, $store->dump('BudgetLine'));

		// Simulate deleting the BudgetLine directly (an operator, or direct
		// API use) — the derivation row still references the now-gone id.
		$store->replace('BudgetLine', []);

		$writer->run('adm-1');

		$budgetLines = $store->dump('BudgetLine');
		self::assertCount(1, $budgetLines, 'a fresh BudgetLine is recreated');
		self::assertSame(85000, $budgetLines[0]['month01Amount']);

		$derivations = $store->dump('BudgetLineDerivation');
		self::assertCount(1, $derivations, 'a fresh derivation, not an accumulating second row');
		self::assertFalse($derivations[0]['overridden']);
		self::assertSame($budgetLines[0]['id'], $derivations[0]['budgetLineId']);

	}//end testDeletedDerivedLineIsRecreatedFresh()

	/**
	 * A fiscal year with no default AnnualBudget is skipped entirely — no
	 * BudgetLine is written for it, and no AnnualBudget is fabricated
	 * (REQ-BKC-007).
	 *
	 * @return void
	 */
	public function testFiscalYearWithNoDefaultBudgetIsSkipped(): void {
		$data = $this->fixture();
		$data['AnnualBudget'][0]['isDefault'] = false;

		[$writer, $store] = $this->buildWriter($data);
		$summary = $writer->run('adm-1');

		self::assertSame([], $store->dump('BudgetLine'));
		self::assertSame([], $store->dump('BudgetLineDerivation'));
		self::assertCount(1, $store->dump('AnnualBudget'), 'still exactly the one seeded (non-default) AnnualBudget — none fabricated');
		self::assertContains(2027, $summary['skippedFiscalYears']);

	}//end testFiscalYearWithNoDefaultBudgetIsSkipped()

}//end class
