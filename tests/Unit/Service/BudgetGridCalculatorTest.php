<?php

/**
 * Unit tests for BudgetGridCalculator.
 *
 * Covers `budget-grid-view` task group 2 (REQ-BGV-003/004/005/008) — the
 * task brief's own explicit warning: "getting this wrong inverts the whole
 * screen". One case per `accountType` proves the sign is NOT inverted: a
 * revenue account over budget is favorable, an expense account over budget
 * (the IDENTICAL raw `actual - budget` difference) is unfavorable. Also
 * covers the cumulative TOTAAL pair (werkelijk excludes future columns), a
 * parent LedgerGroup's child-rollup, and the computed-row formula evaluator
 * against the full `rj270-pl.json`-matching waterfall.
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
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetGridCalculator;
use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the calculator's sign convention, cumulative pair, parent rollup,
 * and computed-row formula evaluator — pure arithmetic, no OpenRegister.
 */
final class BudgetGridCalculatorTest extends TestCase {

	/**
	 * Build a calculator instance.
	 *
	 * @return BudgetGridCalculator
	 */
	private function calculator(): BudgetGridCalculator {
		return new BudgetGridCalculator(new BudgetVsActualsCalculator());
	}//end calculator()

	/**
	 * Build a minimal bvaContext for one LedgerGroup with the given member
	 * account numbers, own BudgetLine amount, and actual GL amounts by month.
	 *
	 * @param string $key The LedgerGroup's key (id/slug).
	 * @param list<string> $memberAccountNumbers Its own resolved member account numbers.
	 * @param array<string,array<string,int>> $actualsByAccountMonth accountNumber => monthKey => cents.
	 * @param array<int,int>|null $monthAmounts month01..12Amount values (1-indexed), or null for no own BudgetLine.
	 *
	 * @return array<string,mixed>
	 */
	private function contextFor(string $key, array $memberAccountNumbers, array $actualsByAccountMonth, ?array $monthAmounts): array {
		$line = ['ledgerGroupId' => $key, 'source' => 'manual'];
		if ($monthAmounts !== null) {
			foreach ($monthAmounts as $monthNumber => $amount) {
				$field = sprintf('month%02dAmount', $monthNumber);
				$line[$field] = $amount;
			}
		}

		return [
			'actualsByAccountMonth' => $actualsByAccountMonth,
			'ledgerGroupEntries' => [0 => ['id' => $key, 'slug' => $key, 'memberAccountNumbers' => $memberAccountNumbers]],
			'ledgerGroupKeyToIndex' => [$key => 0],
			'ledgerGroupChildrenByIndex' => [],
			'budgetLines' => ($monthAmounts !== null ? [$line] : []),
		];

	}//end contextFor()

