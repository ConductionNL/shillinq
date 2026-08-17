<?php

/**
 * Unit tests for FifoValuationService.
 *
 * Covers REQ-INV-003 FIFO lot traversal correctness, idempotency on
 * retry, and the snapshot recompute path.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/InMemoryObjectService.php';

/**
 * FifoValuationService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FifoValuationServiceTest extends TestCase {

	/**
	 * Build a service wired to an in-memory ObjectService stub.
	 *
	 * @param InMemoryObjectService $os The stub.
	 *
	 * @return FifoValuationService
	 */
	private function makeService(InMemoryObjectService $os): FifoValuationService {
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createStub(LoggerInterface::class);

		return new FifoValuationService(
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($os),
		);

	}//end makeService()

	/**
	 * REQ-INV-003 main scenario: two inbound lots, one outbound consuming
	 * Lot A in full and 5 of Lot B should record weighted COGS and a
	 * residual of qty 15 @ EUR 12,00.
	 *
	 * @return void
	 */
	public function testFifoTwoLotSplitCogsAndResidual(): void {
		$os = new InMemoryObjectService();
		// Two inbound lots, posted oldest-first.
		$os->seed(schema: 'StockMove', rows: [
			[
				'id' => 'mv-1',
				'uuid' => 'uuid-mv-1',
				'movementType' => 'receipt',
				'itemId' => 'GT-10-2026',
				'destinationLocationId' => 'Magazijn Noord',
				'sourceLocationId' => null,
				'quantity' => 30.0,
				'unitCost' => 10.0,
				'lifecycleState' => 'posted',
				'postedAt' => '2026-04-01T09:00:00Z',
				'administrationId' => 'adm-1',
			],
			[
				'id' => 'mv-2',
				'uuid' => 'uuid-mv-2',
				'movementType' => 'receipt',
				'itemId' => 'GT-10-2026',
				'destinationLocationId' => 'Magazijn Noord',
				'sourceLocationId' => null,
				'quantity' => 20.0,
				'unitCost' => 12.0,
				'lifecycleState' => 'posted',
				'postedAt' => '2026-04-15T09:00:00Z',
				'administrationId' => 'adm-1',
			],
		]);

		$service = $this->makeService(os: $os);

		// Outbound issue 35 — should take 30 @ 10 from Lot A + 5 @ 12 from Lot B.
		$result = $service->processStockMove(move: [
			'id' => 'mv-3',
			'uuid' => 'uuid-mv-3',
			'movementType' => 'issue',
			'itemId' => 'GT-10-2026',
			'sourceLocationId' => 'Magazijn Noord',
			'quantity' => 35.0,
			'lifecycleState' => 'posted',
			'postedAt' => '2026-05-01T09:00:00Z',
			'administrationId' => 'adm-1',
		]);

		self::assertTrue($result['processed']);
		// 30*10 + 5*12 = 360.00 -> 36000 cents.
		self::assertSame(36000, $result['cogsCents']);
		self::assertCount(2, $result['cogsBasis']);
		self::assertSame(10.0, $result['cogsBasis'][0]['lotCost']);
		self::assertSame(30.0, $result['cogsBasis'][0]['quantity']);
		self::assertSame(12.0, $result['cogsBasis'][1]['lotCost']);
		self::assertSame(5.0, $result['cogsBasis'][1]['quantity']);

		$snapshot = $result['valuation'];
		self::assertSame(15.0, (float)$snapshot['quantity']);
		self::assertSame(12.0, (float)$snapshot['unitCost']);
		self::assertSame(180.0, (float)$snapshot['totalValue']);

	}//end testFifoTwoLotSplitCogsAndResidual()

	/**
	 * REQ-INV-003 idempotency: same StockMove uuid arriving twice causes
	 * the second call to be a no-op.
	 *
	 * @return void
	 */
	public function testIdempotentOnRetry(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'StockMove', rows: [
			[
				'id' => 'mv-1',
				'uuid' => 'uuid-mv-1',
				'movementType' => 'receipt',
				'itemId' => 'P1',
				'destinationLocationId' => 'WH-A',
				'quantity' => 10.0,
				'unitCost' => 5.0,
				'lifecycleState' => 'posted',
				'postedAt' => '2026-04-01T09:00:00Z',
				'administrationId' => 'adm-1',
			],
		]);
		$os->seed(schema: 'InventoryValuation', rows: [
			[
				'id' => 'iv-1',
				'productId' => 'P1',
				'warehouse' => 'WH-A',
				'status' => 'active',
				'quantity' => 10.0,
				'unitCost' => 5.0,
				'totalValue' => 50.0,
				'valuationMethod' => 'FIFO',
				'administrationId' => 'adm-1',
				'lastStockMoveUuid' => 'uuid-mv-1',
			],
		]);

		$service = $this->makeService(os: $os);

		$result = $service->processStockMove(move: [
			'id' => 'mv-1',
			'uuid' => 'uuid-mv-1',
			'movementType' => 'receipt',
			'itemId' => 'P1',
			'destinationLocationId' => 'WH-A',
			'quantity' => 10.0,
			'unitCost' => 5.0,
			'lifecycleState' => 'posted',
			'postedAt' => '2026-04-01T09:00:00Z',
			'administrationId' => 'adm-1',
		]);

		self::assertFalse($result['processed']);
		self::assertSame('idempotent retry', $result['message']);

	}//end testIdempotentOnRetry()

	/**
	 * REQ-INV-003 full-lot exhaustion: outbound takes the entire oldest
	 * lot exactly, leaving the second lot untouched.
	 *
	 * @return void
	 */
	public function testFullLotExhaustion(): void {
		$os = new InMemoryObjectService();
		$os->seed(schema: 'StockMove', rows: [
			[
				'id' => 'mv-1',
				'uuid' => 'uuid-mv-1',
				'movementType' => 'receipt',
				'itemId' => 'P1',
				'destinationLocationId' => 'WH-A',
				'quantity' => 20.0,
				'unitCost' => 8.0,
				'lifecycleState' => 'posted',
				'postedAt' => '2026-04-01T09:00:00Z',
				'administrationId' => 'adm-1',
			],
			[
				'id' => 'mv-2',
				'uuid' => 'uuid-mv-2',
				'movementType' => 'receipt',
				'itemId' => 'P1',
				'destinationLocationId' => 'WH-A',
				'quantity' => 30.0,
				'unitCost' => 9.0,
				'lifecycleState' => 'posted',
				'postedAt' => '2026-04-10T09:00:00Z',
				'administrationId' => 'adm-1',
			],
		]);

		$service = $this->makeService(os: $os);

		$result = $service->processStockMove(move: [
			'id' => 'mv-3',
			'uuid' => 'uuid-mv-3',
			'movementType' => 'issue',
			'itemId' => 'P1',
			'sourceLocationId' => 'WH-A',
			'quantity' => 20.0,
			'lifecycleState' => 'posted',
			'postedAt' => '2026-04-15T09:00:00Z',
			'administrationId' => 'adm-1',
		]);

		self::assertTrue($result['processed']);
		// 20 * 8.00 = 160.00 -> 16000 cents.
		self::assertSame(16000, $result['cogsCents']);
		self::assertCount(1, $result['cogsBasis']);

		$snapshot = $result['valuation'];
		// 30 @ 9.00 remains.
		self::assertSame(30.0, (float)$snapshot['quantity']);
		self::assertSame(9.0, (float)$snapshot['unitCost']);
		self::assertSame(270.0, (float)$snapshot['totalValue']);

	}//end testFullLotExhaustion()

}//end class
