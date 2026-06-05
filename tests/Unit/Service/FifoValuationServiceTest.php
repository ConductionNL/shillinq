<?php

/**
 * Unit tests for FifoValuationService.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\FifoValuationService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FifoValuationService.
 *
 * Covers FIFO inventory valuation logic: inbound weighted-average cost update,
 * outbound lot-traversal COGS computation, idempotency, and early-return guards.
 *
 * @covers \OCA\Shillinq\Service\FifoValuationService
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 */
class FifoValuationServiceTest extends TestCase
{

    /**
     * Mock ContainerInterface for lazy DI resolution.
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
     * The service under test.
     *
     * @var FifoValuationService
     */
    private FifoValuationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $this->service = new FifoValuationService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Non-ObjectCreatedEvent events are silently ignored — no container access.
     *
     * @return void
     */
    public function testHandleIgnoresNonObjectCreatedEvent(): void
    {
        $this->container->expects($this->never())->method('get');

        $this->service->handle(event: new Event());

    }//end testHandleIgnoresNonObjectCreatedEvent()

    /**
     * ObjectCreatedEvent with a non-StockMovement schema is ignored.
     *
     * @return void
     */
    public function testHandleIgnoresNonStockMovementSchema(): void
    {
        $this->container->expects($this->never())->method('get');

        $event = $this->buildObjectCreatedEvent(schema: 'JournalEntry', object: []);
        $this->service->handle(event: $event);

    }//end testHandleIgnoresNonStockMovementSchema()

