<?php

/**
 * Unit tests for CycleCountService.
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
 * @spec openspec/changes/inventory-cycle-count/specs/inventory-cycle-count/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\VarianceGate;
use OCA\Shillinq\Service\CycleCountService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CycleCountService.
 *
 * Covers REQ-ICC-006 (snapshot creation on submit), REQ-ICC-007 (variance
 * adjustment posting via StockMove), and REQ-ICC-008 (partial-count
 * filtering).
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
// phpcs:disable Squiz.PHP.DisallowInlineIf
class CycleCountServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock ObjectService recording every save + serving findAll results
	 * per-schema.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * VarianceGate dependency (real, since it's pure arithmetic for the
	 * helper methods we use here).
	 *
	 * @var VarianceGate
	 */
	private VarianceGate $varianceGate;

	/**
	 * The service under test.
	 *
	 * @var CycleCountService
	 */
	private CycleCountService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->objectService = new class {

			/** @var string current schema set on the chain */
			public string $currentSchema = '';

			/** @var array<int,array<string,mixed>> stock rows for snapshot tests */
			public array $stockRows = [];

			/** @var array<int,array<string,mixed>> line rows for emit tests */
			public array $lineRows = [];

			/** @var array<int,array<string,mixed>> lines saved during the test */
			public array $savedLines = [];

			/** @var array<int,array<string,mixed>> stock moves saved during the test */
			public array $savedMoves = [];

			/** @var int total saveObject calls observed */
			public int $saveCount = 0;

			/** Set OR register; chainable. */
			public function setRegister(string $register): self {
				return $this;
			}

			/** Set OR schema; chainable. */
			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}

			/** Stubbed findAll returning per-schema fixture data. */
			public function findAll(array $args = []): array {
				if ($this->currentSchema === 'InventoryStock') {
					return $this->stockRows;
				}

				if ($this->currentSchema === 'InventoryCycleCountLine') {
					return $this->lineRows;
				}

				return [];
			}

			/** Stubbed find returning empty. */
			public function find(array $args = []): array {
				return [];
			}

			/** Stubbed saveObject capturing writes + returning row with synthetic id. */
			public function saveObject(array $obj): array {
				$this->saveCount++;
				if ($this->currentSchema === 'InventoryCycleCountLine') {
					$this->savedLines[] = $obj;
					$this->lineRows[] = $obj;
					$obj['id'] = 'line-id-' . count($this->savedLines);
					return $obj;
				}

				if ($this->currentSchema === 'StockMove') {
					$this->savedMoves[] = $obj;
					$obj['id'] = 'sm-id-' . count($this->savedMoves);
					return $obj;
				}

				return $obj;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$this->container->method('get')->willReturn($this->objectService);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->varianceGate = new VarianceGate(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

		$this->service = new CycleCountService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			varianceGate: $this->varianceGate,
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);

	}//end setUp()

	/**
	 * snapshotScope creates one InventoryCycleCountLine per InventoryStock row
	 * in scope per REQ-ICC-006.
	 *
	 * @return void
	 */
	public function testSnapshotScopeCreatesLinesFromStock(): void {
		$this->objectService->stockRows = [
			[
				'sku' => 'SKU-001',
				'locationId' => 'loc-w01-z01-b100',
				'quantity' => 150,
				'unitCost' => 45.00,
			],
			[
				'sku' => 'SKU-002',
				'locationId' => 'loc-w01-z01-b100',
				'quantity' => 50,
				'unitCost' => 12.50,
			],
		];

		$count = [
			'countId' => 'CC-2026-05-00001',
			'countType' => 'partial',
			'locationFilter' => 'loc-w01-z01-b100',
			'administrationId' => 'adm-consultancy-nl',
		];

		self::assertTrue($this->service->snapshotScope($count));
		self::assertCount(2, $this->objectService->savedLines);
		self::assertSame('CC-2026-05-00001-001', $this->objectService->savedLines[0]['lineId']);
		self::assertSame('CC-2026-05-00001-002', $this->objectService->savedLines[1]['lineId']);
		self::assertEquals(150, $this->objectService->savedLines[0]['expectedQuantity']);
		self::assertEquals(6750.00, $this->objectService->savedLines[0]['expectedValue']);
		self::assertNull($this->objectService->savedLines[0]['countedQuantity']);

	}//end testSnapshotScopeCreatesLinesFromStock()

	/**
	 * snapshotScope is idempotent on (administrationId, countId): a second call
	 * after lines already exist is a no-op per REQ-ICC-006.
	 *
	 * @return void
	 */
	public function testSnapshotScopeIdempotent(): void {
		$this->objectService->lineRows = [
			['lineId' => 'CC-2026-05-00001-001'],
		];
		$this->objectService->stockRows = [
			[
				'sku' => 'SKU-001',
				'locationId' => 'loc-w01',
				'quantity' => 10,
				'unitCost' => 1.00,
			],
		];

		$count = [
			'countId' => 'CC-2026-05-00001',
			'countType' => 'full',
			'administrationId' => 'adm-consultancy-nl',
		];

		self::assertTrue($this->service->snapshotScope($count));
		self::assertCount(0, $this->objectService->savedLines);

	}//end testSnapshotScopeIdempotent()

	/**
	 * snapshotScope denies when required fields missing (fail-closed).
	 *
	 * @return void
	 */
	public function testSnapshotScopeRequiresFields(): void {
		self::assertFalse(
			$this->service->snapshotScope(['countType' => 'full', 'administrationId' => 'adm-1'])
		);
		self::assertFalse(
			$this->service->snapshotScope(['countId' => 'CC-1', 'administrationId' => 'adm-1'])
		);

	}//end testSnapshotScopeRequiresFields()

	/**
	 * emitAdjustments creates one StockMove per non-zero-variance line per
	 * REQ-ICC-007. Positive variance → receipt; negative variance → issue.
	 *
	 * @return void
	 */
	public function testEmitAdjustmentsCreatesStockMovesPerLine(): void {
		$this->objectService->lineRows = [
			// Negative variance → issue.
			[
				'lineId' => 'CC-2026-05-00001-001',
				'sku' => 'SKU-001',
				'locationId' => 'loc-w01-z01-b100',
				'expectedQuantity' => 100,
				'countedQuantity' => 95,
				'unitCost' => 50.00,
				'reasonCode' => 'ERR-COUNT',
			],
			// Positive variance → receipt.
			[
				'lineId' => 'CC-2026-05-00001-002',
				'sku' => 'SKU-002',
				'locationId' => 'loc-w01-z01-b100',
				'expectedQuantity' => 50,
				'countedQuantity' => 52,
				'unitCost' => 10.00,
				'reasonCode' => null,
			],
			// Zero variance → skipped.
			[
				'lineId' => 'CC-2026-05-00001-003',
				'sku' => 'SKU-003',
				'locationId' => 'loc-w01-z01-b100',
				'expectedQuantity' => 30,
				'countedQuantity' => 30,
				'unitCost' => 1.00,
				'reasonCode' => null,
			],
		];

		$count = [
			'countId' => 'CC-2026-05-00001',
			'administrationId' => 'adm-consultancy-nl',
		];

		self::assertTrue($this->service->emitAdjustments($count));
		self::assertCount(2, $this->objectService->savedMoves);

		$issueMove = $this->objectService->savedMoves[0];
		self::assertSame('issue', $issueMove['movementType']);
		self::assertSame('loc-w01-z01-b100', $issueMove['sourceLocationId']);
		self::assertNull($issueMove['destinationLocationId']);
		self::assertSame(5.00, $issueMove['quantity']);
		self::assertSame('cycle-count-variance', $issueMove['movementReason']);
		self::assertSame(
			'shillinq://cycle-count/CC-2026-05-00001',
			$issueMove['referenceDocumentUri']
		);

		$receiptMove = $this->objectService->savedMoves[1];
		self::assertSame('receipt', $receiptMove['movementType']);
		self::assertNull($receiptMove['sourceLocationId']);
		self::assertSame('loc-w01-z01-b100', $receiptMove['destinationLocationId']);
		self::assertSame(2.00, $receiptMove['quantity']);

	}//end testEmitAdjustmentsCreatesStockMovesPerLine()

	/**
	 * emitAdjustments is idempotent per line: a line already carrying
	 * adjustmentStockMoveId is skipped per REQ-ICC-007.
	 *
	 * @return void
	 */
	public function testEmitAdjustmentsIdempotentPerLine(): void {
		$this->objectService->lineRows = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'sku' => 'SKU-001',
				'locationId' => 'loc-w01-z01-b100',
				'expectedQuantity' => 100,
				'countedQuantity' => 95,
				'unitCost' => 50.00,
				'reasonCode' => 'ERR-COUNT',
				'adjustmentStockMoveId' => 'sm-existing-001',
			],
			// Second line still needs posting.
			[
				'lineId' => 'CC-2026-05-00001-002',
				'sku' => 'SKU-002',
				'locationId' => 'loc-w01-z01-b100',
				'expectedQuantity' => 50,
				'countedQuantity' => 60,
				'unitCost' => 10.00,
				'reasonCode' => null,
				'adjustmentStockMoveId' => null,
			],
		];

		$count = [
			'countId' => 'CC-2026-05-00001',
			'administrationId' => 'adm-consultancy-nl',
		];

		self::assertTrue($this->service->emitAdjustments($count));
		// Only line 2 should have produced a StockMove.
		self::assertCount(1, $this->objectService->savedMoves);

	}//end testEmitAdjustmentsIdempotentPerLine()

	/**
	 * emitAdjustments returns true with no work when the count has no lines
	 * (edge case).
	 *
	 * @return void
	 */
	public function testEmitAdjustmentsZeroLinesPasses(): void {
		$this->objectService->lineRows = [];

		$count = [
			'countId' => 'CC-2026-05-00001',
			'administrationId' => 'adm-consultancy-nl',
		];

		self::assertTrue($this->service->emitAdjustments($count));
		self::assertCount(0, $this->objectService->savedMoves);

	}//end testEmitAdjustmentsZeroLinesPasses()

	/**
	 * emitAdjustments denies when required fields missing (fail-closed).
	 *
	 * @return void
	 */
	public function testEmitAdjustmentsRequiresFields(): void {
		self::assertFalse(
			$this->service->emitAdjustments(['administrationId' => 'adm-1'])
		);

	}//end testEmitAdjustmentsRequiresFields()

	/**
	 * emitAdjustments stamps the resulting StockMove id back on the originating
	 * line per REQ-ICC-007 traceability.
	 *
	 * @return void
	 */
	public function testEmitAdjustmentsBackReferencesStockMove(): void {
		$this->objectService->lineRows = [
			[
				'lineId' => 'CC-2026-05-00001-001',
				'sku' => 'SKU-001',
				'locationId' => 'loc-w01-z01-b100',
				'expectedQuantity' => 100,
				'countedQuantity' => 95,
				'unitCost' => 50.00,
				'reasonCode' => 'ERR-COUNT',
			],
		];

		$count = [
			'countId' => 'CC-2026-05-00001',
			'administrationId' => 'adm-consultancy-nl',
		];

		self::assertTrue($this->service->emitAdjustments($count));
		self::assertCount(1, $this->objectService->savedMoves);

		// Find the line update — the saveCount should now include a follow-up save on the
		// line with adjustmentStockMoveId stamped.
		$lineUpdates = array_values(
			array_filter(
				$this->objectService->savedLines,
				static fn (array $line): bool => ($line['lineId'] ?? '') === 'CC-2026-05-00001-001'
					&& ($line['adjustmentStockMoveId'] ?? null) !== null
			)
		);
		self::assertCount(1, $lineUpdates);
		self::assertSame('sm-id-1', $lineUpdates[0]['adjustmentStockMoveId']);

	}//end testEmitAdjustmentsBackReferencesStockMove()
}//end class
