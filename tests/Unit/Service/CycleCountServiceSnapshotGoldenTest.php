<?php

/**
 * Golden test for CycleCountService::snapshotScope().
 *
 * Pins the exact register calls (schema, filters, order), the saved line
 * payloads and every log call of the snapshot path: required-field denial,
 * the idempotency skip, the empty scope, the location and category filters,
 * row skipping and both fail-closed exception paths.
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
use OCA\Shillinq\Tests\Unit\Service\Support\ObjectEntityStub;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden snapshot behaviour of CycleCountService.
 */
// phpcs:disable CustomSniffs.Functions.NamedParameters
class CycleCountServiceSnapshotGoldenTest extends TestCase {

	/**
	 * Recording ObjectService double.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Every logger call as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The service under test.
	 *
	 * @var CycleCountService
	 */
	private CycleCountService $service;

	/**
	 * Build the service around a recording store and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
		$this->store = new class {
			public string $schema = '';
			public array $rows = [];
			public array $calls = [];
			public ?string $failOn = null;

			public function setRegister(string $register): self {
				$this->calls[] = ['setRegister', $register];
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = ['setSchema', $schema];
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll', $this->schema, $args];
				if ($this->failOn === $this->schema) {
					throw new \RuntimeException('store down: ' . $this->schema);
				}

				$rows = ($this->rows[$this->schema] ?? []);
				if ($this->schema === 'Product') {
					return array_values(
						array_filter(
							$rows,
							static fn (ObjectEntityStub $p): bool => $p->jsonSerialize()['sku'] === $args['filters']['sku']
						)
					);
				}

				return $rows;
			}

			public function saveObject(array $obj): array {
				$this->calls[] = ['saveObject', $this->schema, $obj];
				if ($this->failOn === 'save') {
					throw new \RuntimeException('save refused');
				}

				return $obj;
			}
		};
		// phpcs:enable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('reg-x');
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$this->service = new CycleCountService(
			appConfig: $appConfig,
			logger: $logger,
			varianceGate: new VarianceGate(
				container: $this->createMock(ContainerInterface::class),
				appConfig: $appConfig,
				logger: $logger
			),
			objectService: new DuckObjectServiceAdapter($this->store),
		);

	}//end setUp()

	/**
	 * A missing countId, administrationId or countType denies before any read.
	 *
	 * @return void
	 */
	public function testMissingFieldsDenyWithoutReading(): void {
		$cases = [
			[['administrationId' => 'a', 'countType' => 'full'], null],
			[['countId' => 'C', 'countType' => 'full'], 'C'],
			[['countId' => 'C', 'administrationId' => 'a', 'countType' => ''], 'C'],
		];
		foreach ($cases as [$count, $loggedId]) {
			$this->logs = [];
			self::assertFalse($this->service->snapshotScope($count));
			self::assertSame(
				[['info', 'CycleCountService: snapshot denied — required fields missing', ['countId' => $loggedId]]],
				$this->logs
			);
		}

		self::assertSame([], $this->store->calls);

	}//end testMissingFieldsDenyWithoutReading()

	/**
	 * Existing lines skip the snapshot after exactly one line lookup.
	 *
	 * @return void
	 */
	public function testExistingLinesSkip(): void {
		$this->store->rows['InventoryCycleCountLine'] = [['lineId' => 'C-001']];

		self::assertTrue($this->service->snapshotScope($this->countPayload(extra: [])));
		self::assertSame(
			[
				['setRegister', 'reg-x'],
				['setSchema', 'InventoryCycleCountLine'],
				['findAll', 'InventoryCycleCountLine', ['filters' => ['administrationId' => 'adm-1', 'countId' => 'C']]],
			],
			$this->store->calls
		);
		self::assertSame([['info', 'CycleCountService: snapshot skipped — lines already exist', ['countId' => 'C']]], $this->logs);

	}//end testExistingLinesSkip()

	/**
	 * An empty scope logs and succeeds without saving.
	 *
	 * @return void
	 */
	public function testEmptyScopeSucceedsWithoutSaving(): void {
		self::assertTrue($this->service->snapshotScope($this->countPayload(extra: [])));
		self::assertSame([], $this->saves());
		self::assertSame([['info', 'CycleCountService: snapshot produced zero lines (empty scope)', ['countId' => 'C']]], $this->logs);

	}//end testEmptyScopeSucceedsWithoutSaving()

