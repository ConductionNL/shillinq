<?php

/**
 * Unit tests for BudgetScenarioEvaluator.
 *
 * Covers `budget-scenarios` REQ-BSC-005 (non-destructive evaluation),
 * REQ-BSC-006 (the shared `KnownCostScheduleExpanderInterface` is the only
 * source of RECURRING_* arithmetic), and the cross-change consistency check
 * (a scenario's hypothetical figure matches what a real regeneration would
 * produce, since both paths call the identical expander with the identical
 * input construction).
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetScenarioEvaluator;
use OCA\Shillinq\Service\KnownCostScheduleExpander;
use OCA\Shillinq\Tests\Unit\Service\Support\FakeKnownCostScheduleExpander;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the pure base-vs-scenario evaluator.
 */
final class BudgetScenarioEvaluatorTest extends TestCase {

	/**
	 * One LedgerGroup ("Hosting", account range 4200-4200) and one
	 * BudgetLine giving it a real, own budgeted January 2027 figure.
	 *
	 * @return array<string,mixed>
	 */
	private function ledgerGroups(): array {
		return [
			[
				'id' => 'lg-hosting',
				'@self' => ['id' => 'lg-hosting', 'slug' => 'ledger-group-hosting'],
				'administrationId' => 'adm-1',
				'code' => 'HOSTING',
				'name' => 'Hosting',
				'parentLedgerGroupId' => null,
				'accountRanges' => [['from' => '4200', 'to' => '4200']],
				'includedAccountNumbers' => [],
				'excludedAccountNumbers' => [],
			],
		];
	}//end ledgerGroups()

	/**
	 * A parent group ("Bedrijfskosten") with one child ("Hosting") — for the
	 * parent-rollup test.
	 *
	 * @return array<string,mixed>
	 */
	private function nestedLedgerGroups(): array {
		$groups = $this->ledgerGroups();
		$groups[0]['parentLedgerGroupId'] = 'lg-parent';
		$groups[] = [
			'id' => 'lg-parent',
			'@self' => ['id' => 'lg-parent', 'slug' => 'ledger-group-bedrijfskosten'],
			'administrationId' => 'adm-1',
			'code' => 'BDK',
			'name' => 'Bedrijfskosten',
			'parentLedgerGroupId' => null,
			'accountRanges' => [],
			'includedAccountNumbers' => [],
			'excludedAccountNumbers' => [],
		];

		return $groups;
	}//end nestedLedgerGroups()

	/**
	 * One real CashflowRecurring row: MONTHLY, €250/month, account 4200
	 * (resolves to the "Hosting" LedgerGroup), open-ended from 2026-01-01.
	 *
	 * @return array<string,mixed>
	 */
	private function recurringRow(): array {
		return [
			'recurId' => 'rec-hosting',
			'administrationId' => 'adm-1',
			'accountNumberExpense' => '4200',
			'label' => 'Hosting',
			'frequency' => 'MONTHLY',
			'standardAmount' => 250.0,
			'validFrom' => '2026-01-01',
			'validTo' => null,
		];
	}//end recurringRow()

	/**
	 * A scenario with zero modifiers evaluates to exactly the base — every
	 * cell's scenario value equals base, delta is 0 everywhere
	 * (REQ-BSC-005 second scenario).
	 *
	 * @return void
	 */
	public function testZeroModifiersEqualsBase(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$baseBudgetLines = [
			['ledgerGroupId' => 'lg-hosting', 'month01Amount' => 100000, 'month02Amount' => 100000],
		];

		$result = $evaluator->evaluate(
			baseBudgetLines: $baseBudgetLines,
			ledgerGroups: $this->ledgerGroups(),
			cashflowRecurringRows: [$this->recurringRow()],
			modifiers: [],
			fiscalYear: 2027
		);

		foreach ($result as $cell) {
			$this->assertSame($cell['base'], $cell['scenario']);
			$this->assertSame(0, $cell['delta']);
		}

		$this->assertSame(100000, $result['lg-hosting:2027-01']['base']);

	}//end testZeroModifiersEqualsBase()

