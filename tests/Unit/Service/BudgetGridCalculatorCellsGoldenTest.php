<?php

/**
 * Golden test for BudgetGridCalculator's column and cumulative cells.
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

use OCA\Shillinq\Service\BudgetGridCalculator;
use OCA\Shillinq\Service\BudgetGridReader;
use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use OCA\Shillinq\Service\BudgetVsActualsReader;
use OCA\Shillinq\Tests\Unit\Service\Support\FilteredObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins evaluateColumn() and cumulative() for every group over grids the
 * reader loads, across a fiscal-year boundary with one year unbudgeted.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetGridCalculatorCellsGoldenTest extends TestCase {

	/**
	 * Every cell matches the golden fixture.
	 *
	 * @return void
	 */
	public function testCellsMatchGolden(): void {
		$calculator = new BudgetGridCalculator(new BudgetVsActualsCalculator());
		$result = [];
		$grids = [
			'monthCrossYear' => ['2026-11', '2027-03', 'month'],
			'quarter' => ['2027-01', '2027-06', 'quarter'],
			'unbudgeted' => ['2026-10', '2026-12', 'month'],
		];
		foreach ($grids as $name => [$start, $end, $granularity]) {
			$grid = $this->reader()->loadGrid('adm-1', $start, $end, $granularity);
			foreach (['g-income', 'g-costs', 'g-wages', 'g-rent', 'g-balance', 'missing'] as $key) {
				foreach ($grid['columns'] as $column) {
					foreach ([true, false] as $isPast) {
						$result[$name][$key][$column['key'].':'.var_export($isPast, true)] = $calculator->evaluateColumn(
							$key,
							$column,
							$isPast,
							$grid['bvaContext'],
							$grid['budgetLinesByFiscalYear'],
							$grid['accountTypeByNumber']
						);
					}
				}

				$result[$name][$key]['TOTAAL'] = $calculator->cumulative(
					$key,
					$grid['columns'],
					$grid['bvaContext'],
					$grid['budgetLinesByFiscalYear'],
					$grid['accountTypeByNumber']
				);
			}
		}

		$file = __DIR__.'/fixtures/budget-grid-calculator-cells.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('GRID_CELLS_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testCellsMatchGolden()

	/**
	 * A reader over the fixture.
	 *
	 * @return BudgetGridReader
	 */
	private function reader(): BudgetGridReader {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$store = new FilteredObjectServiceStub($this->data());
		return new BudgetGridReader(
			appConfig: $appConfig,
			logger: new NullLogger(),
			objectService: $store,
			budgetVsActualsReader: new BudgetVsActualsReader(appConfig: $appConfig, logger: new NullLogger(), objectService: $store),
		);

	}//end reader()

	/**
	 * Revenue, a cost parent with two leaves (one budgeted only on the
	 * parent), a balance-sheet group; budgets for 2027 only; actuals on both
	 * sides of the year boundary.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function data(): array {
		$group = static fn (string $id, ?string $parent, array $ranges): array => [
			'id' => $id,
			'administrationId' => 'adm-1',
			'code' => $id,
			'name' => $id,
			'order' => 0,
			'parentLedgerGroupId' => $parent,
			'accountRanges' => $ranges,
			'includedAccountNumbers' => [],
			'excludedAccountNumbers' => [],
		];
		$tx = static fn (string $id, string $date, string $state): array => [
			'id' => $id,
			'transactionNumber' => $id,
			'administrationId' => 'adm-1',
			'state' => $state,
			'postingDate' => $date,
		];
		return [
			'LedgerGroup' => [
				$group('g-income', null, [['from' => '8000', 'to' => '8099']]),
				$group('g-costs', null, []),
				$group('g-wages', 'g-costs', [['from' => '4000', 'to' => '4099']]),
				$group('g-rent', 'g-costs', [['from' => '4300', 'to' => '4399']]),
				$group('g-balance', null, [['from' => '1000', 'to' => '1099']]),
			],
			'Account' => [
				['accountNumber' => '8000', 'accountType' => 'revenue', 'administrationId' => 'adm-1'],
				['accountNumber' => '4000', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
				['accountNumber' => '4300', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
				['accountNumber' => '1000', 'accountType' => 'assets', 'administrationId' => 'adm-1'],
			],
			'GLTransaction' => [
				$tx('tx-1', '2026-12-10', 'posted'),
				$tx('tx-2', '2027-01-15', 'posted'),
				$tx('tx-3', '2027-02-20', 'posted'),
				$tx('tx-4', '2027-03-05', 'draft'),
			],
			'GLLine' => [
				['transactionId' => 'tx-1', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 500.00],
				['transactionId' => 'tx-2', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 1200.50],
				['transactionId' => 'tx-2', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 900.00],
				['transactionId' => 'tx-3', 'accountNumber' => '4300', 'side' => 'debit', 'amount' => 450.25],
				['transactionId' => 'tx-3', 'accountNumber' => '1000', 'side' => 'debit', 'amount' => 75.00],
				['transactionId' => 'tx-4', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 10.00],
			],
			'FiscalPeriod' => [
				['administrationId' => 'adm-1', 'startDate' => '2027-01-01', 'endDate' => '2027-02-28', 'fiscalYear' => 2027, 'state' => 'closed'],
			],
			'AnnualBudget' => [
				['id' => 'ab-2027', 'administrationId' => 'adm-1', 'fiscalYear' => 2027, 'isDefault' => true],
			],
			'BudgetLine' => [
				['id' => 'bl-1', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'g-income', 'month01Amount' => 100000, 'month02Amount' => 110000],
				['id' => 'bl-2', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'g-wages', 'month01Amount' => 80000, 'month03Amount' => 80000],
				['id' => 'bl-3', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'g-costs', 'month02Amount' => 50000],
				['id' => 'bl-4', 'annualBudgetId' => 'ab-2027', 'ledgerGroupId' => 'g-balance', 'month02Amount' => 5000],
			],
		];

	}//end data()
}//end class
