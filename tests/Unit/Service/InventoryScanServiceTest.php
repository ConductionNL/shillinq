<?php

/**
 * Unit tests for InventoryScanService.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T6.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InventoryScanService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for InventoryScanService server-authoritative operations.
 *
 * Covers:
 * - Receive increments stock and returns resulting on-hand (REQ-INVENTORY-001).
 * - Transfer decrements source, increments destination (REQ-INVENTORY-002).
 * - Pick decrements stock and clamps at zero (REQ-INVENTORY-003).
 * - Count computes variance and does NOT auto-apply (REQ-INVENTORY-004).
 * - Idempotency: a repeated transactionId returns duplicate, no double mutation (REQ-SYNC-001).
 * - Barcode resolution by barcode then SKU fallback (REQ-SKU-001 / REQ-BARCODE-002).
 * - Stock delta filtering by timestamp (REQ-OFFLINE-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InventoryScanServiceTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * In-memory ObjectService stub shared by service + test assertions.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * The service under test.
	 *
	 * @var InventoryScanService
	 */
	private InventoryScanService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->store = $this->buildStore();
		$this->container->method('get')->willReturn($this->store);

		$this->service = new InventoryScanService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end setUp()

	/**
	 * Build a stateful in-memory ObjectService stub that supports the real API
	 * (setRegister/setSchema/findAll/saveObject) and persists saves so finds see them.
	 *
	 * @return object
	 */
	private function buildStore(): object {
		return new class {
			/**
			 * Persisted records keyed by schema.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			public array $records = [];

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema name.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return records for the current schema matching the exact filters.
			 *
			 * @param array<string, mixed> $params Query parameters (filters/limit).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->records[$this->currentSchema] ?? []);
				$filters = ($params['filters'] ?? []);
				$matched = [];
				foreach ($rows as $row) {
					$ok = true;
					foreach ($filters as $key => $value) {
						if ((string)($row[$key] ?? '') !== (string)$value) {
							$ok = false;
							break;
						}
					}

					if ($ok === true) {
						$matched[] = $row;
					}
				}

				if (isset($params['limit']) === true) {
					$matched = array_slice($matched, 0, (int)$params['limit']);
				}

				return $matched;
			}//end findAll()

			/**
			 * Persist an object for a schema (upsert on sku+location for stock).
			 *
			 * @param array<string, mixed> $object Object data.
			 * @param string $register Register slug.
			 * @param string $schema Schema name.
			 *
			 * @return array<string, mixed> The stored object.
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$target = $this->currentSchema;
				if ($schema !== '') {
					$target = $schema;
				}

				if (isset($this->records[$target]) === false) {
					$this->records[$target] = [];
				}

				if ($target === 'InventoryStock') {
					foreach ($this->records[$target] as $i => $row) {
						if (($row['sku'] ?? null) === ($object['sku'] ?? null)
							&& ($row['location'] ?? null) === ($object['location'] ?? null)
						) {
							$this->records[$target][$i] = $object;
							return $object;
						}
					}
				}

				$this->records[$target][] = $object;
				return $object;
			}//end saveObject()
		};

	}//end buildStore()

	/**
	 * Seed an InventoryStock row directly into the store.
	 *
	 * @param string $sku SKU.
	 * @param string $location Location code.
	 * @param float $quantity Quantity.
	 *
	 * @return void
	 */
	private function seedStock(string $sku, string $location, float $quantity): void {
		$this->store->records['InventoryStock'][] = [
			'administrationId' => 'adm-1',
			'sku' => $sku,
			'location' => $location,
			'quantity' => $quantity,
			'lastModified' => '2026-05-19T08:00:00Z',
			'status' => 'active',
		];

	}//end seedStock()

	/**
	 * Receive increments stock and returns the resulting on-hand (REQ-INVENTORY-001).
	 *
	 * @return void
	 */
	public function testReceiveIncrementsStock(): void {
		$this->seedStock('WIDGET-001', 'WH-A1', 45);

		$result = $this->service->applyOperation(
			operation: ['type' => 'receive', 'transactionId' => 'tx-r1', 'sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 50],
			userId: 'clerk',
			administrationId: 'adm-1',
		);

		$this->assertSame('applied', $result['status']);
		$this->assertSame(95.0, $result['resultingQuantity']);
		$this->assertCount(1, $this->store->records['GoodsReceipt']);

	}//end testReceiveIncrementsStock()

	/**
	 * Transfer decrements source and increments destination (REQ-INVENTORY-002).
	 *
	 * @return void
	 */
	public function testTransferMovesStockBetweenLocations(): void {
		$this->seedStock('WIDGET-001', 'WH-A1', 45);
		$this->seedStock('WIDGET-001', 'WH-A2', 8);

		$op = [
			'type' => 'transfer',
			'transactionId' => 'tx-t1',
			'sku' => 'WIDGET-001',
			'location' => 'WH-A1',
			'toLocation' => 'WH-A2',
			'quantity' => 20,
		];
		$result = $this->service->applyOperation(
			operation: $op,
			userId: 'operator',
			administrationId: 'adm-1',
		);

		$this->assertSame('applied', $result['status']);
		$this->assertSame(25.0, $result['resultingQuantity']);

		$a1 = $this->store->setSchema('InventoryStock')->findAll(['filters' => ['sku' => 'WIDGET-001', 'location' => 'WH-A1']]);
		$a2 = $this->store->setSchema('InventoryStock')->findAll(['filters' => ['sku' => 'WIDGET-001', 'location' => 'WH-A2']]);
		$this->assertSame(25.0, (float)$a1[0]['quantity']);
		$this->assertSame(28.0, (float)$a2[0]['quantity']);
		$this->assertCount(1, $this->store->records['InventoryTransfer']);

	}//end testTransferMovesStockBetweenLocations()

	/**
	 * Pick decrements stock and clamps at zero (REQ-INVENTORY-003).
	 *
	 * @return void
	 */
	public function testPickClampsAtZero(): void {
		$this->seedStock('PART-003', 'WH-B1', 3);

		$result = $this->service->applyOperation(
			operation: ['type' => 'pick', 'transactionId' => 'tx-p1', 'sku' => 'PART-003', 'location' => 'WH-B1', 'quantity' => 10],
			userId: 'operator',
			administrationId: 'adm-1',
		);

		$this->assertSame('applied', $result['status']);
		$this->assertSame(0.0, $result['resultingQuantity']);

	}//end testPickClampsAtZero()

	/**
	 * Count computes variance and does NOT auto-apply to stock (REQ-INVENTORY-004).
	 *
	 * @return void
	 */
	public function testCountComputesVarianceWithoutApplying(): void {
		$this->seedStock('WIDGET-001', 'WH-A1', 45);

		$result = $this->service->applyOperation(
			operation: ['type' => 'count', 'transactionId' => 'tx-c1', 'sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 42],
			userId: 'counter',
			administrationId: 'adm-1',
		);

		$this->assertSame('applied', $result['status']);
		$this->assertSame(-3.0, $result['variance']);

		// Stock must remain at the system value (not auto-corrected).
		$stock = $this->store->setSchema('InventoryStock')->findAll(['filters' => ['sku' => 'WIDGET-001', 'location' => 'WH-A1']]);
		$this->assertSame(45.0, (float)$stock[0]['quantity']);
		$this->assertCount(1, $this->store->records['InventoryCount']);
		$this->assertFalse($this->store->records['InventoryCount'][0]['applied']);

	}//end testCountComputesVarianceWithoutApplying()

	/**
	 * Idempotency: a repeated transactionId is deduplicated, no double mutation (REQ-SYNC-001).
	 *
	 * @return void
	 */
	public function testDuplicateTransactionIdIsIdempotent(): void {
		$this->seedStock('WIDGET-001', 'WH-A1', 45);

		$first = $this->service->applyOperation(
			operation: ['type' => 'receive', 'transactionId' => 'tx-dup', 'sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 50],
			userId: 'clerk',
			administrationId: 'adm-1',
		);
		$this->assertSame('applied', $first['status']);
		$this->assertSame(95.0, $first['resultingQuantity']);

		$second = $this->service->applyOperation(
			operation: ['type' => 'receive', 'transactionId' => 'tx-dup', 'sku' => 'WIDGET-001', 'location' => 'WH-A1', 'quantity' => 50],
			userId: 'clerk',
			administrationId: 'adm-1',
		);
		$this->assertSame('duplicate', $second['status']);

		// Stock must still be 95, not 145.
		$stock = $this->store->setSchema('InventoryStock')->findAll(['filters' => ['sku' => 'WIDGET-001', 'location' => 'WH-A1']]);
		$this->assertSame(95.0, (float)$stock[0]['quantity']);

	}//end testDuplicateTransactionIdIsIdempotent()

	/**
	 * Barcode resolution matches by barcode, then falls back to SKU (REQ-SKU-001).
	 *
	 * @return void
	 */
	public function testResolveBarcodeMatchesBarcodeThenSku(): void {
		$this->store->records['InventoryItem'][] = [
			'administrationId' => 'adm-1',
			'sku' => 'WIDGET-001',
			'barcode' => '5901234123457',
			'name' => 'Blue Widget',
		];

		$byBarcode = $this->service->resolveBarcode('5901234123457', 'adm-1');
		$this->assertNotNull($byBarcode);
		$this->assertSame('WIDGET-001', $byBarcode['sku']);

		$bySku = $this->service->resolveBarcode('WIDGET-001', 'adm-1');
		$this->assertNotNull($bySku);
		$this->assertSame('WIDGET-001', $bySku['sku']);

		$this->assertNull($this->service->resolveBarcode('NOPE-999', 'adm-1'));

	}//end testResolveBarcodeMatchesBarcodeThenSku()

	/**
	 * Stock delta returns only records modified at/after the cut-off (REQ-OFFLINE-002).
	 *
	 * @return void
	 */
	public function testStockDeltaFiltersByTimestamp(): void {
		$this->store->records['InventoryStock'] = [
			['administrationId' => 'adm-1', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1, 'lastModified' => '2026-05-10T00:00:00Z'],
			['administrationId' => 'adm-1', 'sku' => 'B', 'location' => 'L1', 'quantity' => 2, 'lastModified' => '2026-05-20T00:00:00Z'],
		];

		$delta = $this->service->getStockDelta('2026-05-15T00:00:00Z', 'adm-1');
		$this->assertCount(1, $delta);
		$this->assertSame('B', $delta[0]['sku']);

		$full = $this->service->getStockDelta(null, 'adm-1');
		$this->assertCount(2, $full);

	}//end testStockDeltaFiltersByTimestamp()
}//end class