	/**
	 * Full count: every countable row becomes one line with the full payload;
	 * rows without sku or location are skipped without using a sequence number.
	 *
	 * @return void
	 */
	public function testFullCountSavesEveryCountableRow(): void {
		$this->store->rows['InventoryStock'] = [
			['sku' => 'S1', 'locationId' => 'L1', 'quantity' => 3, 'unitCost' => 2.345, 'productName' => 'Bolt'],
			['sku' => '', 'locationId' => 'L1', 'quantity' => 1],
			['sku' => 'S2', 'quantity' => 1],
			new ObjectEntityStub(payload: ['sku' => 'S3', 'locationId' => 'L2', 'quantity' => '1.5', 'unitCost' => 'x']),
			['sku' => 'S4', 'locationId' => 'L2', 'productName' => 7],
		];

		self::assertTrue($this->service->snapshotScope($this->countPayload(extra: ['locationFilter' => 'L9', 'categoryFilter' => 'cat'])));
		self::assertSame(
			['findAll', 'InventoryStock', ['filters' => ['administrationId' => 'adm-1']]],
			$this->store->calls[5]
		);
		self::assertSame(
			[
				$this->line(lineId: 'C-001', fields: ['S1', 'Bolt', 'L1', 3.0, 2.345, 7.05]),
				$this->line(lineId: 'C-002', fields: ['S3', null, 'L2', 1.5, 0.0, 0.0]),
				$this->line(lineId: 'C-003', fields: ['S4', '7', 'L2', 0.0, 0.0, 0.0]),
			],
			$this->saves()
		);
		self::assertSame([], $this->logs);
		self::assertSame(['setRegister', 'reg-x'], $this->store->calls[6]);
		self::assertSame(['setSchema', 'InventoryCycleCountLine'], $this->store->calls[7]);

	}//end testFullCountSavesEveryCountableRow()

	/**
	 * A partial count with a location filters the stock query by location
	 * and ignores the category filter.
	 *
	 * @return void
	 */
	public function testPartialLocationFiltersTheQuery(): void {
		$count = $this->countPayload(extra: ['countType' => 'partial', 'locationFilter' => 'L1', 'categoryFilter' => 'cat']);
		self::assertTrue($this->service->snapshotScope($count));
		self::assertSame(
			['findAll', 'InventoryStock', ['filters' => ['administrationId' => 'adm-1', 'locationId' => 'L1']]],
			$this->store->calls[5]
		);
		self::assertCount(6, $this->store->calls);

	}//end testPartialLocationFiltersTheQuery()

	/**
	 * A partial count by category looks up each distinct sku once, in order,
	 * and keeps only the rows whose product carries the category.
	 *
	 * @return void
	 */
	public function testPartialCategoryKeepsMatchingProducts(): void {
		$this->store->rows['InventoryStock'] = [
			['sku' => 'S1', 'locationId' => 'L1'],
			['sku' => 'S2', 'locationId' => 'L1'],
			['sku' => 'S1', 'locationId' => 'L2'],
			['sku' => '', 'locationId' => 'L3'],
			['locationId' => 'L4'],
			['sku' => 'S3', 'locationId' => 'L5'],
		];
		$this->store->rows['Product'] = [
			new ObjectEntityStub(payload: ['sku' => 'S1', 'category' => 'cat']),
			new ObjectEntityStub(payload: ['sku' => 'S2', 'category' => 'other']),
			new ObjectEntityStub(payload: ['sku' => 'S3']),
		];

		self::assertTrue($this->service->snapshotScope($this->countPayload(extra: ['countType' => 'partial', 'categoryFilter' => 'cat'])));
		$productQueries = array_values(
			array_filter($this->store->calls, static fn (array $c): bool => $c[0] === 'findAll' && $c[1] === 'Product')
		);
		self::assertSame(
			[
				['findAll', 'Product', ['filters' => ['administrationId' => 'adm-1', 'sku' => 'S1']]],
				['findAll', 'Product', ['filters' => ['administrationId' => 'adm-1', 'sku' => 'S2']]],
				['findAll', 'Product', ['filters' => ['administrationId' => 'adm-1', 'sku' => 'S3']]],
			],
			$productQueries
		);
		self::assertSame(['C-001', 'C-002'], array_column($this->saves(), 'lineId'));
		self::assertSame(['L1', 'L2'], array_column($this->saves(), 'locationId'));

	}//end testPartialCategoryKeepsMatchingProducts()

