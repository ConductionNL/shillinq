<?php

/**
 * Unit tests for DeliveryDispatchListener.
 *
 * The `testConfirmedDeliveryProducesIssueMoveThatDrivesCogsPosting` test is
 * the correctness proof required by tasks.md Task 6: on the pre-change
 * codebase, no code path ever creates an `issue` StockMove for a sale —
 * this test could not even be written (DeliveryDispatchListener did not
 * exist). Post-change, it demonstrates the full connection end-to-end
 * using REAL SalesDispatchStockIssueService, StockMoveTransitionedListener,
 * FifoValuationService, and CogsPosterService instances (only ObjectService
 * / TransitionEngine are faked, in-memory) — proving a sale dispatch
 * produces both an `issue` StockMove AND a posted COGS GLTransaction via
 * the pre-existing, UNMODIFIED valuation pipeline.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCA\Shillinq\Listener\DeliveryDispatchListener;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCA\Shillinq\Service\SalesDispatchStockIssueService;
use OCA\Shillinq\Sort\FefoSort;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DeliveryDispatchListener dispatch + the full sale -> issue ->
 * COGS wiring proof.
 */
class DeliveryDispatchListenerTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * Mock SalesDispatchStockIssueService.
	 *
	 * @var SalesDispatchStockIssueService&MockObject
	 */
	private SalesDispatchStockIssueService&MockObject $dispatchService;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test (mocked-service tests).
	 *
	 * @var DeliveryDispatchListener
	 */
	private DeliveryDispatchListener $listener;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->dispatchService = $this->createMock(SalesDispatchStockIssueService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new DeliveryDispatchListener(
			dispatchService: $this->dispatchService,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build an ObjectEntity stub for the given schema + payload.
	 *
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $payload Object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $payload): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getSchema')->willReturn($schema);
		$entity->method('getObject')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * A Delivery reaching `confirmed` forwards to issueForDelivery() (REQ-001).
	 *
	 * @return void
	 */
	public function testDeliveryConfirmedForwardsToIssue(): void {
		$payload = ['id' => 'dn-1', 'administrationId' => 'adm-1', 'lines' => []];
		$entity = $this->entity('Delivery', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'confirmed', 'getSchema' => 'Delivery']
		);

		$this->dispatchService->expects(self::once())
			->method('issueForDelivery')
			->with($payload);
		$this->dispatchService->expects(self::never())->method('reverseForDelivery');

		$this->listener->handle($event);

	}//end testDeliveryConfirmedForwardsToIssue()

	/**
	 * A Delivery reaching `cancelled` forwards to reverseForDelivery() (REQ-006).
	 *
	 * @return void
	 */
	public function testDeliveryCancelledForwardsToReverse(): void {
		$payload = ['id' => 'dn-1', 'administrationId' => 'adm-1', 'lines' => []];
		$entity = $this->entity('Delivery', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'cancelled', 'getSchema' => 'Delivery']
		);

		$this->dispatchService->expects(self::once())
			->method('reverseForDelivery')
			->with($payload);
		$this->dispatchService->expects(self::never())->method('issueForDelivery');

		$this->listener->handle($event);

	}//end testDeliveryCancelledForwardsToReverse()

	/**
	 * A Delivery transitioning to any other state is ignored.
	 *
	 * @return void
	 */
	public function testDeliveryOtherTransitionIgnored(): void {
		$payload = ['id' => 'dn-1'];
		$entity = $this->entity('Delivery', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'shipped', 'getSchema' => 'Delivery']
		);

		$this->dispatchService->expects(self::never())->method('issueForDelivery');
		$this->dispatchService->expects(self::never())->method('reverseForDelivery');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testDeliveryOtherTransitionIgnored()

	/**
	 * An unrelated schema is ignored without touching the dispatch service.
	 *
	 * @return void
	 */
	public function testUnrelatedSchemaIgnored(): void {
		$entity = $this->entity('StockMove', ['id' => 'sm-1']);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'confirmed', 'getSchema' => 'StockMove']
		);

		$this->dispatchService->expects(self::never())->method('issueForDelivery');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testUnrelatedSchemaIgnored()

	/**
	 * A downstream exception is logged but never propagates (fail-soft,
	 * mirrors StockMoveTransitionedListener's contract).
	 *
	 * @return void
	 */
	public function testDownstreamExceptionIsFailSoft(): void {
		$payload = ['id' => 'dn-1', 'administrationId' => 'adm-1', 'lines' => []];
		$entity = $this->entity('Delivery', $payload);
		$event = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $entity, 'getTo' => 'confirmed', 'getSchema' => 'Delivery']
		);

		$this->dispatchService->method('issueForDelivery')->willThrowException(new \RuntimeException('boom'));
		$this->logger->expects(self::once())->method('error');

		$this->listener->handle($event);
		self::assertTrue(true);

	}//end testDownstreamExceptionIsFailSoft()

	/**
	 * CORRECTNESS PROOF (tasks.md Task 6): confirming a Delivery produces a
	 * posted `issue` StockMove, and feeding that StockMove into the
	 * pre-existing, UNMODIFIED StockMoveTransitionedListener pipeline
	 * (FifoValuationService -> CogsPosterService) posts a balanced COGS
	 * GLTransaction. Only ObjectService / TransitionEngine are faked
	 * in-memory; every business-logic class under test is real.
	 *
	 * @return void
	 */
	public function testConfirmedDeliveryProducesIssueMoveThatDrivesCogsPosting(): void {
		$store = new \stdClass();
		$store->objects = ['StockMove' => [], 'InventoryStock' => [], 'InventoryValuation' => [], 'GLTransaction' => [], 'GLLine' => []];
		$store->nextId = 1;

		// Seed one posted receipt so FIFO has an open lot to consume:
		// 10 units @ EUR 6.00 for sku-a at loc-1 / adm-1.
		$store->objects['StockMove']['sm-receipt-1'] = [
			'id' => 'sm-receipt-1',
			'movementNumber' => 'SM-2026-0001',
			'itemId' => 'sku-a',
			'quantity' => 10.0,
			'unitCost' => 6.00,
			'movementType' => 'receipt',
			'sourceLocationId' => null,
			'destinationLocationId' => 'loc-1',
			'administrationId' => 'adm-1',
			'lifecycleState' => 'posted',
			'postedAt' => '2026-07-01T09:00:00Z',
		];
		$store->objects['InventoryStock'][] = [
			'sku' => 'sku-a',
			'administrationId' => 'adm-1',
			'locationId' => 'loc-1',
			'quantity' => 10,
			'reservedQuantity' => 0,
		];

		$fakeObjectService = new class($store) {

			/**
			 * Shared in-memory store.
			 *
			 * @var \stdClass
			 */
			private \stdClass $store;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			public string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param \stdClass $store Shared store.
			 */
			public function __construct(\stdClass $store) {
				$this->store = $store;

			}//end __construct()

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
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Persist a new object, assigning a generated id for id-keyed
			 * schemas (StockMove); appended for list-shaped schemas.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				$schema = $this->currentSchema;
				if ($schema === 'StockMove') {
					$id = ($object['id'] ?? ('sm-' . $this->store->nextId++));
					$object['id'] = $id;
					$this->store->objects['StockMove'][$id] = $object;
					return $object;
				}

				if ($schema === 'GLTransaction' || $schema === 'GLLine' || $schema === 'InventoryValuation') {
					$id = 'obj-' . $this->store->nextId++;
					$object['id'] = $id;
					if (isset($this->store->objects[$schema]) === false) {
						$this->store->objects[$schema] = [];
					}

					$this->store->objects[$schema][] = $object;
					return $object;
				}

				return $object;
			}//end saveObject()

			/**
			 * Update an existing object by id (StockMove only, in this fixture).
			 *
			 * @param string $id Object id.
			 * @param array $object Patched payload.
			 * @param string|null $register Ignored.
			 * @param string|null $schema Ignored.
			 *
			 * @return array<string,mixed>
			 */
			public function updateObject(string $id, array $object, ?string $register = null, ?string $schema = null): array {
				$object['id'] = $id;
				if (isset($this->store->objects['StockMove'][$id]) === true) {
					$this->store->objects['StockMove'][$id] = $object;
				}

				return $object;
			}//end updateObject()

			/**
			 * Find a StockMove by id.
			 *
			 * @param string $id Object id.
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id): ?array {
				return ($this->store->objects['StockMove'][$id] ?? null);
			}//end find()

			/**
			 * Return stubbed records for the active schema, applying a simple
			 * equality filter when present.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$raw = ($this->store->objects[$this->currentSchema] ?? []);
				$items = array_values($raw);

				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $items;
				}

				return array_values(
					array_filter(
						$items,
						static function (array $item) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (is_array($value) === true) {
									// E.g. {'not' => 'x'} shape used elsewhere — not needed here.
									continue;
								}

								if (($item[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

			}//end findAll()
		};

		$fakeTransitionEngine = new class($store) {

			/**
			 * Shared in-memory store.
			 *
			 * @var \stdClass
			 */
			private \stdClass $store;

			/**
			 * Constructor.
			 *
			 * @param \stdClass $store Shared store.
			 */
			public function __construct(\stdClass $store) {
				$this->store = $store;

			}//end __construct()

			/**
			 * Simulate driving a StockMove through its declarative lifecycle.
			 *
			 * @param string $id Object id.
			 * @param string $action Transition action name.
			 *
			 * @return array<string,mixed>
			 */
			public function transition(string $id, string $action): array {
				$move = ($this->store->objects['StockMove'][$id] ?? null);
				if ($move === null) {
					throw new \RuntimeException('not found');
				}

				if ($action === 'post') {
					$move['lifecycleState'] = 'posted';
					$move['locked'] = true;
					$move['postedAt'] = gmdate('Y-m-d\TH:i:s\Z');
				}

				$this->store->objects['StockMove'][$id] = $move;
				return $move;
			}//end transition()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(
			static fn (string $class): bool => $class === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine'
		);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($fakeObjectService, $fakeTransitionEngine): object {
				if ($class === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine') {
					return $fakeTransitionEngine;
				}

				return $fakeObjectService;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				return match ($key) {
					'cogs_account' => '7000',
					'inventory_account' => '1300',
					default => 'shillinq',
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		// Real business-logic classes — nothing about the existing valuation
		// + COGS pipeline is mocked or reimplemented.
		$fifo = new FifoValuationService(appConfig: $appConfig, logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$average = new MovingAverageValuationService(appConfig: $appConfig, logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$cogs = new CogsPosterService(appConfig: $appConfig, logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$stockMoveListener = new StockMoveTransitionedListener(
			fifo: $fifo,
			average: $average,
			cogs: $cogs,
			appConfig: $appConfig,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);

		$dispatchService = new SalesDispatchStockIssueService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
			lotGuard: new LotSellabilityGuard(fefoSort: new FefoSort()),
			objectService: new DuckObjectServiceAdapter($fakeObjectService),
		);
		$deliveryListener = new DeliveryDispatchListener(dispatchService: $dispatchService, logger: $logger);

		// Step 1: confirm a Delivery for 3 units of sku-a -> expect exactly
		// one posted `issue` StockMove (REQ-001) where NONE existed before.
		self::assertCount(1, $store->objects['StockMove'], 'only the seeded receipt exists before confirm');

		$deliveryPayload = [
			'id' => 'dn-1',
			'administrationId' => 'adm-1',
			'lines' => [
				['orderLineReference' => 'sol-1', 'productReference' => 'sku-a', 'quantityShipped' => 3],
			],
		];
		$deliveryEntity = $this->entity('Delivery', $deliveryPayload);
		$deliveryEvent = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $deliveryEntity, 'getTo' => 'confirmed', 'getSchema' => 'Delivery']
		);

		$deliveryListener->handle($deliveryEvent);

		self::assertCount(2, $store->objects['StockMove'], 'delivery confirm created exactly one new StockMove');
		$issueMove = null;
		foreach ($store->objects['StockMove'] as $move) {
			if (($move['movementType'] ?? '') === 'issue') {
				$issueMove = $move;
			}
		}

		self::assertNotNull($issueMove, 'an issue StockMove was created');
		self::assertSame('posted', $issueMove['lifecycleState']);
		self::assertSame(3.0, $issueMove['quantity']);
		self::assertSame('sku-a', $issueMove['itemId']);

		// Step 2: feed the newly-posted issue StockMove into the EXISTING,
		// UNMODIFIED StockMoveTransitionedListener pipeline — the same
		// dispatch every receipt/cycle-count move already goes through —
		// and prove it posts a balanced COGS GLTransaction (REQ-002).
		self::assertCount(0, $store->objects['GLTransaction'], 'no GL entries exist before the issue move posts');

		$stockMoveEntity = $this->entity('StockMove', $issueMove);
		$stockMoveEvent = $this->createConfiguredMock(
			ObjectTransitionedEvent::class,
			['getObject' => $stockMoveEntity, 'getTo' => 'posted', 'getSchema' => 'StockMove']
		);

		$stockMoveListener->handle($stockMoveEvent);

		self::assertCount(1, $store->objects['GLTransaction'], 'issuing stock posted exactly one COGS GLTransaction');
		self::assertCount(2, $store->objects['GLLine'], 'the COGS GLTransaction has a debit + credit line');

		$lines = $store->objects['GLLine'];
		$debit = null;
		$credit = null;
		foreach ($lines as $line) {
			if (($line['side'] ?? '') === 'debit') {
				$debit = $line;
			} elseif (($line['side'] ?? '') === 'credit') {
				$credit = $line;
			}
		}

		self::assertNotNull($debit);
		self::assertNotNull($credit);
		self::assertSame('7000', $debit['accountNumber'], 'COGS debit hits the configured COGS account');
		self::assertSame('1300', $credit['accountNumber'], 'COGS credit hits the configured inventory asset account');
		// 3 units consumed from the EUR 6.00 FIFO lot = EUR 18.00.
		self::assertEqualsWithDelta(18.00, (float)$debit['amount'], 0.001);
		self::assertEqualsWithDelta(18.00, (float)$credit['amount'], 0.001);

	}//end testConfirmedDeliveryProducesIssueMoveThatDrivesCogsPosting()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
