<?php

/**
 * Characterisation tests for CycleCountService::emitAdjustments().
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
use RuntimeException;

/**
 * Pins the full StockMove payload, the save order and every deny branch of
 * emitAdjustments() so its split into named steps keeps behaviour identical.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
class CycleCountServiceEmitAdjustmentsTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Recording ObjectService double.
	 *
	 * @var object
	 */
	private object $objectService;

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

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->objectService = new class {

			/** @var string current schema set on the chain */
			public string $currentSchema = '';

			/** @var array<int,mixed> line rows served by findAll */
			public array $lineRows = [];

			/** @var array<int,array{0:string,1:array<string,mixed>}> every save in order */
			public array $saves = [];

			/** @var bool when true a StockMove save returns no id */
			public bool $moveWithoutId = false;

			/** @var bool when true a StockMove save throws */
			public bool $throwOnMove = false;

			/** Set OR register; chainable. */
			public function setRegister(string $register): self {
				return $this;
			}

			/** Set OR schema; chainable. */
			public function setSchema(string $schema): self {
				$this->currentSchema = $schema;
				return $this;
			}

			/** Stubbed findAll returning the line fixture. */
			public function findAll(array $args = []): array {
				return $this->lineRows;
			}

			/** Stubbed find returning empty. */
			public function find(array $args = []): array {
				return [];
			}

			/** Stubbed saveObject recording writes. */
			public function saveObject(array $obj): array {
				if ($this->currentSchema === 'StockMove' && $this->throwOnMove === true) {
					throw new RuntimeException('store down');
				}

				$this->saves[] = [$this->currentSchema, $obj];
				if ($this->currentSchema === 'StockMove' && $this->moveWithoutId === false) {
					$obj['id'] = 'sm-' . count($this->saves);
				}

				return $obj;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$container->method('get')->willReturn($this->objectService);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->service = new CycleCountService(
			appConfig: $appConfig,
			logger: $this->logger,
			varianceGate: new VarianceGate(container: $container, appConfig: $appConfig, logger: $this->logger),
			objectService: new DuckObjectServiceAdapter($this->objectService),
		);

	}//end setUp()

	/**
	 * A count line with the given overrides.
	 *
	 * @param array<string,mixed> $overrides Fields to override.
	 *
	 * @return array<string,mixed>
	 */
	private function line(array $overrides): array {
		return array_merge(
			[
				'lineId' => 'L-1',
				'sku' => 'SKU-1',
				'locationId' => 'loc-1',
				'expectedQuantity' => 10,
				'countedQuantity' => 7.5,
				'unitCost' => 2.25,
				'reasonCode' => 'DAMAGE',
			],
			$overrides
		);

	}//end line()

	/**
	 * The count under test.
	 *
	 * @return array<string,mixed>
	 */
	private function cycleCount(): array {
		return ['countId' => 'CC-9', 'administrationId' => 'adm-9'];

	}//end cycleCount()

	/**
	 * The full StockMove payload and the save order (move, then line stamp)
	 * are pinned; skipped lines (un-entered, already posted, zero
	 * variance) do not consume a movement sequence number.
	 *
	 * @return void
	 */
	public function testPayloadSaveOrderAndSequenceNumbering(): void {
		$this->objectService->lineRows = [
			$this->line(['lineId' => 'L-0', 'adjustmentStockMoveId' => 'sm-old']),
			$this->line(['lineId' => 'L-u', 'countedQuantity' => null]),
			$this->line(['lineId' => 'L-z', 'countedQuantity' => 10]),
			$this->line(['lineId' => 'L-1']),
			$this->line(['lineId' => 'L-2', 'countedQuantity' => 12, 'reasonCode' => null, 'unitCost' => 'x']),
		];

		self::assertTrue($this->service->emitAdjustments($this->cycleCount()));

		$saves = $this->objectService->saves;
		self::assertSame(['StockMove', 'InventoryCycleCountLine', 'StockMove', 'InventoryCycleCountLine'], array_column($saves, 0));

		$issue = $saves[0][1];
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $issue['draftedAt']);
		unset($issue['draftedAt']);
		self::assertSame(
			[
				'movementNumber' => 'SM-CC-CC-9-001',
				'itemId' => 'SKU-1',
				'quantity' => 2.5,
				'unitCost' => 2.25,
				'movementType' => 'issue',
				'sourceLocationId' => 'loc-1',
				'destinationLocationId' => null,
				'referenceDocumentUri' => 'shillinq://cycle-count/CC-9',
				'movementReason' => 'cycle-count-variance',
				'notes' => 'Variance adjustment for line L-1 (reasonCode=DAMAGE)',
				'administrationId' => 'adm-9',
				'lifecycleState' => 'draft',
				'locked' => false,
			],
			$issue
		);
		self::assertSame('sm-1', $saves[1][1]['adjustmentStockMoveId']);
		self::assertSame('L-1', $saves[1][1]['lineId']);

		$receipt = $saves[2][1];
		self::assertSame('SM-CC-CC-9-002', $receipt['movementNumber']);
		self::assertSame('receipt', $receipt['movementType']);
		self::assertNull($receipt['sourceLocationId']);
		self::assertSame('loc-1', $receipt['destinationLocationId']);
		self::assertSame(2.0, $receipt['quantity']);
		self::assertSame(0.0, $receipt['unitCost']);
		self::assertSame('Variance adjustment for line L-2 (reasonCode=n/a)', $receipt['notes']);

	}//end testPayloadSaveOrderAndSequenceNumbering()

	/**
	 * A line without sku or locationId denies the whole run before any save
	 * of that line, after earlier lines were posted.
	 *
	 * @return void
	 */
	public function testLineMissingLocationDenies(): void {
		$this->objectService->lineRows = [
			$this->line(['lineId' => 'L-1']),
			$this->line(['lineId' => 'L-2', 'locationId' => '']),
			$this->line(['lineId' => 'L-3']),
		];
		$this->logger->expects(self::once())
			->method('error')
			->with(
				'CycleCountService: emit-adjustments denied — line missing sku or locationId',
				['countId' => 'CC-9', 'lineId' => 'L-2']
			);

		self::assertFalse($this->service->emitAdjustments($this->cycleCount()));
		self::assertCount(2, $this->objectService->saves);

	}//end testLineMissingLocationDenies()

	/**
	 * A StockMove save without an id denies before the line is stamped.
	 *
	 * @return void
	 */
	public function testMoveWithoutIdDenies(): void {
		$this->objectService->lineRows = [$this->line([])];
		$this->objectService->moveWithoutId = true;
		$this->logger->expects(self::once())
			->method('error')
			->with(
				'CycleCountService: emit-adjustments — saveObject returned no id',
				['countId' => 'CC-9', 'lineId' => 'L-1']
			);

		self::assertFalse($this->service->emitAdjustments($this->cycleCount()));
		self::assertSame(['StockMove'], array_column($this->objectService->saves, 0));

	}//end testMoveWithoutIdDenies()

	/**
	 * A throwing save fails closed with the original log message.
	 *
	 * @return void
	 */
	public function testThrowingSaveFailsClosed(): void {
		$this->objectService->lineRows = [$this->line([])];
		$this->objectService->throwOnMove = true;
		$this->logger->expects(self::once())
			->method('error')
			->with(
				'CycleCountService: emit-adjustments failed — denying reconcile (fail-closed)',
				['countId' => 'CC-9', 'exception' => 'store down']
			);

		self::assertFalse($this->service->emitAdjustments($this->cycleCount()));

	}//end testThrowingSaveFailsClosed()

	/**
	 * Missing required fields and an empty line set log at info level.
	 *
	 * @return void
	 */
	public function testMissingFieldsAndNoLinesLogInfo(): void {
		$this->logger->expects(self::exactly(2))
			->method('info')
			->willReturnCallback(
				static function (string $message, array $context): void {
					self::assertContains(
						[$message, $context],
						[
							['CycleCountService: emit-adjustments denied — required fields missing', ['countId' => 'CC-9']],
							['CycleCountService: emit-adjustments skipped — no lines', ['countId' => 'CC-9']],
						]
					);
				}
			);

		self::assertFalse($this->service->emitAdjustments(['countId' => 'CC-9']));
		self::assertTrue($this->service->emitAdjustments($this->cycleCount()));

	}//end testMissingFieldsAndNoLinesLogInfo()
}//end class