	/**
	 * A failing product lookup empties the scope; a failing stock lookup too.
	 *
	 * @return void
	 */
	public function testLookupFailuresEmptyTheScope(): void {
		$this->store->rows['InventoryStock'] = [['sku' => 'S1', 'locationId' => 'L1']];
		$this->store->failOn = 'Product';
		self::assertTrue($this->service->snapshotScope($this->countPayload(extra: ['countType' => 'partial', 'categoryFilter' => 'cat'])));
		self::assertSame(
			[
				['error', 'CycleCountService: filterByCategory failed', ['administrationId' => 'adm-1', 'category' => 'cat', 'exception' => 'store down: Product']],
				['info', 'CycleCountService: snapshot produced zero lines (empty scope)', ['countId' => 'C']],
			],
			$this->logs
		);

		$this->logs = [];
		$this->store->failOn = 'InventoryStock';
		self::assertTrue($this->service->snapshotScope($this->countPayload(extra: ['countType' => 'partial'])));
		self::assertSame(
			[
				['error', 'CycleCountService: findInScopeStock failed', ['administrationId' => 'adm-1', 'countType' => 'partial', 'exception' => 'store down: InventoryStock']],
				['info', 'CycleCountService: snapshot produced zero lines (empty scope)', ['countId' => 'C']],
			],
			$this->logs
		);

	}//end testLookupFailuresEmptyTheScope()

	/**
	 * A failing save denies the submit (fail-closed) after the first attempt.
	 *
	 * @return void
	 */
	public function testSaveFailureDenies(): void {
		$this->store->rows['InventoryStock'] = [['sku' => 'S1', 'locationId' => 'L1'], ['sku' => 'S2', 'locationId' => 'L1']];
		$this->store->failOn = 'save';

		self::assertFalse($this->service->snapshotScope($this->countPayload(extra: [])));
		self::assertCount(1, $this->saves());
		self::assertSame(
			[['error', 'CycleCountService: snapshot failed — denying submit (fail-closed)', ['countId' => 'C', 'exception' => 'save refused']]],
			$this->logs
		);

	}//end testSaveFailureDenies()

	/**
	 * A valid count payload with overrides.
	 *
	 * @param array<string, mixed> $extra Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function countPayload(array $extra): array {
		return array_merge(['countId' => 'C', 'administrationId' => 'adm-1', 'countType' => 'full'], $extra);

	}//end countPayload()

	/**
	 * The saved payloads, in order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function saves(): array {
		$saves = array_filter($this->store->calls, static fn (array $c): bool => $c[0] === 'saveObject');

		return array_values(array_map(static fn (array $c): array => $c[2], $saves));

	}//end saves()

	/**
	 * The expected line payload, keys in saved order.
	 *
	 * @param string $lineId Line id.
	 * @param list<mixed> $fields [sku, productName, locationId, expectedQuantity, unitCost, expectedValue].
	 *
	 * @return array<string, mixed>
	 */
	private function line(string $lineId, array $fields): array {
		return [
			'lineId' => $lineId,
			'countId' => 'C',
			'sku' => $fields[0],
			'productName' => $fields[1],
			'locationId' => $fields[2],
			'expectedQuantity' => $fields[3],
			'countedQuantity' => null,
			'unitCost' => $fields[4],
			'expectedValue' => $fields[5],
			'countedValue' => null,
			'quantityVariance' => null,
			'valueVariance' => null,
			'requiresReason' => false,
			'reasonCode' => null,
			'notes' => null,
			'adjustmentStockMoveId' => null,
			'administrationId' => 'adm-1',
		];

	}//end line()
}//end class
