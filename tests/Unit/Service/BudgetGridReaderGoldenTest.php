<?php

/**
 * Golden test for BudgetGridReader's grid load, row tree and past-column keys.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetGridReader;
use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins loadGrid(), rowsFor() and pastColumnKeys() output on a varied fixture.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetGridReaderGoldenTest extends TestCase {

	/**
	 * Every output matches the golden fixture.
	 *
	 * @return void
	 */
	public function testOutputsMatchGolden(): void {
		$reader = $this->reader(data: $this->data());
		$result = [
			'gridMonthCrossYear' => $reader->loadGrid('adm-1', '2026-11', '2027-02', 'month'),
			'gridQuarter' => $reader->loadGrid('adm-1', '2027-01', '2027-06', 'quarter'),
			'gridOtherAdmin' => $reader->loadGrid('adm-2', '2027-01', '2027-01', 'month'),
			'gridNoBudgets' => $this->reader(data: ['Account' => $this->data()['Account']])->loadGrid('adm-1', '2027-01', '2027-02', 'month'),
			'rows' => $reader->rowsFor('adm-1'),
			'past' => $reader->pastColumnKeys(
				[
					['key' => 'a', 'startDate' => '2027-01-01', 'endDate' => '2027-01-31'],
					['key' => 'b', 'startDate' => '2027-02-01', 'endDate' => '2027-02-28'],
					['key' => 'c', 'startDate' => '2027-03-01', 'endDate' => '2027-03-31'],
					['key' => 'd', 'startDate' => '2027-01-01', 'endDate' => '2027-03-31'],
					['key' => 'e', 'startDate' => '2026-12-15', 'endDate' => '2027-01-10'],
				],
				[
					['state' => 'closed', 'startDate' => '2027-01-01', 'endDate' => '2027-01-31'],
					['state' => 'open', 'startDate' => '2027-02-01', 'endDate' => '2027-02-28'],
					['state' => 'locked', 'startDate' => '2027-03-01', 'endDate' => ''],
					['state' => 'closing', 'startDate' => '2027-01-01', 'endDate' => '2027-12-31'],
					['startDate' => '2027-01-01', 'endDate' => '2027-12-31'],
					['state' => 'locked', 'startDate' => '2027-02-01', 'endDate' => '2027-02-28'],
				]
			),
			'pastNoPeriods' => $reader->pastColumnKeys([['key' => 'a', 'startDate' => '2027-01-01', 'endDate' => '2027-01-31']], []),
		];

		$file = __DIR__.'/fixtures/budget-grid-reader.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('BUDGET_GRID_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testOutputsMatchGolden()

	/**
	 * A reader over an in-memory store.
	 *
	 * @param array $data Schema => rows.
	 *
	 * @return BudgetGridReader
	 */
	private function reader(array $data): BudgetGridReader {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$store = new FilteredObjectServiceStub($data);
		return new BudgetGridReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $store,
			budgetVsActualsReader: new BudgetVsActualsReader(appConfig: $appConfig, logger: new NullLogger(), objectService: $store),
		);

	}//end reader()

	/**
	 * The fixture: a three-level tree with an unresolved parent, ordering
	 * ties, id-only and slug-only groups; two fiscal years with one default
	 * budget each (2026 none); accounts with and without type or number.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function data(): array {
		$group = static fn (array $g): array => array_merge(
			['administrationId' => 'adm-1', 'accountRanges' => [], 'includedAccountNumbers' => [], 'excludedAccountNumbers' => []],
			$g
		);
		return [
			'LedgerGroup' => [
				$group(['id' => 'g-root-b', '@self' => ['slug' => 'root-b'], 'code' => 'B', 'name' => 'Root B', 'order' => 20]),
				$group(['id' => 'g-root-a', '@self' => ['slug' => 'root-a'], 'code' => 'A', 'name' => 'Root A', 'order' => 10, 'parentLedgerGroupId' => null]),
				$group(['id' => 'g-child-2', 'code' => 'A2', 'name' => 'Child 2', 'order' => 5, 'parentLedgerGroupId' => 'root-a']),
				$group(
					[
						'id' => 'g-child-1',
						'code' => 'A1',
						'name' => 'Child 1',
						'order' => 1,
						'parentLedgerGroupId' => 'g-root-a',
						'accountRanges' => [['from' => '4000', 'to' => '4099']],
					]
				),
				$group(
					[
						'@self' => ['id' => 'g-grand', 'slug' => 'grand'],
						'code' => 'A11',
						'name' => 'Grandchild',
						'order' => '3',
						'parentLedgerGroupId' => 'g-child-1',
					]
				),
				$group(['slug' => 'orphan', 'name' => 'Orphan', 'parentLedgerGroupId' => 'missing']),
				$group(['id' => 'g-empty-parent', 'name' => 'Empty parent', 'order' => 15, 'parentLedgerGroupId' => '']),
				$group(['id' => 'same', 'slug' => 'same', 'name' => 'Same id and slug', 'order' => 10]),
				$group(['id' => 'g-other', 'administrationId' => 'adm-2', 'name' => 'Other admin']),
			],
			'Account' => [
				['accountNumber' => '4000', 'name' => 'Salarissen', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
				['accountNumber' => '8000', 'name' => 'Omzet', 'accountType' => '', 'administrationId' => 'adm-1'],
				['accountNumber' => '', 'name' => 'Nameless', 'accountType' => 'assets', 'administrationId' => 'adm-1'],
				['accountNumber' => '1000', 'name' => 'Kas', 'administrationId' => 'adm-1'],
			],
			'FiscalPeriod' => [
				['administrationId' => 'adm-1', 'startDate' => '2026-11-01', 'endDate' => '2026-11-30', 'fiscalYear' => 2026, 'state' => 'locked'],
				['administrationId' => 'adm-1', 'startDate' => '2027-01-01', 'endDate' => '2027-03-31', 'fiscalYear' => 2027, 'state' => 'closed'],
				['administrationId' => 'adm-1', 'startDate' => '2027-04-01', 'endDate' => '2027-04-30', 'fiscalYear' => 2027, 'state' => 'open'],
			],
			'AnnualBudget' => [
				['id' => 'ab-2027', 'administrationId' => 'adm-1', 'fiscalYear' => 2027, 'isDefault' => true],
				['id' => 'ab-2027-alt', 'administrationId' => 'adm-1', 'fiscalYear' => 2027, 'isDefault' => false],
				['id' => 'ab-2026-alt', 'administrationId' => 'adm-1', 'fiscalYear' => 2026, 'isDefault' => false],
				['id' => 'ab-other', 'administrationId' => 'adm-2', 'fiscalYear' => 2027, 'isDefault' => true],
			],
			'BudgetLine' => [
				['id' => 'bl-1', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'g-child-1', 'month01Amount' => 100],
				['id' => 'bl-2', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'root-b', 'month02Amount' => 5.5],
				['id' => 'bl-3', 'annualBudgetId' => 'ab-2027-alt', 'ledgerGroupId' => 'g-child-1', 'month01Amount' => 999],
				['id' => 'bl-4', 'annualBudgetId' => 'ab-other', 'ledgerGroupId' => 'g-other', 'month01Amount' => 7],
				['id' => 'bl-5', 'annualBudgetId' => '', 'ledgerGroupId' => 'g-child-1', 'month01Amount' => 1],
			],
		];

	}//end data()
}//end class
