<?php

/**
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Budget
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Budget;

use OCA\Shillinq\Budget\BudgetEditingService;
use OCA\Shillinq\Budget\BudgetEditRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Typing, spreading and multi-year budgeting (REQ-PBE-001 to REQ-PBE-003).
 */
final class BudgetEditingServiceTest extends TestCase {
	use BudgetEditingFixture;

	/**
	 * Seed Gemeente Voorbeeld.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->seed();
	}//end setUp()

	/**
	 * Rows in tree order; the manual line is editable, the projected one read-only naming its source.
	 *
	 * @return void
	 */
	public function testTheGridRowsNameWhatCanBeTyped(): void {
		$rows = $this->service()->lines('adm-voorbeeld', $this->ids['budget-2026'])['rows'];

		$this->assertSame(['LASTEN', 'PERS', 'AFSCHR'], array_column($rows, 'code'));
		$this->assertSame([0, 1, 1], array_column($rows, 'depth'));
		$this->assertSame([true, true, false], array_column($rows, 'editable'));
		$this->assertSame('projected', $rows[2]['source']);
		$this->assertSame(240000000, $rows[1]['total']);
	}//end testTheGridRowsNameWhatCanBeTyped()

	/**
	 * Typing a month into a group without a line creates a manual line the register accepts.
	 *
	 * @return void
	 */
	public function testTypingACellCreatesAManualLine(): void {
		$row = $this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-lasten'], 1, 20600000, 0);

		$this->assertSame(20600000, $row['months'][0]);
		$created = array_values(array_filter($this->all('BudgetLine'), fn (array $l): bool => $l['ledgerGroupId'] === $this->ids['lg-lasten']));
		$this->assertCount(1, $created);
		$this->assertSame('manual', $created[0]['source']);
		$this->assertSame([], $this->registerErrors('BudgetLine', $created[0]));
	}//end testTypingACellCreatesAManualLine()

	/**
	 * Typing over a stored manual month changes that month only.
	 *
	 * @return void
	 */
	public function testTypingOverAStoredMonth(): void {
		$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 1, 20600000, 20000000);

		$line = $this->store->setSchema('BudgetLine')->find($this->ids['line-pers'])->getObject();
		$this->assertSame(20600000, $line['month01Amount']);
		$this->assertSame(20000000, $line['month02Amount']);
		$this->assertSame([], $this->registerErrors('BudgetLine', $line));
	}//end testTypingOverAStoredMonth()

	/**
	 * A save from a stale value is refused naming the stored amount.
	 *
	 * @return void
	 */
	public function testAStaleSaveIsRefused(): void {
		try {
			$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 1, 1, 19000000);
			$this->fail('A stale save was accepted.');
		} catch (BudgetEditRefusedException $e) {
			$this->assertSame('Someone else changed this amount to EUR 200.000,00. The cell now shows their value.', $e->getMessage());
		}

		$this->assertSame(20000000, $this->store->setSchema('BudgetLine')->find($this->ids['line-pers'])->getObject()['month01Amount']);
	}//end testAStaleSaveIsRefused()

	/**
	 * A projected row cannot be typed over, and a closed budget cannot change.
	 *
	 * @return void
	 */
	public function testDerivedRowsAndClosedBudgetsAreRefused(): void {
		$this->expectExceptionMessage('This row comes from projected and cannot be typed over.');
		$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-afschr'], 1, 1, 500000);
	}//end testDerivedRowsAndClosedBudgetsAreRefused()

	/**
	 * A closed budget refuses a save.
	 *
	 * @return void
	 */
	public function testAClosedBudgetIsRefused(): void {
		$this->store->setSchema('AnnualBudget')->patchObject($this->ids['budget-2026'], ['state' => 'closed']);

		$this->assertFalse($this->service()->lines('adm-voorbeeld', $this->ids['budget-2026'])['rows'][1]['editable']);
		$this->expectExceptionMessage('This budget is closed. Its amounts can no longer change.');
		$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 1, 1, 20000000);
	}//end testAClosedBudgetIsRefused()

	/**
	 * Another administration's budget does not exist for this one.
	 *
	 * @return void
	 */
	public function testAnotherAdministrationsBudgetIsRefused(): void {
		$this->expectExceptionMessage('This budget does not exist.');
		$this->service()->lines('adm-voorbeeld', 'budget-other');
	}//end testAnotherAdministrationsBudgetIsRefused()

	/**
	 * Spreading EUR 2,472,000 gives EUR 206,000 a month; a remainder lands on December.
	 *
	 * @return void
	 */
	public function testSpreadingAYear(): void {
		$row = $this->service()->spread('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 247200000, array_fill(0, 12, 20000000));

		$this->assertSame(array_fill(0, 12, 20600000), $row['months']);
		$this->assertSame(array_merge(array_fill(0, 11, 8), [12]), BudgetEditingService::spreadAmounts(100));
		$this->assertSame(100, array_sum(BudgetEditingService::spreadAmounts(100)));

		$this->expectExceptionMessage('Someone else changed this row. It now shows their values.');
		$this->service()->spread('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 1200, array_fill(0, 12, 20000000));
	}//end testSpreadingAYear()

	/**
	 * Start 2027 at three percent more: a draft default budget and Personeel at EUR 2,472,000.
	 *
	 * @return void
	 */
	public function testStartingNextYearAtThreePercent(): void {
		$service = $this->service();
		$created = $service->startNextYear('adm-voorbeeld', $this->ids['budget-2026'], 3.0);

		$this->assertSame(2027, $created['fiscalYear']);
		$this->assertSame('draft', $created['state']);
		$this->assertSame([], $this->registerErrors('AnnualBudget', $created));
		$copies = array_values(array_filter($this->all('BudgetLine'), static fn (array $l): bool => $l['annualBudgetId'] === $created['id']));
		$this->assertCount(1, $copies, 'Only the manual line is copied, the projected one is not.');
		$this->assertSame([], $this->registerErrors('BudgetLine', $copies[0]));

		$view = $service->multiYear('adm-voorbeeld', 2026);
		$this->assertSame([2026, 2027], array_column($view['years'], 'fiscalYear'));
		$this->assertSame(['2026' => 240000000, '2027' => 247200000], $view['rows'][1]['amounts']);
		$this->assertSame(['2026' => 6000000, '2027' => 0], $view['rows'][2]['amounts']);

		$this->expectExceptionMessage('2027 already has a budget: Budget 2027.');
		$service->startNextYear('adm-voorbeeld', $this->ids['budget-2026'], 3.0);
	}//end testStartingNextYearAtThreePercent()
}//end class