	/**
	 * A LEDGER_AMOUNT_DELTA modifier applies to exactly one month — every
	 * other month is unaffected (REQ-BSC-003 second scenario).
	 *
	 * @return void
	 */
	public function testLedgerAmountDeltaAppliesToSingleMonth(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$baseBudgetLines = [
			['ledgerGroupId' => 'lg-hosting', 'month01Amount' => 0, 'month03Amount' => 0],
		];

		$modifiers = [
			[
				'modifierType' => 'LEDGER_AMOUNT_DELTA',
				'targetLedgerGroupId' => 'lg-hosting',
				'effectiveDate' => '2027-03-15',
				'amountDeltaCents' => -500000,
			],
		];

		$result = $evaluator->evaluate(
			baseBudgetLines: $baseBudgetLines,
			ledgerGroups: $this->ledgerGroups(),
			cashflowRecurringRows: [],
			modifiers: $modifiers,
			fiscalYear: 2027
		);

		$this->assertSame(-500000, $result['lg-hosting:2027-03']['delta']);
		$this->assertSame(0, $result['lg-hosting:2027-01']['delta']);
		$this->assertSame(0, $result['lg-hosting:2027-02']['delta']);

	}//end testLedgerAmountDeltaAppliesToSingleMonth()

	/**
	 * A LEDGER_AMOUNT_DELTA dated in a DIFFERENT fiscal year than the one
	 * being evaluated contributes nothing.
	 *
	 * @return void
	 */
	public function testLedgerAmountDeltaOutsideRequestedFiscalYearContributesNothing(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$modifiers = [
			[
				'modifierType' => 'LEDGER_AMOUNT_DELTA',
				'targetLedgerGroupId' => 'lg-hosting',
				'effectiveDate' => '2028-03-15',
				'amountDeltaCents' => -500000,
			],
		];

		$result = $evaluator->evaluate(
			baseBudgetLines: [],
			ledgerGroups: $this->ledgerGroups(),
			cashflowRecurringRows: [],
			modifiers: $modifiers,
			fiscalYear: 2027
		);

		$this->assertSame(0, $result['lg-hosting:2027-03']['delta']);

	}//end testLedgerAmountDeltaOutsideRequestedFiscalYearContributesNothing()

	/**
	 * A RECURRING_END modifier caps the schedule at effectiveDate — months
	 * after the cap lose their €250 hypothetical contribution.
	 *
	 * @return void
	 */
	public function testRecurringEndCapsScheduleAtEffectiveDate(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$modifiers = [
			[
				'modifierType' => 'RECURRING_END',
				'targetRecurId' => 'rec-hosting',
				'effectiveDate' => '2027-06-30',
			],
		];

		$result = $evaluator->evaluate(
			baseBudgetLines: [],
			ledgerGroups: $this->ledgerGroups(),
			cashflowRecurringRows: [$this->recurringRow()],
			modifiers: $modifiers,
			fiscalYear: 2027
		);

		// Real (unmodified) row is open-ended: every month books -25000
		// cents against base's own €0 own-line (base itself carries no
		// BudgetLine here, so base is 0 and the recurring row's real
		// expansion is NOT part of base — it only enters via the modifier's
		// hypothetical-vs-real delta, per design.md §6a step 2).
		$this->assertSame(0, $result['lg-hosting:2027-01']['delta']);
		$this->assertSame(0, $result['lg-hosting:2027-06']['delta']);
		// July onward: hypothetical (capped) books 0, real books 25000 —
		// delta = 0 - 25000 = -25000.
		$this->assertSame(-25000, $result['lg-hosting:2027-07']['delta']);
		$this->assertSame(-25000, $result['lg-hosting:2027-12']['delta']);

	}//end testRecurringEndCapsScheduleAtEffectiveDate()

	/**
	 * A RECURRING_AMOUNT_CHANGE applies the new amount from effectiveDate
	 * forward; the real amount still applies strictly before it.
	 *
	 * @return void
	 */
	public function testRecurringAmountChangeAppliesFromEffectiveDateForward(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$modifiers = [
			[
				'modifierType' => 'RECURRING_AMOUNT_CHANGE',
				'targetRecurId' => 'rec-hosting',
				'effectiveDate' => '2027-06-01',
				'newStandardAmount' => 100.0,
			],
		];

		$result = $evaluator->evaluate(
			baseBudgetLines: [],
			ledgerGroups: $this->ledgerGroups(),
			cashflowRecurringRows: [$this->recurringRow()],
			modifiers: $modifiers,
			fiscalYear: 2027
		);

		// Before June: real 25000, hypothetical (before-slice) also 25000 — delta 0.
		$this->assertSame(0, $result['lg-hosting:2027-05']['delta']);
		// From June: real 25000, hypothetical (after-slice, €100) 10000 — delta -15000.
		$this->assertSame(-15000, $result['lg-hosting:2027-06']['delta']);
		$this->assertSame(-15000, $result['lg-hosting:2027-12']['delta']);

	}//end testRecurringAmountChangeAppliesFromEffectiveDateForward()

