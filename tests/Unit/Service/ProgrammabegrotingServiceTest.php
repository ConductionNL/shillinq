<?php

/**
 * Unit tests for ProgrammabegrotingService.
 *
 * The service reads a municipal budget and its multi-year rows from
 * OpenRegister and delegates the sluitend evaluation and the IV3 / JSON exports
 * to the real calculator and exporter. These tests run those two collaborators
 * for real and mock only the OpenRegister boundary and the app config.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\ProgrammabegrotingExporter;
use OCA\Shillinq\Service\ProgrammabegrotingService;
use OCA\Shillinq\Service\SluitendCalculator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Shillinq\Service\ProgrammabegrotingService
 * @uses   \OCA\Shillinq\Service\SluitendCalculator
 * @uses   \OCA\Shillinq\Service\ProgrammabegrotingExporter
 */
class ProgrammabegrotingServiceTest extends TestCase {

	/**
	 * The service, reading the given rows per schema from a fake ObjectService.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rowsBySchema Rows keyed by schema slug.
	 * @param string                                       $register     The configured register value.
	 *
	 * @return ProgrammabegrotingService
	 */
	private function service(array $rowsBySchema, string $register = 'shillinq'): ProgrammabegrotingService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($register);

		$schema = '';
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnCallback(
			static function (string|int $s) use ($objectService, &$schema): ObjectServiceInterface {
				$schema = (string)$s;
				return $objectService;
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$schema, $rowsBySchema): array {
				$rows = ($rowsBySchema[$schema] ?? []);
				$filters = ($config['filters'] ?? []);
				if (is_array($filters) === false || $filters === []) {
					return $rows;
				}

				return array_values(array_filter(
					$rows,
					static function (array $row) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}
		);

		return new ProgrammabegrotingService(
			appConfig: $appConfig,
			sluitend: new SluitendCalculator(),
			exporter: new ProgrammabegrotingExporter(),
			objectService: $objectService,
		);

	}//end service()

	/**
	 * A structurally balanced budget whose real saldo also closes is repressief.
	 *
	 * @return void
	 */
	public function testSluitendStatusOfABalancedBudgetIsRepressief(): void {
		$service = $this->service([
			'Programmabegroting' => [['id' => 'b-1', 'administrationId' => 'a-1', 'nominalDevelopment' => 2.0]],
			'Meerjarenraming'    => [
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'year' => 2026, 'revenueStructural' => 1000, 'expensesStructural' => 900, 'revenueIncidental' => 50, 'expensesIncidental' => 0],
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'year' => 2027, 'revenueStructural' => 1000, 'expensesStructural' => 1000, 'revenueIncidental' => 30, 'expensesIncidental' => 0],
			],
		]);

		$status = $service->sluitendStatus(administrationId: 'a-1', budgetId: 'b-1');

