<?php

/**
 * Unit tests for InventoryValuationReportService.
 *
 * Covers the jaarrekening `voorraadwaarde per <as-of-date>` computed by
 * replaying the immutable StockMove ledger (FIFO lot reconstruction) up to
 * a historical cut-off, plus ageing and turnover.
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
 * @spec openspec/changes/inventory-accounting-correctness/specs/inventory-accounting-correctness/spec.md
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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * InventoryValuationReportService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InventoryValuationReportServiceTest extends TestCase {
	/**
	 * Build the service wired to an in-memory ObjectService stub.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return InventoryValuationReportService
	 */
	private function makeService(InMemoryObjectService $os): InventoryValuationReportService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createStub(LoggerInterface::class);

		return new InventoryValuationReportService(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * Seed a known ledger: two receipts then one 35-unit issue, plus the
	 * driving InventoryValuation snapshot under the requested method.
	 *
	 * @param InMemoryObjectService $os The stub.
	 * @param string $method 'FIFO' or 'average'.
	 *
	 * @return void
	 */
	private function seedLedger(InMemoryObjectService $os, string $method = 'FIFO'): void {
		$os->seed(
			schema: 'StockMove',
			rows: [
				[
					'id' => 'mv-1',
					'itemId' => 'GT-10',
					'movementType' => 'receipt',
					'destinationLocationId' => 'WH-A',
					'quantity' => 30.0,
					'unitCost' => 10.0,
					'lifecycleState' => 'posted',
					'postedAt' => '2026-04-01T09:00:00Z',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'mv-2',
					'itemId' => 'GT-10',
					'movementType' => 'receipt',
					'destinationLocationId' => 'WH-A',
					'quantity' => 20.0,
					'unitCost' => 12.0,
					'lifecycleState' => 'posted',
					'postedAt' => '2026-04-15T09:00:00Z',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'mv-3',
					'itemId' => 'GT-10',
					'movementType' => 'issue',
					'sourceLocationId' => 'WH-A',
					'quantity' => 35.0,
					'lifecycleState' => 'posted',
					'postedAt' => '2026-05-01T09:00:00Z',
					'administrationId' => 'adm-1',
				],
			]
		);
		$os->seed(
			schema: 'InventoryValuation',
			rows: [
				[
					'id' => 'iv-1',
					'productId' => 'GT-10',
					'warehouse' => 'WH-A',
					'status' => 'active',
					'valuationMethod' => $method,
					'administrationId' => 'adm-1',
				],
			]
		);

	}//end seedLedger()

	/**
	 * As-of a date BEFORE the issue: value = 30*10 + 20*12 = 540,00, qty 50.
	 *
	 * @return void
	 */
	public function testValuationAsOfBeforeIssue(): void {
		$os = new InMemoryObjectService();
		$this->seedLedger(os: $os);
		$service = $this->makeService(os: $os);

		$report = $service->valuationAsOf(administrationId: 'adm-1', asOfDate: '2026-04-30');

		self::assertSame(540.0, $report['totalValue']);
		self::assertSame(50.0, $report['totalQuantity']);
		self::assertCount(1, $report['lines']);
		self::assertSame('GT-10', $report['lines'][0]['sku']);
		self::assertSame(540.0, $report['lines'][0]['totalValue']);

	}//end testValuationAsOfBeforeIssue()

	/**
	 * As-of a date AFTER the 35-unit issue: FIFO consumes 30@10 + 5@12,
	 * leaving 15@12 = 180,00, qty 15.
	 *
	 * @return void
	 */
	public function testValuationAsOfAfterIssue(): void {
		$os = new InMemoryObjectService();
		$this->seedLedger(os: $os);
		$service = $this->makeService(os: $os);

		$report = $service->valuationAsOf(administrationId: 'adm-1', asOfDate: '2026-05-31');

		self::assertSame(180.0, $report['totalValue']);
		self::assertSame(15.0, $report['totalQuantity']);
		self::assertSame(12.0, $report['lines'][0]['unitCost']);

	}//end testValuationAsOfAfterIssue()

	/**
	 * Moving-average replay: 30@10 then 20@12 averages to 10,80; after issuing
	 * 35 the residual 15 units are valued at the running average = 162,00.
	 *
	 * @return void
	 */
	public function testValuationAsOfMovingAverage(): void {
		$os = new InMemoryObjectService();
		$this->seedLedger(os: $os, method: 'average');
		$service = $this->makeService(os: $os);

		// After 35 issue: qty 15 @ avg 10,80 = 162,00.
		$report = $service->valuationAsOf(administrationId: 'adm-1', asOfDate: '2026-05-31', sku: 'GT-10', warehouse: 'WH-A');

		self::assertSame(15.0, $report['totalQuantity']);
		self::assertSame(162.0, $report['totalValue']);

	}//end testValuationAsOfMovingAverage()

	/**
	 * FIFO ageing as-of after the issue: the residual 15@12 lot was received
	 * 2026-04-15, so as-of 2026-05-31 it is 46 days old -> the 31-60 bucket.
	 *
	 * @return void
	 */
	public function testAgeingBucketsResidualLot(): void {
		$os = new InMemoryObjectService();
		$this->seedLedger(os: $os);
		$service = $this->makeService(os: $os);

		$ageing = $service->ageing(
			administrationId: 'adm-1',
			asOfDate: '2026-05-31',
			sku: 'GT-10',
			warehouse: 'WH-A'
		);

		self::assertSame(180.0, $ageing['totalValue']);
		self::assertSame(180.0, $ageing['buckets']['31-60']);
		self::assertSame(0.0, $ageing['buckets']['0-30']);

	}//end testAgeingBucketsResidualLot()
}//end class
