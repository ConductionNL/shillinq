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
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Input refusals, the multi-year window and the refusal's template and parameters.
 */
#[CoversClass(BudgetEditingService::class)]
#[CoversClass(BudgetEditRefusedException::class)]
#[UsesClass(ObjectIdentifier::class)]
final class BudgetEditingServiceRefusalsTest extends TestCase {
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
	 * Months outside 1 to 12.
	 *
	 * @return array<string,array{int}>
	 */
	public static function badMonths(): array {
		return ['zero' => [0], 'thirteen' => [13], 'negative' => [-1]];
	}//end badMonths()

	/**
	 * A month outside 1 to 12 is refused before anything is stored.
	 *
	 * @param int $month The month typed.
	 *
	 * @return void
	 */
	#[DataProvider('badMonths')]
	public function testAMonthOutsideTheYearIsRefused(int $month): void {
		try {
			$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], $month, 1, 20000000);
			$this->fail('A month outside the year was accepted.');
		} catch (BudgetEditRefusedException $e) {
			$this->assertSame('Choose a month from 1 to 12.', $e->getMessage());
			$this->assertSame('Choose a month from 1 to 12.', $e->getTemplate());
			$this->assertSame([], $e->getParameters());
		}

		$this->assertSame(20000000, $this->store->setSchema('BudgetLine')->find($this->ids['line-pers'])->getObject()['month01Amount']);
	}//end testAMonthOutsideTheYearIsRefused()

	/**
	 * December is the last month that can be typed, and only December changes.
	 *
	 * @return void
	 */
	public function testDecemberIsTyped(): void {
		$row = $this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 12, 1, 20000000);

		$this->assertSame(array_merge(array_fill(0, 11, 20000000), [1]), $row['months']);
		$this->assertSame((11 * 20000000) + 1, $row['total']);
		$this->assertSame($this->ids['line-pers'], $row['lineId']);
		$this->assertTrue($row['editable']);
	}//end testDecemberIsTyped()

	/**
	 * A ledger group of another administration, or none at all, is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownLedgerGroupIsRefused(): void {
		foreach (['lg-other', 'no-such-group'] as $groupId) {
			try {
				$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $groupId, 1, 1, 0);
				$this->fail('An unknown ledger group was accepted.');
			} catch (BudgetEditRefusedException $e) {
				$this->assertSame('This ledger group does not exist.', $e->getMessage());
			}
		}

		$this->assertCount(2, $this->all('BudgetLine'));
	}//end testAnUnknownLedgerGroupIsRefused()

	/**
	 * The stale refusal keeps its template and the formatted amount apart.
	 *
	 * @return void
	 */
	public function testTheStaleRefusalCarriesItsParameters(): void {
		try {
			$this->service()->saveCell('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 2, 1, 0);
			$this->fail('A stale save was accepted.');
		} catch (BudgetEditRefusedException $e) {
			$this->assertSame('Someone else changed this amount to %1$s. The cell now shows their value.', $e->getTemplate());
			$this->assertSame(['EUR 200.000,00'], $e->getParameters());
		}
	}//end testTheStaleRefusalCarriesItsParameters()

	/**
	 * Spreading into a closed budget is refused.
	 *
	 * @return void
	 */
	public function testSpreadingIntoAClosedBudgetIsRefused(): void {
		$this->store->setSchema('AnnualBudget')->patchObject($this->ids['budget-2026'], ['state' => 'closed']);

		$this->expectException(BudgetEditRefusedException::class);
		$this->expectExceptionMessage('This budget is closed. Its amounts can no longer change.');
		$this->service()->spread('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-personeel'], 1200, array_fill(0, 12, 20000000));
	}//end testSpreadingIntoAClosedBudgetIsRefused()

	/**
	 * Spreading a group without a line creates a manual line from zero.
	 *
	 * @return void
	 */
	public function testSpreadingAnEmptyGroupCreatesALine(): void {
		$row = $this->service()->spread('adm-voorbeeld', $this->ids['budget-2026'], $this->ids['lg-lasten'], 1205, array_fill(0, 12, 0));

		$this->assertSame(array_merge(array_fill(0, 11, 100), [105]), $row['months']);
		$this->assertSame(1205, $row['total']);
		$this->assertSame('manual', $row['source']);
		$this->assertCount(3, $this->all('BudgetLine'));
	}//end testSpreadingAnEmptyGroupCreatesALine()

	/**
	 * Percentages outside -100 to 1000.
	 *
	 * @return array<string,array{float}>
	 */
	public static function badPercentages(): array {
		return ['below -100' => [-100.5], 'above 1000' => [1000.01]];
	}//end badPercentages()

	/**
	 * A percentage outside -100 to 1000 is refused and no budget is created.
	 *
	 * @param float $percentage The percentage entered.
	 *
	 * @return void
	 */
	#[DataProvider('badPercentages')]
	public function testAPercentageOutOfRangeIsRefused(float $percentage): void {
		try {
			$this->service()->startNextYear('adm-voorbeeld', $this->ids['budget-2026'], $percentage);
			$this->fail('An out-of-range percentage was accepted.');
		} catch (BudgetEditRefusedException $e) {
			$this->assertSame('Enter a percentage from -100 to 1000.', $e->getMessage());
		}

		$this->assertCount(2, $this->all('AnnualBudget'));
	}//end testAPercentageOutOfRangeIsRefused()

	/**
	 * A decrease is rounded per month and the note names the percentage without trailing zeros.
	 *
	 * @return void
	 */
	public function testStartingNextYearLower(): void {
		$created = $this->service()->startNextYear('adm-voorbeeld', $this->ids['budget-2026'], -2.5);

		$copies = array_values(array_filter($this->all('BudgetLine'), static fn (array $l): bool => $l['annualBudgetId'] === $created['id']));
		$this->assertCount(1, $copies);
		$this->assertSame(19500000, $copies[0]['month01Amount']);
		$this->assertSame('Started from Begroting 2026 at -2.5%', $copies[0]['notes']);
		$this->assertSame($this->ids['lg-personeel'], $copies[0]['ledgerGroupId']);
	}//end testStartingNextYearLower()

	/**
	 * Starting from another administration's budget is refused.
	 *
	 * @return void
	 */
	public function testStartingFromAnotherAdministrationsBudgetIsRefused(): void {
		$this->expectExceptionMessage('This budget does not exist.');
		$this->service()->startNextYear('adm-voorbeeld', 'budget-other', 3.0);
	}//end testStartingFromAnotherAdministrationsBudgetIsRefused()

	/**
	 * Only years from the first one up to four ahead show; a year's default budget wins;
	 * every budget is listed newest first.
	 *
	 * @return void
	 */
	public function testTheMultiYearWindowAndDefaults(): void {
		$budgets = $this->store->setSchema('AnnualBudget');
		$budgets->saveObject(
			['id' => 'b-2025', 'administrationId' => 'adm-voorbeeld',
				'fiscalYear' => 2025, 'name' => 'Begroting 2025', 'isDefault' => true, 'state' => 'closed']
		);
		$budgets->saveObject(
			['id' => 'b-2031', 'administrationId' => 'adm-voorbeeld', 'fiscalYear' => 2031, 'name' => 'Begroting 2031', 'isDefault' => true]
		);
		$budgets->saveObject(
			['id' => 'b-2028-alt', 'administrationId' => 'adm-voorbeeld', 'fiscalYear' => 2028, 'name' => 'Alternatief', 'isDefault' => false]
		);
		$budgets->saveObject(
			['id' => 'b-2028', 'administrationId' => 'adm-voorbeeld',
				'fiscalYear' => 2028, 'name' => 'Begroting 2028', 'isDefault' => true, 'state' => 'active']
		);
		$budgets->saveObject(
			['id' => 'b-2030', 'administrationId' => 'adm-voorbeeld', 'fiscalYear' => 2030, 'name' => 'Begroting 2030', 'isDefault' => false]
		);

		$view = $this->service()->multiYear('adm-voorbeeld', 2026);
		$years = $view['years'];

		$this->assertSame([2026, 2028, 2030], array_column($years, 'fiscalYear'));
		$this->assertSame('Begroting 2028', $years[1]['name']);
		$this->assertSame('active', $years[1]['state']);
		$this->assertSame('draft', $years[2]['state']);
		$this->assertSame(
			[
				[2031, 'Begroting 2031'],
				[2030, 'Begroting 2030'],
				[2028, 'Alternatief'],
				[2028, 'Begroting 2028'],
				[2026, 'Begroting 2026'],
				[2025, 'Begroting 2025'],
			],
			array_map(static fn (array $b): array => [$b['fiscalYear'], $b['name']], $view['budgets'])
		);
		$this->assertSame(['2026' => 240000000, '2028' => 0, '2030' => 0], $view['rows'][1]['amounts']);
		$this->assertSame(['2026' => 0, '2028' => 0, '2030' => 0], $view['rows'][0]['amounts']);
	}//end testTheMultiYearWindowAndDefaults()
}//end class
