<?php

/**
 * Unit tests for the MapExpenseCategories repair step.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Repair;

use OCA\Shillinq\Repair\MapExpenseCategories;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-ECM-001 seeding and REQ-ECM-002 mapping: an exact code, a Dutch name,
 * an English name and an unknown text.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(MapExpenseCategories::class)]
#[UsesClass(ObjectIdentifier::class)]
final class MapExpenseCategoriesTest extends TestCase {
	/**
	 * Everything saved, as [schema, object].
	 *
	 * @var list<array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The store behind the step.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The receipts' text maps onto codes; an unknown text is set to
	 * uncategorised and listed with its original text.
	 *
	 * @return void
	 */
	public function testReceiptCategoriesAreMapped(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with(
			'Shillinq: expense categories — 7 categories seeded, 1 administrations given expense accounts, 5 receipts mapped, 2 left uncategorised.'
		);
		$output->expects($this->once())->method('warning')->with(
			"Shillinq: receipts whose category matched no expense category, now uncategorised: R-5: 'Kunstwerk'; R-7: 'buitenland'"
		);

		$this->step()->run(output: $output);

		$this->assertSame(
			[
				'r-2' => 'travel-public',
				'r-3' => 'office',
				'r-4' => 'meals',
				'r-5' => 'uncategorised',
				'r-7' => 'uncategorised',
			],
			$this->savedCategories()
		);
	}//end testReceiptCategoriesAreMapped()

	/**
	 * Categories are seeded under the id the chart carries, only where the
	 * chart has the account as an expense account, and an existing one stays.
	 *
	 * @return void
	 */
	public function testCategoriesAndSettingsAreSeededForTheChart(): void {
		$this->step()->run(output: $this->createMock(IOutput::class));

		$seeded = [];
		foreach ($this->savedOf(schema: 'ExpenseCategory') as $category) {
			$seeded[$category['code']] = [$category['expenseAccount'], $category['administrationId'], $category['vatDeductionRule'], $category['active']];
		}

		$this->assertSame(
			[
				'travel-public' => ['4510', 'ADM-001', 'full', true],
				'fuel' => ['4210', 'ADM-001', 'full', true],
				'office' => ['4310', 'ADM-001', 'full', true],
				'phone' => ['4330', 'ADM-001', 'full', true],
				'software' => ['4340', 'ADM-001', 'full', true],
				'training' => ['4050', 'ADM-001', 'full', true],
				'other' => ['4060', 'ADM-001', 'full', true],
			],
			$seeded,
			'meals already exists; travel-abroad has no account 4520 in this chart'
		);
		$this->assertSame(['nl' => 'Software en abonnementen', 'en' => 'Software and subscriptions'], $this->savedOf(schema: 'ExpenseCategory')[4]['name']);

		$administrations = $this->savedOf(schema: 'Administration');
		$this->assertCount(1, $administrations, 'the administration without a chart is not touched');
		$this->assertSame(
			['adm-uuid-1', '4999', '4060', '2200'],
			[
				$administrations[0]['id'],
				$administrations[0]['mileageAccount'],
				$administrations[0]['perDiemAccount'],
				$administrations[0]['expensePayableAccount'],
			],
			'a mileage account already set is kept'
		);
	}//end testCategoriesAndSettingsAreSeededForTheChart()

	/**
	 * A second run changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$step = $this->step();
		$step->run(output: $this->createMock(IOutput::class));
		$this->saved = [];

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with(
			'Shillinq: expense categories — 0 categories seeded, 0 administrations given expense accounts, 0 receipts mapped, 0 left uncategorised.'
		);
		$output->expects($this->never())->method('warning');
		$step->run(output: $output);

		$this->assertSame([], $this->saved);
	}//end testASecondRunChangesNothing()

	/**
	 * The four matches of task 2.2, directly.
	 *
	 * @return void
	 */
	public function testCodeForMatchesCodeDutchEnglishAndNothing(): void {
		$categories = [
			['code' => 'meals', 'name' => ['nl' => 'Maaltijden en representatie', 'en' => 'Meals and entertainment']],
			['code' => 'travel-public', 'name' => ['nl' => 'Openbaar vervoer', 'en' => 'Public transport']],
		];
		$step = $this->step();

		$this->assertSame('meals', $step->codeFor(text: 'MEALS', categories: $categories, aliases: []));
		$this->assertSame('meals', $step->codeFor(text: ' maaltijden en representatie ', categories: $categories, aliases: []));
		$this->assertSame('travel-public', $step->codeFor(text: 'Public Transport', categories: $categories, aliases: []));
		$this->assertSame('travel-public', $step->codeFor(text: 'Trein', categories: $categories, aliases: ['trein' => 'travel-public']));
		$this->assertNull($step->codeFor(text: 'Kunstwerk', categories: $categories, aliases: ['trein' => 'travel-public']));
		$this->assertNull(
			$step->codeFor(text: 'laptop', categories: $categories, aliases: ['laptop' => 'office']),
			'an alias of a category the administration lacks does not match'
		);
	}//end testCodeForMatchesCodeDutchEnglishAndNothing()

	/**
	 * Without OpenRegister nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingRuns(): void {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn(false);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Shillinq: OpenRegister is not available; expense categories were not mapped.');

		$step = new MapExpenseCategories(
			objectService: new InMemoryObjectServiceStub(data: []),
			registerLocation: $location,
			logger: $this->createMock(LoggerInterface::class)
		);
		$step->run(output: $output);
	}//end testWithoutOpenRegisterNothingRuns()

	/**
	 * The step over two administrations, one on the RGS chart.
	 *
	 * @return MapExpenseCategories
	 */
	private function step(): MapExpenseCategories {
		$accounts = [];
		foreach (['4510', '4210', '4440', '4310', '4330', '4340', '4050', '4060', '4530'] as $number) {
			$accounts[] = ['id' => 'acc-' . $number, 'accountNumber' => $number, 'accountType' => 'expenses', 'administrationId' => 'ADM-001'];
		}

		$accounts[] = ['id' => 'acc-2200', 'accountNumber' => '2200', 'accountType' => 'liabilities', 'administrationId' => 'ADM-001'];

		$receipt = static fn (string $id, string $category): array => [
			'id' => 'r-' . $id, 'receiptNumber' => 'R-' . $id, 'category' => $category, 'administrationId' => 'ADM-001',
		];

		$this->saved = [];
		$this->store = new InMemoryObjectServiceStub(
			data: [
				'Administration' => [
					['id' => 'adm-uuid-1', 'administrationCode' => 'ADM-001', 'mileageAccount' => '4999'],
					['id' => 'adm-uuid-2', 'administrationCode' => 'ADM-002'],
				],
				'Account' => $accounts,
				'ExpenseCategory' => [
					[
						'id' => 'cat-meals', 'code' => 'meals', 'name' => ['nl' => 'Lunch en diner', 'en' => 'Meals'], 'expenseAccount' => '4440',
						'vatDeductionRule' => 'none', 'active' => true, 'administrationId' => 'ADM-001',
					],
				],
				'Receipt' => [
					$receipt('1', 'travel-public'),
					$receipt('2', 'Openbaar vervoer'),
					$receipt('3', 'Office supplies'),
					$receipt('4', 'Lunch'),
					$receipt('5', 'Kunstwerk'),
					$receipt('6', ''),
					$receipt('7', 'buitenland'),
					['id' => 'r-8', 'category' => 'Lunch', 'administrationId' => 'ADM-002'],
				],
			],
			saveSink: $this->saved
		);

		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn(true);
		$location->method('getRegisterSlug')->willReturn('shillinq');

		return new MapExpenseCategories(objectService: $this->store, registerLocation: $location, logger: $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * The categories saved on receipts, by receipt id.
	 *
	 * @return array<string, string>
	 */
	private function savedCategories(): array {
		$out = [];
		foreach ($this->savedOf(schema: 'Receipt') as $receipt) {
			$out[$receipt['id']] = $receipt['category'];
		}

		return $out;
	}//end savedCategories()

	/**
	 * The objects saved for one schema, in order.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function savedOf(string $schema): array {
		$out = [];
		foreach ($this->saved as $entry) {
			if ($entry['schema'] === $schema) {
				$out[] = $entry['object'];
			}
		}

		return $out;
	}//end savedOf()
}//end class
