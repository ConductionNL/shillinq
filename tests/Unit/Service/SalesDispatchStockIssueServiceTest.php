<?php

/**
 * Unit tests for SalesDispatchStockIssueService.
 *
 * Proves the core inventory-sales-issue-cogs-trigger correctness fix: a
 * confirmed Delivery MUST produce an `issue` StockMove for every
 * stock-tracked line (REQ-001), MUST NOT double-issue on re-processing
 * (REQ-004), MUST skip non-stock-tracked lines (REQ-001), and MUST reverse
 * issued StockMoves through the existing StockMove.cancel transition on
 * delivery cancellation (REQ-006).
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
 * @spec openspec/changes/inventory-sales-issue-cogs-trigger/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SalesDispatchStockIssueService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SalesDispatchStockIssueService::issueForDelivery() and
 * ::reverseForDelivery().
 */
class SalesDispatchStockIssueServiceTest extends TestCase
{
    // phpcs:disable CustomSniffs.Functions.NamedParameters

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
     * The in-memory fake ObjectService (shared store across calls in a test).
     *
     * @var object
     */
    private object $fakeObjectService;

    /**
     * The service under test.
     *
     * @var SalesDispatchStockIssueService
     */
    private SalesDispatchStockIssueService $service;

    /**
     * Set up test fixtures: an in-memory fake ObjectService + a fake
     * TransitionEngine sharing the same StockMove store, wired through the
     * container by class name.
     *
     * @param array<int,array<string,mixed>> $inventoryStock Seed InventoryStock rows.
     *
     * @return void
     */
    private function setUpService(array $inventoryStock=[]): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $store = new \stdClass();
        $store->stockMoves     = [];
        $store->inventoryStock = $inventoryStock;
        $store->nextId         = 1;

