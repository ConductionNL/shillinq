<?php

/**
 * Unit tests for MovingAverageValuationService.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MovingAverageValuationService.
 *
 * Covers moving-average inventory valuation: inbound weighted-average recomputation,
 * outbound COGS-at-current-unit-cost, idempotency, and early-return guards.
 *
 * @covers \OCA\Shillinq\Service\MovingAverageValuationService
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */
class MovingAverageValuationServiceTest extends TestCase
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
     * @var MovingAverageValuationService
     */
    private MovingAverageValuationService $service;

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

        $this->service = new MovingAverageValuationService(
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

        $event = $this->buildObjectCreatedEvent(schema: 'PurchaseOrder', object: []);
        $this->service->handle(event: $event);

    }//end testHandleIgnoresNonStockMovementSchema()

    /**
     * Inbound movement recalculates weighted moving average correctly.
     *
     * Current:  qty=100, unitCost=3.50 → value=350.
     * Receipt:  qty=200, unitCost=4.00 → value=800.
     * Expected: qty=300, unitCost=(350+800)/300=3.8333, totalValue=round(300×3.8333,2)=1150.00.
     *
     * @return void
     */
    public function testHandleInboundRecalculatesMovingAverage(): void
    {
        $valuation = [
            'id'                        => 'val-avg-010',
            'valuationMethod'           => 'average',
            'productId'                 => 'KL-500',
            'warehouse'                 => 'West',
            'quantity'                  => 100.0,
            'unitCost'                  => 3.50,
            'totalValue'                => 350.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'productId'    => 'KL-500',
            'warehouse'    => 'West',
            'quantity'     => 200.0,
            'unitCost'     => 4.00,
            'uuid'         => 'mv-avg-001',
        ];

        $savedObject = null;

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
            onSave: static function (array $object) use (&$savedObject): array {
                $savedObject = $object;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertIsArray(actual: $savedObject);
        self::assertSame(expected: 300.0, actual: $savedObject['quantity']);
        // phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar,Squiz.Commenting.InlineComment.NotCapital
        // (100×3.50 + 200×4.00) / 300 = 1150/300 = 3.8333...
        self::assertSame(expected: 3.8333, actual: $savedObject['unitCost']);
        // Round(300 × 3.8333..., 2) = round(1149.9999..., 2) = 1150.0.
        self::assertSame(expected: 1150.0, actual: $savedObject['totalValue']);
        self::assertSame(expected: 'mv-avg-001', actual: $savedObject['lastProcessedMovementUuid']);

    }//end testHandleInboundRecalculatesMovingAverage()

    /**
     * Inbound movement is skipped when the valuation method is FIFO, not average.
     *
     * @return void
     */
    public function testHandleInboundSkipsFifoValuation(): void
    {
        $valuation = [
            'id'                        => 'val-fifo-010',
            'valuationMethod'           => 'FIFO',
            'productId'                 => 'KL-500',
            'warehouse'                 => 'West',
            'quantity'                  => 100.0,
            'unitCost'                  => 3.50,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'productId'    => 'KL-500',
            'warehouse'    => 'West',
            'quantity'     => 50.0,
            'unitCost'     => 4.00,
            'uuid'         => 'mv-skip-010',
        ];

        $saveCallCount = 0;

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
            onSave: static function (array $object) use (&$saveCallCount): array {
                $saveCallCount++;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertSame(expected: 0, actual: $saveCallCount);

    }//end testHandleInboundSkipsFifoValuation()

    /**
     * Inbound movement is skipped when lastProcessedMovementUuid already matches (idempotency).
     *
     * @return void
     */
    public function testHandleInboundIdempotency(): void
    {
        $movementUuid = 'mv-idem-010';

        $valuation = [
            'id'                        => 'val-avg-011',
            'valuationMethod'           => 'average',
            'productId'                 => 'KL-600',
            'warehouse'                 => 'Oost',
            'quantity'                  => 200.0,
            'unitCost'                  => 5.0,
            'totalValue'                => 1000.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => $movementUuid,
        ];

        $movement = [
            'movementType' => 'inbound',
            'productId'    => 'KL-600',
            'warehouse'    => 'Oost',
            'quantity'     => 50.0,
            'unitCost'     => 6.0,
            'uuid'         => $movementUuid,
        ];

        $saveCallCount = 0;

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
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
     * Outbound movement on average valuation computes COGS at current unit cost and posts GL.
     *
     * Current:   qty=300, unitCost=3.8333.
     * Movement:  outbound, qty=50.
     * COGS:      round(50 × 3.8333, 2) = 191.67.
     * New qty:   250.
     * totalValue: round(250 × 3.8333, 2) = 958.33.
     *
     * @return void
     */
    public function testHandleOutboundComputesCogs(): void
    {
        $valuation = [
            'id'                        => 'val-avg-012',
            'valuationMethod'           => 'average',
            'productId'                 => 'KL-500',
            'warehouse'                 => 'West',
            'quantity'                  => 300.0,
            'unitCost'                  => 3.8333,
            'totalValue'                => 1150.0,
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
            'administrationId'          => 'admin-001',
        ];

        $movement = [
            'movementType' => 'outbound',
            'productId'    => 'KL-500',
            'warehouse'    => 'West',
            'quantity'     => 50.0,
            'uuid'         => 'mv-out-010',
        ];

        $savedObjects = [];
        $postedCogs   = null;

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $cogsPoster = $this->createMock(CogsPosterService::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters
        $cogsPoster
            ->expects($this->once())
            ->method('postCogs')
            ->willReturnCallback(
                static function (array $mv, array $val, float $cogsAmount) use (&$postedCogs): void {
                    $postedCogs = $cogsAmount;
                }
            );

        $objectService = $this->buildObjectServiceStub(
            valuations: [$valuation],
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

        // COGS = round(50 × 3.8333, 2) = 191.67.
        self::assertSame(expected: 191.67, actual: $postedCogs);
        self::assertNotEmpty(actual: $savedObjects);

        $savedValuation = $savedObjects[0];
        self::assertSame(expected: 250.0, actual: $savedValuation['quantity']);
        self::assertSame(expected: 'mv-out-010', actual: $savedValuation['lastProcessedMovementUuid']);

    }//end testHandleOutboundComputesCogs()

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
     * @param array<mixed>  $valuations Active InventoryValuation records to return.
     * @param callable|null $onSave     Callback invoked with the saved object; returns the saved array.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $valuations, ?callable $onSave=null): object
    {
        return new class($valuations, $onSave) {

            /**
             * Active InventoryValuation records.
             *
             * @var array<mixed>
             */
            private array $valuations;

            /**
             * Callback invoked on saveObject.
             *
             * @var callable|null
             */
            private $onSave;

            /**
             * Constructor.
             *
             * @param array<mixed>  $valuations Active InventoryValuation records.
             * @param callable|null $onSave     Save callback.
             */
            public function __construct(array $valuations, ?callable $onSave)
            {
                $this->valuations = $valuations;
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
             * Fluent schema setter.
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Return the configured valuations for all findAll calls.
             *
             * @param array<string,mixed> $config Query config (unused in stub).
             *
             * @return array<mixed>
             */
            public function findAll(array $config=[]): array
            {
                return $this->valuations;
            }//end findAll()

            /**
             * Delegate to onSave callback if set, otherwise return the object unchanged.
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