		$this->assertSame('b-1', $status['budgetId']);
		$this->assertTrue($status['structurallyBalanced']);
		$this->assertTrue($status['sluitendReëel']);
		$this->assertSame('repressief', $status['supervisionRegime']);
		$this->assertCount(2, $status['jaren']);
		$this->assertSame(2026, $status['jaren'][0]['year']);
		$this->assertSame(100.0, $status['jaren'][0]['balanceStructural']);
		$this->assertTrue($status['jaren'][0]['sluitend']);

	}//end testSluitendStatusOfABalancedBudgetIsRepressief()

	/**
	 * A structural shortfall makes the budget unbalanced and preventief.
	 *
	 * @return void
	 */
	public function testSluitendStatusOfAnUnbalancedBudgetIsPreventief(): void {
		$service = $this->service([
			'Programmabegroting' => [['id' => 'b-2', 'administrationId' => 'a-1']],
			'Meerjarenraming'    => [
				['budgetId' => 'b-2', 'administrationId' => 'a-1', 'year' => 2026, 'revenueStructural' => 800, 'expensesStructural' => 1000],
			],
		]);

		$status = $service->sluitendStatus(administrationId: 'a-1', budgetId: 'b-2');

		$this->assertFalse($status['structurallyBalanced']);
		$this->assertFalse($status['sluitendReëel']);
		$this->assertSame('preventief', $status['supervisionRegime']);
		$this->assertFalse($status['jaren'][0]['sluitend']);

	}//end testSluitendStatusOfAnUnbalancedBudgetIsPreventief()

	/**
	 * An empty budget has no years, so neither flag is set and it is preventief.
	 *
	 * @return void
	 */
	public function testSluitendStatusWithNoYears(): void {
		$status = $this->service(['Programmabegroting' => [['id' => 'b-3', 'administrationId' => 'a-1']]])
			->sluitendStatus(administrationId: 'a-1', budgetId: 'b-3');

		$this->assertSame([], $status['jaren']);
		$this->assertFalse($status['structurallyBalanced']);
		$this->assertSame('preventief', $status['supervisionRegime']);

	}//end testSluitendStatusWithNoYears()

	/**
	 * The JSON export carries the budget metadata, the programmes, the IV3 rows and the paragraphs.
	 *
	 * @return void
	 */
	public function testJsonExport(): void {
		$service = $this->service([
			'Programmabegroting' => [['id' => 'b-1', 'administrationId' => 'a-1', 'budgetYear' => 2026, 'status' => 'vastgesteld']],
			'Programma'          => [['budgetId' => 'b-1', 'administrationId' => 'a-1', 'number' => 1, 'name' => 'Bestuur', 'revenueTotal' => 10.0]],
			'Taakveld'           => [
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'taskFieldCode' => '0.1', 'revenue' => 5.0, 'expenses' => 3.0],
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'taskFieldCode' => '0.1', 'revenue' => 1.0, 'expenses' => 2.0],
			],
			'Paragraaf'          => [['budgetId' => 'b-1', 'administrationId' => 'a-1', 'type' => 'weerstandsvermogen', 'narrative' => 'x']],
		]);

		$export = $service->jsonExport(administrationId: 'a-1', budgetId: 'b-1');

		$this->assertSame(2026, $export['metadata']['budgetYear']);
		$this->assertSame('vastgesteld', $export['metadata']['status']);
		$this->assertSame('Bestuur', $export['programmas'][0]['name']);
		$this->assertSame([['taskFieldCode' => '0.1', 'revenue' => 6.0, 'expenses' => 5.0]], $export['taskFields']);
		$this->assertSame('weerstandsvermogen', $export['paragrafen'][0]['type']);

	}//end testJsonExport()

	/**
	 * The IV3 export groups the task fields by code, summed, sorted by code.
	 *
	 * @return void
	 */
	public function testIv3Export(): void {
		$service = $this->service([
			'Taakveld' => [
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'taskFieldCode' => '0.2', 'revenue' => 2.0, 'expenses' => 1.0],
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'taskFieldCode' => '0.1', 'revenue' => 4.0, 'expenses' => 3.0],
				['budgetId' => 'b-1', 'administrationId' => 'a-1', 'taskFieldCode' => '0.1', 'revenue' => 1.0, 'expenses' => 1.0],
			],
		]);

		$rows = $service->iv3Export(administrationId: 'a-1', budgetId: 'b-1');

		$this->assertSame([
			['taskFieldCode' => '0.1', 'revenue' => 5.0, 'expenses' => 4.0],
			['taskFieldCode' => '0.2', 'revenue' => 2.0, 'expenses' => 1.0],
		], $rows);

	}//end testIv3Export()

	/**
	 * An empty configured register falls back to the default register.
	 *
	 * @return void
	 */
	public function testEmptyRegisterConfigFallsBackToDefault(): void {
		$service = $this->service(['Taakveld' => []], register: '');

		$this->assertSame([], $service->iv3Export(administrationId: 'a-1', budgetId: 'b-1'));

	}//end testEmptyRegisterConfigFallsBackToDefault()
}//end class