        $this->fakeObjectService = new class ($store) {

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
            public function __construct(\stdClass $store)
            {
                $this->store = $store;

            }//end __construct()

            /**
             * Fluent register setter.
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;

            }//end setRegister()

            /**
             * Fluent schema setter.
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                $this->currentSchema = $schema;
                return $this;

            }//end setSchema()

            /**
             * Persist a new object, assigning a generated id.
             *
             * @param array<string,mixed> $object Object payload.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object): array
            {
                if ($this->currentSchema === 'StockMove') {
                    $id           = 'sm-'.$this->store->nextId++;
                    $object['id'] = $id;
                    $this->store->stockMoves[$id] = $object;
                    return $object;
                }

                return $object;

            }//end saveObject()

            /**
             * Update an existing object by id.
             *
             * @param string              $id       Object id.
             * @param array<string,mixed> $object   Patched payload.
             * @param string|null         $register Ignored.
             * @param string|null         $schema   Ignored.
             *
             * @return array<string,mixed>
             */
            public function updateObject(string $id, array $object, ?string $register=null, ?string $schema=null): array
            {
                $object['id'] = $id;
                if (isset($this->store->stockMoves[$id]) === true) {
                    $this->store->stockMoves[$id] = $object;
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
            public function find(string $id): ?array
            {
                return ($this->store->stockMoves[$id] ?? null);

            }//end find()

            /**
             * Return stubbed records for the active schema, applying a simple
             * equality filter when present.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $params=[]): array
            {
                if ($this->currentSchema === 'InventoryStock') {
                    $items = $this->store->inventoryStock;
                } else if ($this->currentSchema === 'StockMove') {
                    $items = array_values($this->store->stockMoves);
                } else {
                    $items = [];
                }

                $filters = ($params['filters'] ?? []);
                if ($filters === []) {
                    return $items;
                }

                return array_values(
                    array_filter(
                        $items,
                        static function (array $item) use ($filters): bool {
                            foreach ($filters as $field => $value) {
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

        $fakeTransitionEngine = new class ($store) {

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
            public function __construct(\stdClass $store)
            {
                $this->store = $store;

            }//end __construct()

            /**
             * Simulate driving a StockMove through its declarative
             * lifecycle: 'post' -> posted/locked/postedAt stamped;
             * 'cancel' -> cancelled/cancelledAt stamped.
             *
             * @param string $id     Object id.
             * @param string $action Transition action name.
             *
             * @return array<string,mixed>
             */
            public function transition(string $id, string $action): array
            {
                $move = ($this->store->stockMoves[$id] ?? null);
                if ($move === null) {
                    throw new \RuntimeException('not found');
                }

                if ($action === 'post') {
                    $move['lifecycleState'] = 'posted';
                    $move['locked']         = true;
                    $move['postedAt']       = gmdate('Y-m-d\TH:i:s\Z');
                } else if ($action === 'cancel') {
                    $move['lifecycleState'] = 'cancelled';
                    $move['cancelledAt']    = gmdate('Y-m-d\TH:i:s\Z');
                }

                $this->store->stockMoves[$id] = $move;
                return $move;

            }//end transition()
        };

        $objectService = $this->fakeObjectService;
        $this->container->method('has')->willReturnCallback(
            static fn(string $class): bool => $class === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine'
        );
        $this->container->method('get')->willReturnCallback(
            static function (string $class) use ($objectService, $fakeTransitionEngine): object {
                if ($class === 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine') {
                    return $fakeTransitionEngine;
                }

                return $objectService;
            }
        );

        $this->service = new SalesDispatchStockIssueService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUpService()

    /**
     * A confirmed delivery with one stock-tracked line issues exactly one
     * posted `issue` StockMove (REQ-001).
     *
     * @return void
     */
    public function testIssueForDeliveryCreatesPostedIssueMoveForStockTrackedLine(): void
    {
        $this->setUpService(
            inventoryStock: [
                ['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 20, 'reservedQuantity' => 0],
            ]
        );

        $result = $this->service->issueForDelivery(
            delivery: [
                'id'               => 'dn-1',
                'administrationId' => 'adm-1',
                'lines'            => [
                    ['orderLineReference' => 'sol-1', 'productReference' => 'sku-a', 'quantityShipped' => 5],
                ],
            ]
        );

        self::assertSame(1, $result['issued']);
        self::assertCount(1, $result['moves']);
        self::assertSame('issue', $result['moves'][0]['movementType']);
        self::assertSame('posted', $result['moves'][0]['lifecycleState']);
        self::assertSame(5.0, $result['moves'][0]['quantity']);
        self::assertSame('sku-a', $result['moves'][0]['itemId']);

    }//end testIssueForDeliveryCreatesPostedIssueMoveForStockTrackedLine()

    /**
     * A line whose product has no InventoryStock row is skipped as a
     * service line — not an error.
     *
     * @return void
     */
    public function testIssueForDeliverySkipsNonStockTrackedLine(): void
    {
        $this->setUpService(inventoryStock: []);

        $result = $this->service->issueForDelivery(
            delivery: [
                'id'               => 'dn-1',
                'administrationId' => 'adm-1',
                'lines'            => [
                    ['orderLineReference' => 'sol-1', 'productReference' => 'sku-service', 'quantityShipped' => 3],
                ],
            ]
        );

        self::assertSame(0, $result['issued']);
        self::assertSame(1, $result['skipped']);

    }//end testIssueForDeliverySkipsNonStockTrackedLine()

    /**
     * Re-processing an already-confirmed delivery does not double-issue
     * (REQ-004 idempotency).
     *
     * @return void
     */
    public function testIssueForDeliveryIsIdempotentOnReprocess(): void
    {
        $this->setUpService(
            inventoryStock: [
                ['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 20, 'reservedQuantity' => 0],
            ]
        );

        $delivery = [
            'id'               => 'dn-1',
            'administrationId' => 'adm-1',
            'lines'            => [
                ['orderLineReference' => 'sol-1', 'productReference' => 'sku-a', 'quantityShipped' => 5],
            ],
        ];

        $first  = $this->service->issueForDelivery(delivery: $delivery);
        $second = $this->service->issueForDelivery(delivery: $delivery);

        self::assertSame(1, $first['issued']);
        self::assertSame(0, $second['issued']);
        self::assertSame(1, $second['skipped']);

    }//end testIssueForDeliveryIsIdempotentOnReprocess()

    /**
     * Cancelling a delivery reverses the StockMove(s) it issued via the
     * existing StockMove.cancel transition (REQ-006).
     *
     * @return void
     */
    public function testReverseForDeliveryCancelsIssuedMoves(): void
    {
        $this->setUpService(
            inventoryStock: [
                ['sku' => 'sku-a', 'administrationId' => 'adm-1', 'locationId' => 'loc-1', 'quantity' => 20, 'reservedQuantity' => 0],
            ]
        );

        $delivery = [
            'id'               => 'dn-1',
            'administrationId' => 'adm-1',
            'lines'            => [
                ['orderLineReference' => 'sol-1', 'productReference' => 'sku-a', 'quantityShipped' => 5],
            ],
        ];

        $this->service->issueForDelivery(delivery: $delivery);
        $result = $this->service->reverseForDelivery(delivery: $delivery);

        self::assertSame(1, $result['reversed']);
        self::assertSame(0, $result['failed']);

    }//end testReverseForDeliveryCancelsIssuedMoves()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
