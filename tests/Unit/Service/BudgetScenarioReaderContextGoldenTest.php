<?php

/**
 * Golden test for BudgetScenarioReader::loadContext.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\BudgetScenarioReader;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the context and every query, in order, per scenario.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BudgetScenarioReaderContextGoldenTest extends TestCase {

	/**
	 * Recorded findAll calls.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testContextsMatchGolden(): void {
		$data = [
			'BudgetScenario' => [
				['id' => 'sc-1', 'administrationId' => 'adm-1'],
				['@self' => ['id' => 'sc-2'], 'administrationId' => 'adm-1'],
				['administrationId' => 'adm-1', 'name' => 'no id'],
				['id' => 'sc-x', 'administrationId' => 'adm-2'],
			],
			'BudgetScenarioModifier' => [
				['id' => 'm-1', 'scenarioId' => 'sc-1'],
				['id' => 'm-2', 'scenarioId' => 'sc-2'],
				['id' => 'm-3', 'scenarioId' => 'sc-1'],
				['id' => 'm-4', 'scenarioId' => ''],
				['id' => 'm-5'],
				['id' => 'm-6', 'scenarioId' => 'sc-x'],
			],
			'CashflowRecurring' => [['recurId' => 'r-1', 'administrationId' => 'adm-1']],
			'BudgetLine' => [['id' => 'bl-1', 'annualBudgetId' => 'ab-1'], ['id' => 'bl-2', 'annualBudgetId' => 'ab-2']],
			'LedgerGroup' => [['id' => 'lg-1', 'administrationId' => 'adm-1']],
		];
		$result = [];
		$scenarios = [
			'full' => ['adm-1', ['ab-1']],
			'noBudgets' => ['adm-1', []],
			'noScenarios' => ['adm-9', ['ab-2', 'ab-1']],
		];
		foreach ($scenarios as $name => [$administrationId, $annualBudgetIds]) {
			$this->calls = [];
			$context = $this->reader(data: $data)->loadContext($administrationId, $annualBudgetIds);
			$result[$name] = ['context' => $context, 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/budget-scenario-reader-context.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('SCENARIO_CONTEXT_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testContextsMatchGolden()

	/**
	 * A reader over a recording, filtering store.
	 *
	 * @param array $data Schema => rows.
	 *
	 * @return BudgetScenarioReader
	 */
	private function reader(array $data): BudgetScenarioReader {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$service = $this->createMock(ObjectServiceInterface::class);
		$schema = '';
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnCallback(
			function (string $slug) use ($service, &$schema) {
				$schema = $slug;
				return $service;
			}
		);
		$service->method('findAll')->willReturnCallback(
			function (array $config) use ($data, &$schema) {
				$this->calls[] = [$schema, $config];
				$keep = static fn (array $row): bool => self::rowMatches(row: $row, filters: $config['filters']);
				return array_values(array_filter(($data[$schema] ?? []), $keep));
			}
		);
		return new BudgetScenarioReader(appConfig: $appConfig, logger: new NullLogger(), objectService: $service);

	}//end reader()

	/**
	 * Whether a row matches every filter (equality or `in`).
	 *
	 * @param array $row     The row.
	 * @param array $filters The filters.
	 *
	 * @return bool
	 */
	private static function rowMatches(array $row, array $filters): bool {
		foreach ($filters as $key => $value) {
			$actual = ($row[$key] ?? null);
			if (is_array($value) === true && in_array($actual, $value['in'], true) === false) {
				return false;
			}

			if (is_array($value) === false && $actual !== $value) {
				return false;
			}
		}

		return true;

	}//end rowMatches()
}//end class