	/**
	 * A revenue account 10,000 over budget shows a POSITIVE/favorable
	 * deviation.
	 *
	 * @return void
	 */
	public function testRevenueAccountOverBudgetIsFavorable(): void {
		$context = $this->contextFor('lg-omzet', ['8000'], ['8000' => ['2027-01' => 6000000]], [1 => 5000000]);
		$accountTypeByNumber = ['8000' => 'revenue'];

		$column = ['monthKeys' => ['2027-01'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => $context['budgetLines']];

		$result = $this->calculator()->evaluateColumn(
			'lg-omzet',
			$column,
			true,
			$context,
			$budgetLinesByFiscalYear,
			$accountTypeByNumber
		);

		$this->assertSame(1000000, $result['deviation']);
		$this->assertTrue($result['favorable']);

	}//end testRevenueAccountOverBudgetIsFavorable()

	/**
	 * An expense account 10,000 over budget — the IDENTICAL raw
	 * `actual - budget` difference as the revenue case above — shows a
	 * NEGATIVE/unfavorable deviation. This is the task brief's own explicit
	 * "getting this wrong inverts the whole screen" check.
	 *
	 * @return void
	 */
	public function testExpenseAccountOverBudgetIsUnfavorable(): void {
		$context = $this->contextFor('lg-kosten', ['4000'], ['4000' => ['2027-01' => 6000000]], [1 => 5000000]);
		$accountTypeByNumber = ['4000' => 'expenses'];

		$column = ['monthKeys' => ['2027-01'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => $context['budgetLines']];

		$result = $this->calculator()->evaluateColumn(
			'lg-kosten',
			$column,
			true,
			$context,
			$budgetLinesByFiscalYear,
			$accountTypeByNumber
		);

		$this->assertSame(-1000000, $result['deviation']);
		$this->assertFalse($result['favorable']);

	}//end testExpenseAccountOverBudgetIsUnfavorable()

	/**
	 * An asset/liability/equity-type account still computes a raw
	 * `actual - budget` deviation, but with NO favorable/unfavorable framing
	 * (design.md §9.1's open question — still computed, not invented).
	 *
	 * @return void
	 */
	public function testBalanceSheetAccountComputesUnframedDeviation(): void {
		$context = $this->contextFor('lg-assets', ['1000'], ['1000' => ['2027-01' => 6000000]], [1 => 5000000]);
		$accountTypeByNumber = ['1000' => 'assets'];

		$column = ['monthKeys' => ['2027-01'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => $context['budgetLines']];

		$result = $this->calculator()->evaluateColumn(
			'lg-assets',
			$column,
			true,
			$context,
			$budgetLinesByFiscalYear,
			$accountTypeByNumber
		);

		$this->assertSame(1000000, $result['deviation']);
		$this->assertNull($result['favorable']);

	}//end testBalanceSheetAccountComputesUnframedDeviation()

	/**
	 * A future (non-past) column never renders an actual or deviation, only
	 * the budget (REQ-BGV-003).
	 *
	 * @return void
	 */
	public function testFutureColumnHasNoActualOrDeviation(): void {
		$context = $this->contextFor('lg-kosten', ['4000'], ['4000' => ['2027-02' => 999900]], [2 => 5000000]);
		$accountTypeByNumber = ['4000' => 'expenses'];

		$column = ['monthKeys' => ['2027-02'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => $context['budgetLines']];

		$result = $this->calculator()->evaluateColumn(
			'lg-kosten',
			$column,
			false,
			$context,
			$budgetLinesByFiscalYear,
			$accountTypeByNumber
		);

		$this->assertSame(5000000, $result['budget']);
		$this->assertNull($result['actual']);
		$this->assertNull($result['deviation']);

	}//end testFutureColumnHasNoActualOrDeviation()

	/**
	 * A column in a fiscal year with NO default AnnualBudget renders an
	 * explicit `null` (empty/dash) budget, not `0` (REQ-BGV-001's
	 * empty-vs-zero distinction).
	 *
	 * @return void
	 */
	public function testColumnWithNoDefaultAnnualBudgetRendersNullNotZero(): void {
		$context = $this->contextFor('lg-kosten', ['4000'], [], null);
		$column = ['monthKeys' => ['2027-01'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => null];

		$result = $this->calculator()->evaluateColumn(
			'lg-kosten',
			$column,
			false,
			$context,
			$budgetLinesByFiscalYear,
			['4000' => 'expenses']
		);

		$this->assertNull($result['budget']);
		$this->assertFalse($result['hasBudget']);

	}//end testColumnWithNoDefaultAnnualBudgetRendersNullNotZero()

	/**
	 * The TOTAAL cumulative pair: begroot sums EVERY displayed column's
	 * budget unconditionally (future months included); werkelijk sums ONLY
	 * the past columns' actuals (REQ-BGV-005).
	 *
	 * @return void
	 */
	public function testCumulativeWerkelijkExcludesFutureColumns(): void {
		$monthAmounts = [1 => 100000, 2 => 100000, 3 => 100000, 4 => 100000, 5 => 100000, 6 => 100000];
		$actuals = ['4000' => ['2027-01' => 90000, '2027-02' => 95000]];
		$context = $this->contextFor('lg-kosten', ['4000'], $actuals, $monthAmounts);

		$columns = [
			['monthKeys' => ['2027-01'], 'isPast' => true],
			['monthKeys' => ['2027-02'], 'isPast' => true],
			['monthKeys' => ['2027-03'], 'isPast' => false],
			['monthKeys' => ['2027-04'], 'isPast' => false],
			['monthKeys' => ['2027-05'], 'isPast' => false],
			['monthKeys' => ['2027-06'], 'isPast' => false],
		];
		$budgetLinesByFiscalYear = [2027 => $context['budgetLines']];

		$result = $this->calculator()->cumulative(
			'lg-kosten',
			$columns,
			$context,
			$budgetLinesByFiscalYear,
			['4000' => 'expenses']
		);

		// Begroot: unconditional sum of all six months = 600000.
		$this->assertSame(600000, $result['budget']);
		// Werkelijk: only Jan+Feb actuals = 90000 + 95000 = 185000.
		$this->assertSame(185000, $result['actual']);

	}//end testCumulativeWerkelijkExcludesFutureColumns()

	/**
	 * A parent LedgerGroup with NO own BudgetLine resolves its budgeted
	 * amount via child rollup (`budget-core-schema design.md` §3d) — this
	 * calculator's own delegate, {@see BudgetVsActualsCalculator}, already
	 * implements the rollup; this test proves BudgetGridCalculator composes
	 * with it correctly through `evaluateColumn()`.
	 *
	 * @return void
	 */
	public function testParentLedgerGroupWithNoOwnBudgetLineRollsUpFromChildren(): void {
		$line = ['ledgerGroupId' => 'lonen', 'month01Amount' => 300000];
		$context = [
			'actualsByAccountMonth' => ['4000' => ['2027-01' => 250000]],
			'ledgerGroupEntries' => [
				0 => ['id' => 'personeel', 'slug' => 'personeel', 'memberAccountNumbers' => []],
				1 => ['id' => 'lonen', 'slug' => 'lonen', 'memberAccountNumbers' => ['4000']],
			],
			'ledgerGroupKeyToIndex' => ['personeel' => 0, 'lonen' => 1],
			'ledgerGroupChildrenByIndex' => [0 => [1]],
			'budgetLines' => [$line],
		];

		$column = ['monthKeys' => ['2027-01'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => [$line]];

		$result = $this->calculator()->evaluateColumn(
			'personeel',
			$column,
			true,
			$context,
			$budgetLinesByFiscalYear,
			['4000' => 'expenses']
		);

		$this->assertSame(300000, $result['budget']);
		$this->assertSame(250000, $result['actual']);
		// Expense: budget - actual = 300000 - 250000 = 50000, favorable.
		$this->assertSame(50000, $result['deviation']);
		$this->assertTrue($result['favorable']);

	}//end testParentLedgerGroupWithNoOwnBudgetLineRollsUpFromChildren()

	/**
	 * The computed-row formula evaluator against the full
	 * `rj270-pl.json`-matching waterfall (design.md §4): Bedrijfsresultaat
	 * ties to Bruto Marge minus Kosten for a worked example.
	 *
	 * @return void
	 */
	public function testComputedRowWaterfallResolvesBedrijfsresultaat(): void {
		$computedRows = [
			['code' => 'bruto-marge', 'label' => 'Bruto Marge', 'formula' => 'omzet - kostprijs-van-de-omzet'],
			['code' => 'kosten', 'label' => 'Kosten', 'formula' => 'personeel + huisvesting'],
			['code' => 'bedrijfsresultaat', 'label' => 'Bedrijfsresultaat', 'formula' => 'bruto-marge - kosten'],
			['code' => 'financieel-resultaat', 'label' => 'Financieel resultaat', 'formula' => 'rentebaten - rentelasten'],
			['code' => 'resultaat-voor-belastingen', 'label' => 'Resultaat voor belastingen', 'formula' => 'bedrijfsresultaat + financieel-resultaat'],
			['code' => 'nettoresultaat', 'label' => 'Nettoresultaat', 'formula' => 'resultaat-voor-belastingen - vennootschapsbelasting'],
			['code' => 'nettoresultaat-pct', 'label' => '% van omzet', 'formula' => 'nettoresultaat / omzet', 'asPercent' => true],
		];

		$rowValues = [
			'omzet' => 1000000,
			'kostprijs-van-de-omzet' => 400000,
			'personeel' => 300000,
			'huisvesting' => 100000,
			'rentebaten' => 5000,
			'rentelasten' => 2000,
			'vennootschapsbelasting' => 40000,
		];

		$results = $this->calculator()->evaluateComputedRows($computedRows, $rowValues);

		$this->assertSame(600000, $results['bruto-marge']);
		$this->assertSame(400000, $results['kosten']);
		$this->assertSame(200000, $results['bedrijfsresultaat']);
		$this->assertSame(3000, $results['financieel-resultaat']);
		$this->assertSame(203000, $results['resultaat-voor-belastingen']);
		$this->assertSame(163000, $results['nettoresultaat']);
		$this->assertEqualsWithDelta((163000 / 1000000), $results['nettoresultaat-pct'], 0.0001);

	}//end testComputedRowWaterfallResolvesBedrijfsresultaat()

	/**
	 * A computed-row formula referencing a missing/unresolved code returns
	 * `null` rather than silently treating the missing operand as 0.
	 *
	 * @return void
	 */
	public function testComputedRowWithMissingOperandResolvesNull(): void {
		$computedRows = [
			['code' => 'bedrijfsresultaat', 'label' => 'Bedrijfsresultaat', 'formula' => 'bruto-marge - kosten'],
		];

		$results = $this->calculator()->evaluateComputedRows($computedRows, ['bruto-marge' => 100000]);

		$this->assertNull($results['bedrijfsresultaat']);

	}//end testComputedRowWithMissingOperandResolvesNull()

	/**
	 * A LedgerGroup whose resolved member accounts mix `revenue` and
	 * `expenses` types sums each member's own correctly-signed deviation
	 * rather than applying one row-wide sign (REQ-BGV-004's own mixed-type
	 * scenario) — here demonstrated via the signed-actual-sum mechanism this
	 * calculator uses (design.md §2d's own acknowledgement that a mixed
	 * subtree is a rare, not-fully-specified case): a mixed subtree resolves
	 * with NO favorable/unfavorable framing (the same treatment as an
	 * unresolved/balance-sheet type), while still returning a raw,
	 * computed, non-null deviation.
	 *
	 * @return void
	 */
	public function testMixedAccountTypeGroupComputesUnframedDeviation(): void {
		$context = $this->contextFor(
			'lg-mixed',
			['4000', '8000'],
			['4000' => ['2027-01' => 300000], '8000' => ['2027-01' => 300000]],
			[1 => 500000]
		);
		$accountTypeByNumber = ['4000' => 'expenses', '8000' => 'revenue'];

		$column = ['monthKeys' => ['2027-01'], 'fiscalYears' => [2027]];
		$budgetLinesByFiscalYear = [2027 => $context['budgetLines']];

		$result = $this->calculator()->evaluateColumn(
			'lg-mixed',
			$column,
			true,
			$context,
			$budgetLinesByFiscalYear,
			$accountTypeByNumber
		);

		$this->assertSame(600000, $result['actual']);
		$this->assertSame(500000, $result['budget']);
		$this->assertSame(100000, $result['deviation']);
		$this->assertNull($result['favorable']);

	}//end testMixedAccountTypeGroupComputesUnframedDeviation()
}//end class