	/**
	 * Two modifiers on DIFFERENT targets both apply, summed independently of
	 * evaluation order (REQ-BSC-004 second scenario).
	 *
	 * @return void
	 */
	public function testIndependentModifiersSumOrderIndependently(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$ledgerGroups = $this->ledgerGroups();
		$ledgerGroups[] = [
			'id' => 'lg-bank',
			'@self' => ['id' => 'lg-bank', 'slug' => 'ledger-group-vla-liq'],
			'administrationId' => 'adm-1',
			'code' => 'VLA-LIQ',
			'name' => 'Liquide middelen',
			'parentLedgerGroupId' => null,
			'accountRanges' => [['from' => '1000', 'to' => '1099']],
			'includedAccountNumbers' => [],
			'excludedAccountNumbers' => [],
		];

		$modifiersInOneOrder = [
			['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-06-30'],
			['modifierType' => 'LEDGER_AMOUNT_DELTA', 'targetLedgerGroupId' => 'lg-bank', 'effectiveDate' => '2027-09-01', 'amountDeltaCents' => 5000000],
		];
		$modifiersReversed = array_reverse($modifiersInOneOrder);

		$resultA = $evaluator->evaluate([], $ledgerGroups, [$this->recurringRow()], $modifiersInOneOrder, 2027);
		$resultB = $evaluator->evaluate([], $ledgerGroups, [$this->recurringRow()], $modifiersReversed, 2027);

		$this->assertSame($resultA, $resultB);
		$this->assertSame(-25000, $resultA['lg-hosting:2027-07']['delta']);
		$this->assertSame(5000000, $resultA['lg-bank:2027-09']['delta']);

	}//end testIndependentModifiersSumOrderIndependently()

	/**
	 * A parent LedgerGroup's scenario-adjusted value rolls up from its
	 * children exactly as its base value already does (design.md §6b) — a
	 * modifier on the LEAF "Hosting" group is reflected in the parent
	 * "Bedrijfskosten" group's own scenario total.
	 *
	 * @return void
	 */
	public function testParentLedgerGroupRollupAppliesToScenarioValuesToo(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$modifiers = [
			['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-06-30'],
		];

		$result = $evaluator->evaluate(
			baseBudgetLines: [],
			ledgerGroups: $this->nestedLedgerGroups(),
			cashflowRecurringRows: [$this->recurringRow()],
			modifiers: $modifiers,
			fiscalYear: 2027
		);

		// Parent has no own BudgetLine — its base is the rollup of its
		// child's base (0), and its scenario is the rollup of its child's
		// scenario (also reflecting the RECURRING_END delta from July).
		$this->assertSame(0, $result['lg-parent:2027-01']['base']);
		$this->assertSame(-25000, $result['lg-parent:2027-07']['delta']);
		$this->assertSame($result['lg-hosting:2027-07']['scenario'], $result['lg-parent:2027-07']['scenario']);

	}//end testParentLedgerGroupRollupAppliesToScenarioValuesToo()

	/**
	 * Cross-change consistency (REQ-BSC-006): the modifier's hypothetical
	 * figure is derived by calling the SAME shared expander for both the
	 * hypothetical and the real row — never a second, independent
	 * arithmetic. Verified by inspecting the fake expander's own call log:
	 * every call it received carries the row's OTHER fields unmodified
	 * (only validTo/validFrom/standardAmount are sliced), matching exactly
	 * how a real regeneration would construct its own input.
	 *
	 * @return void
	 */
	public function testRecurringModifierDelegatesEveryScheduleCallToTheSharedExpander(): void {
		$expander = new FakeKnownCostScheduleExpander();
		$evaluator = new BudgetScenarioEvaluator($expander, new NullLogger());

		$modifiers = [
			['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-06-30'],
		];

		$evaluator->evaluate([], $this->ledgerGroups(), [$this->recurringRow()], $modifiers, 2027);

		// Exactly 2 calls: the real (unmodified) row, and the hypothetical
		// (capped) row — no third, independent computation.
		$this->assertCount(2, $expander->calls);
		$this->assertSame('rec-hosting', $expander->calls[0]['recurId']);
		$this->assertNull($expander->calls[0]['validTo']);
		$this->assertSame('rec-hosting', $expander->calls[1]['recurId']);
		$this->assertSame('2027-06-30', $expander->calls[1]['validTo']);

	}//end testRecurringModifierDelegatesEveryScheduleCallToTheSharedExpander()

