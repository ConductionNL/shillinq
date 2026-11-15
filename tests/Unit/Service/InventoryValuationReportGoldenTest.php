<?php

/**
 * Golden tests for InventoryValuationReportService over a mixed ledger.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InventoryValuationReportService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins valuation, ageing and turnover, value for value, over two SKUs in two
 * warehouses under both methods, with moves the report must ignore.
 */
final class InventoryValuationReportGoldenTest extends TestCase {

	/**
	 * The service over the mixed ledger.
	 *
	 * @return InventoryValuationReportService
	 */
	private function service(): InventoryValuationReportService {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'StockMove', rows: $this->moves());
		$os->seed(
			schema: 'InventoryValuation',
			rows: [
				['id' => 'iv-1', 'productId' => 'AV-1', 'warehouse' => 'WH-A', 'valuationMethod' => 'average', 'administrationId' => 'adm-1'],
				['id' => 'iv-2', 'productId' => 'FF-1', 'warehouse' => 'WH-A', 'valuationMethod' => 'FIFO', 'administrationId' => 'adm-1'],
			]
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new InventoryValuationReportService(
			appConfig: $appConfig,
			logger: $this->createStub(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($os),
		);
	}//end service()

	/**
	 * The ledger.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function moves(): array {
		$move = static fn (string $id, string $sku, string $type, string $wh, float $qty, float $cost, string $at, string $state = 'posted'): array => [
			'id' => $id,
			'itemId' => $sku,
			'movementType' => $type,
			($type === 'receipt' ? 'destinationLocationId' : 'sourceLocationId') => $wh,
			'quantity' => $qty,
			'unitCost' => $cost,
			'lifecycleState' => $state,
			'postedAt' => $at,
			'administrationId' => 'adm-1',
		];

		return [
			$move('m1', 'FF-1', 'receipt', 'WH-A', 10, 5.0, '2026-01-05T08:00:00Z'),
			$move('m2', 'FF-1', 'receipt', 'WH-A', 10, 7.0, '2026-02-10T08:00:00Z'),
			$move('m3', 'FF-1', 'issue', 'WH-A', 12, 0, '2026-03-01T08:00:00Z'),
			$move('m4', 'FF-1', 'receipt', 'WH-A', 5, 9.0, '2026-03-20T08:00:00Z'),
			$move('m5', 'FF-1', 'receipt', 'WH-B', 4, 6.0, '2026-02-01T08:00:00Z'),
			$move('m6', 'FF-1', 'issue', 'WH-B', 0, 0, '2026-02-02T08:00:00Z'),
			$move('m7', 'FF-1', 'receipt', 'WH-A', 100, 1.0, '2026-03-05T08:00:00Z', 'draft'),
			$move('m8', 'FF-1', 'transfer', 'WH-A', 3, 1.0, '2026-03-06T08:00:00Z'),
			$move('m9', 'FF-1', 'receipt', 'WH-A', 50, 2.0, '2026-05-01T08:00:00Z'),
			$move('a1', 'AV-1', 'receipt', 'WH-A', 10, 10.0, '2026-01-10T08:00:00Z'),
			$move('a2', 'AV-1', 'receipt', 'WH-A', 10, 13.0, '2026-02-10T08:00:00Z'),
			$move('a3', 'AV-1', 'issue', 'WH-A', 15, 0, '2026-03-10T08:00:00Z'),
			$move('a4', 'AV-1', 'issue', 'WH-A', 9, 0, '2026-03-11T08:00:00Z'),
			$move('a5', 'AV-1', 'receipt', 'WH-A', 4, 20.0, '2026-03-12T08:00:00Z'),
			$move('z1', 'ZZ-1', 'receipt', 'WH-A', 2, 3.0, '2026-03-31'),
		];
	}//end moves()

	/**
	 * Every report over the ledger.
	 *
	 * @return array<string, mixed>
	 */
	private function reports(): array {
		$service = $this->service();

		return [
			'all' => $service->valuationAsOf('adm-1', '2026-03-31'),
			'sku' => $service->valuationAsOf('adm-1', '2026-03-31T00:00:00Z', 'FF-1'),
			'warehouse' => $service->valuationAsOf('adm-1', ' 2026-02-15 ', null, 'WH-B'),
			'empty filters' => $service->valuationAsOf('adm-1', '2026-06-30', '', ''),
			'other tenant' => $service->valuationAsOf('adm-2', '2026-06-30'),
			'ageing fifo' => $service->ageing('adm-1', '2026-05-15', 'FF-1', 'WH-A'),
			'ageing average' => $service->ageing('adm-1', '2026-04-30', 'AV-1', 'WH-A'),
			'turnover fifo' => $service->turnover('adm-1', '2026-01-31', '2026-03-31', 'FF-1', 'WH-A'),
			'turnover average' => $service->turnover('adm-1', '2026-02-28', '2026-03-31', 'AV-1', 'WH-A'),
			'turnover empty' => $service->turnover('adm-1', '2025-01-01', '2025-01-01', 'FF-1', 'WH-A'),
			'turnover no cogs' => $service->turnover('adm-1', '2026-03-21', '2026-04-30', 'FF-1', 'WH-A'),
		];
	}//end reports()

	/**
	 * The reports match the snapshot taken before the report was split.
	 *
	 * @return void
	 */
	public function testReportsMatchTheSnapshot(): void {
		$snapshot = json_decode((string)file_get_contents(__DIR__ . '/fixtures/inventory-valuation-report-golden.json'), true);
		self::assertSame($snapshot, json_decode((string)json_encode($this->reports(), JSON_PRESERVE_ZERO_FRACTION), true));
	}//end testReportsMatchTheSnapshot()
}//end class