    /**
     * Inbound movement on FIFO valuation updates quantity and weighted-average unit cost.
     *
     * Current: qty=30, unitCost=10.00 → total 300.
     * Receipt: qty=20, unitCost=12.00 → total 240.
     * Expected: qty=50, unitCost=(300+240)/50=10.8, totalValue=540.00.
     *
     * @return void
     */
    public function testHandleInboundUpdatesFifoValuation(): void
    {
        $valuation = [
            'id'                        => 'val-fifo-001',
            'valuationMethod'           => 'FIFO',
            'productId'                 => 'GT-10',
            'warehouse'                 => 'Noord',
            'quantity'                  => 30.0,
            'unitCost'                  => 10.0,
            'totalValue'                => 300.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'productId'    => 'GT-10',
            'warehouse'    => 'Noord',
            'quantity'     => 20.0,
            'unitCost'     => 12.0,
            'uuid'         => 'abc123',
        ];

        $savedObject = null;

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
            lots: [],
            onSave: static function (array $object) use (&$savedObject): array {
                $savedObject = $object;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertIsArray(actual: $savedObject);
        self::assertSame(expected: 50.0, actual: $savedObject['quantity']);
        self::assertSame(expected: 10.8, actual: $savedObject['unitCost']);
        self::assertSame(expected: 540.0, actual: $savedObject['totalValue']);
        self::assertSame(expected: 'abc123', actual: $savedObject['lastProcessedMovementUuid']);

    }//end testHandleInboundUpdatesFifoValuation()

    /**
     * Inbound movement is skipped when the valuation method is not FIFO.
     *
     * @return void
     */
    public function testHandleInboundSkipsNonFifoValuation(): void
    {
        $valuation = [
            'id'                        => 'val-avg-001',
            'valuationMethod'           => 'average',
            'productId'                 => 'GT-10',
            'warehouse'                 => 'Noord',
            'quantity'                  => 30.0,
            'unitCost'                  => 10.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'productId'    => 'GT-10',
            'warehouse'    => 'Noord',
            'quantity'     => 20.0,
            'unitCost'     => 12.0,
            'uuid'         => 'mv-skip-001',
        ];

        $saveCallCount = 0;

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
            lots: [],
            onSave: static function (array $object) use (&$saveCallCount): array {
                $saveCallCount++;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertSame(expected: 0, actual: $saveCallCount);

    }//end testHandleInboundSkipsNonFifoValuation()

    /**
     * Inbound movement is skipped when lastProcessedMovementUuid already matches (idempotency).
     *
     * @return void
     */
    public function testHandleInboundIdempotency(): void
    {
        $movementUuid = 'mv-idem-001';

        $valuation = [
            'id'                        => 'val-fifo-002',
            'valuationMethod'           => 'FIFO',
            'productId'                 => 'GT-20',
            'warehouse'                 => 'Zuid',
            'quantity'                  => 50.0,
            'unitCost'                  => 8.0,
            'totalValue'                => 400.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => $movementUuid,
        ];

        $movement = [
            'movementType' => 'inbound',
            'productId'    => 'GT-20',
            'warehouse'    => 'Zuid',
            'quantity'     => 10.0,
            'unitCost'     => 9.0,
            'uuid'         => $movementUuid,
        ];

        $saveCallCount = 0;

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
            lots: [],
            onSave: static function (array $object) use (&$saveCallCount): array {
                $saveCallCount++;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertSame(expected: 0, actual: $saveCallCount);

    }//end testHandleInboundIdempotency()

    /**
     * Outbound movement on FIFO valuation traverses lots and calls saveObject + CogsPosterService.
     *
     * Lot: qty=100, unitCost=5.00.
     * Movement: outbound, qty=30.
     * Expected COGS = 30 × 5.00 = 150.00; new qty = 70.
     *
     * @return void
     */
    public function testHandleOutboundTraversesLotsAndPostsCogs(): void
    {
        $valuation = [
            'id'                        => 'val-fifo-003',
            'valuationMethod'           => 'FIFO',
            'productId'                 => 'HP-100',
            'warehouse'                 => 'Oost',
            'quantity'                  => 100.0,
            'unitCost'                  => 5.0,
            'totalValue'                => 500.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
            'administrationId'          => 'admin-001',
        ];

        $movement = [
            'movementType' => 'outbound',
            'productId'    => 'HP-100',
            'warehouse'    => 'Oost',
            'quantity'     => 30.0,
            'uuid'         => 'mv-out-001',
        ];

        $lot = [
            'movementType'      => 'inbound',
            'productId'         => 'HP-100',
            'warehouse'         => 'Oost',
            'quantity'          => 100.0,
            'remainingQuantity' => 100.0,
            'unitCost'          => 5.0,
        ];

        $savedObjects   = [];
        $cogsPostCalled = false;
        $postedCogs     = null;

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $cogsPoster = $this->createMock(CogsPosterService::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters
        $cogsPoster
            ->expects($this->once())
            ->method('postCogs')
            ->willReturnCallback(
                static function (array $movement, array $valuation, float $cogsAmount) use (&$cogsPostCalled, &$postedCogs): void {
                    $cogsPostCalled = true;
                    $postedCogs     = $cogsAmount;
                }
            );

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
            lots: [$lot],
            onSave: static function (array $object) use (&$savedObjects): array {
                $savedObjects[] = $object;
                return $object;
            }
        );

        $this->container->method('get')->willReturnCallback(
            static function (string $class) use ($objectService, $cogsPoster): object {
                if ($class === CogsPosterService::class) {
                    return $cogsPoster;
                }

                return $objectService;
            }
        );

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertTrue(condition: $cogsPostCalled);
        self::assertSame(expected: 150.0, actual: $postedCogs);
        self::assertNotEmpty(actual: $savedObjects);

        $savedValuation = $savedObjects[0];
        self::assertSame(expected: 70.0, actual: $savedValuation['quantity']);
        self::assertSame(expected: 'mv-out-001', actual: $savedValuation['lastProcessedMovementUuid']);

    }//end testHandleOutboundTraversesLotsAndPostsCogs()

    /**
     * Build an ObjectCreatedEvent with an anonymous ObjectEntity subclass carrying the given data.
     *
     * ObjectEntity::getSchema() is a magic getter from OCP\AppFramework\Db\Entity; it cannot be
     * mocked directly in PHPUnit 10. We subclass ObjectEntity, set the protected $schema and
     * $object properties in the constructor, and pass the real subclass to ObjectCreatedEvent.
     *
     * @param string              $schema The schema name for the object entity.
     * @param array<string,mixed> $object The movement data array.
     *
     * @return ObjectCreatedEvent
     */
    private function buildObjectCreatedEvent(string $schema, array $object): ObjectCreatedEvent
    {
        // Anonymous subclass of ObjectEntity — sets protected fields directly.
        $objectEntity = new class($schema, $object) extends ObjectEntity {
            /**
             * Constructor — sets schema slug and object data on the entity.
             *
             * @param string              $schemaSlug Schema slug (written to protected $schema).
             * @param array<string,mixed> $objectData Object data (written to protected $object).
             */
            public function __construct(string $schemaSlug, array $objectData)
            {
                parent::__construct();
                $this->schema = $schemaSlug;
                $this->object = $objectData;
            }//end __construct()
        };

        return new ObjectCreatedEvent(object: $objectEntity);

    }//end buildObjectCreatedEvent()

    /**
     * Build an anonymous ObjectService stub with configurable findAll and saveObject behaviour.
     *
     * The stub returns $valuations for InventoryValuation findAll calls,
     * $lots for StockMovement findAll calls, and delegates save calls to $onSave.
     *
     * @param array<mixed>  $valuations Active InventoryValuation records to return.
     * @param array<mixed>  $lots       Inbound StockMovement lots to return for outbound traversal.
     * @param callable|null $onSave     Callback invoked with the saved object; returns the saved array.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $valuations, array $lots, ?callable $onSave=null): object
    {
        return new class($valuations, $lots, $onSave) {

            /**
             * Active InventoryValuation records.
             *
             * @var array<mixed>
             */
            private array $valuations;

            /**
             * Inbound StockMovement lots for FIFO outbound traversal.
             *
             * @var array<mixed>
             */
            private array $lots;

            /**
             * Callback invoked on saveObject.
             *
             * @var callable|null
             */
            private $onSave;

            /**
             * Current schema context ('InventoryValuation' or 'StockMovement').
             *
             * @var string
             */
            private string $currentSchema = '';

            /**
             * Constructor.
             *
             * @param array<mixed>  $valuations Active InventoryValuation records.
             * @param array<mixed>  $lots       Inbound StockMovement lots.
             * @param callable|null $onSave     Save callback.
             */
            public function __construct(array $valuations, array $lots, ?callable $onSave)
            {
                $this->valuations = $valuations;
                $this->lots       = $lots;
                $this->onSave     = $onSave;
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
             * Fluent schema setter — records the current schema for findAll routing.
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
             * Return records based on the current schema context.
             *
             * @param array<string,mixed> $config Query config (unused in stub).
             *
             * @return array<mixed>
             */
            public function findAll(array $config=[]): array
            {
                if ($this->currentSchema === 'StockMovement') {
                    return $this->lots;
                }

                return $this->valuations;
            }//end findAll()

            /**
             * Delegate to onSave callback if set, otherwise return the object.
             *
             * @param array<string,mixed> $object The object to save.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object): array
            {
                if ($this->onSave !== null) {
                    return ($this->onSave)($object);
                }

                return $object;
            }//end saveObject()
        };
    }//end buildObjectServiceStub()
}//end class