	/**
	 * NON-DESTRUCTIVE PROOF (REQ-BSC-005): evaluate() never mutates its
	 * inputs. The real BudgetLine and CashflowRecurring arrays passed in
	 * are byte-identical (via var_export serialisation) before and after
	 * evaluate() runs.
	 *
	 * @return void
	 */
	public function testEvaluationLeavesInputsByteIdentical(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$baseBudgetLines = [
			['ledgerGroupId' => 'lg-hosting', 'month01Amount' => 100000],
		];
		$ledgerGroups = $this->ledgerGroups();
		$cashflowRecurringRows = [$this->recurringRow()];
		$modifiers = [
			['modifierType' => 'RECURRING_AMOUNT_CHANGE', 'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-06-01', 'newStandardAmount' => 100.0],
		];

		$baseBudgetLinesBefore = var_export($baseBudgetLines, true);
		$cashflowRecurringRowsBefore = var_export($cashflowRecurringRows, true);

		$evaluator->evaluate($baseBudgetLines, $ledgerGroups, $cashflowRecurringRows, $modifiers, 2027);

		$this->assertSame($baseBudgetLinesBefore, var_export($baseBudgetLines, true));
		$this->assertSame($cashflowRecurringRowsBefore, var_export($cashflowRecurringRows, true));
		// The real recurring row's own standardAmount is untouched — the
		// evaluator's return value carries the hypothetical figure
		// separately (asserted in testRecurringAmountChangeAppliesFromEffectiveDateForward()).
		$this->assertSame(250.0, $cashflowRecurringRows[0]['standardAmount']);

	}//end testEvaluationLeavesInputsByteIdentical()

	/**
	 * THE CONTROL. Every other test in this file drives the evaluator through
	 * {@see FakeKnownCostScheduleExpander}, and a fake can only ever confirm the
	 * shape its author believed in. That is precisely how RECURRING_* modifiers
	 * shipped as a total no-op with this suite green: the fake returned a flat
	 * `["01" => cents]` map, the evaluator read a flat map, and the real
	 * `KnownCostScheduleExpander` returned
	 * `['kind' => 'amounts', 'monthlyCents' => [...]]` — a shape nothing under
	 * test ever produced. Both halves agreed with each other and neither agreed
	 * with production.
	 *
	 * So this one wires the REAL expander. It is the only test here that can
	 * observe a mismatch between the evaluator and the class it actually calls
	 * at runtime, and it fails (delta 0, every month) against the flat-indexing
	 * code this test was added alongside.
	 *
	 * MONTHLY €250 from 2026-01-01 with no end, capped at 2027-06-30: July
	 * through December 2027 lose €250 each.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
	 */
	public function testRecurringEndAgainstTheRealExpanderMovesTheNumber(): void {
		$evaluator = new BudgetScenarioEvaluator(new KnownCostScheduleExpander(), new NullLogger());

		$modifiers = [
			['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-06-30'],
		];

		$result = $evaluator->evaluate([], $this->ledgerGroups(), [$this->recurringRow()], $modifiers, 2027);

		// June is still inside the capped window — unchanged.
		$this->assertSame(0, $result['lg-hosting:2027-06']['delta']);
		// July onward is cut. A non-zero delta here is the whole point: the
		// pre-fix evaluator produced 0 for all twelve months.
		$this->assertSame(-25000, $result['lg-hosting:2027-07']['delta']);
		$this->assertSame(-25000, $result['lg-hosting:2027-12']['delta']);

	}//end testRecurringEndAgainstTheRealExpanderMovesTheNumber()

	/**
	 * REQ-BKC-003: a CPI-indexed row with no `cpiRatePercent` makes the expander
	 * answer `needsOperatorInput`, which carries no schedule at all.
	 *
	 * The evaluator must SKIP such a modifier. The tempting alternative — treat
	 * the missing schedule as all-zero — is not neutral: the delta is
	 * `hypothetical - real`, so a zeroed `real` posts the entire hypothetical
	 * amount as if it were the change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
	 */
	public function testNeedsOperatorInputSkipsTheModifierRatherThanZeroingTheBaseline(): void {
		$evaluator = new BudgetScenarioEvaluator(new FakeKnownCostScheduleExpander(), new NullLogger());

		$cpiRow = array_merge(
			$this->recurringRow(),
			['indexationRule' => 'CPI_PAST_YEAR', 'cpiRatePercent' => null]
		);
		$modifiers = [
			['modifierType' => 'RECURRING_END', 'targetRecurId' => 'rec-hosting', 'effectiveDate' => '2027-06-30'],
		];

		$result = $evaluator->evaluate([], $this->ledgerGroups(), [$cpiRow], $modifiers, 2027);

		foreach (['2027-06', '2027-07', '2027-12'] as $month) {
			$this->assertSame(
				0,
				$result['lg-hosting:' . $month]['delta'],
				$month . ': an unknowable schedule must post no delta at all'
			);
		}

	}//end testNeedsOperatorInputSkipsTheModifierRatherThanZeroingTheBaseline()
}//end class
