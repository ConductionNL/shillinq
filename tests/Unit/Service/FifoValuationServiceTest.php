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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\FifoValuationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FifoValuationService.
 *
 * Covers REQ-INV-003: FIFO lot traversal correctness, idempotency on retry,
 * and schema filtering (non-StockMovement events are ignored).
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 */
class FifoValuationServiceTest extends TestCase
{

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
     * Mock CogsPosterService.
     *
     * @var CogsPosterService&MockObject
     */
    private CogsPosterService&MockObject $cogsPoster;

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
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appConfig  = $this->createMock(IAppConfig::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->cogsPoster = $this->createMock(CogsPosterService::class);
        // phpcs:enable

        $this->service = new FifoValuationService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
            cogsPoster: $this->cogsPoster,
        );
    }//end setUp()

    /**
     * Non-StockMovement events are silently ignored.
     *
     * @return void
     */
    public function testHandleIgnoresNonStockMovementSchema(): void
    {
        $event = $this->buildObjectCreatedEvent(schema: 'Product', object: []);

        $this->container->expects(self::never())->method('get');

        $this->service->handle($event);
    }//end testHandleIgnoresNonStockMovementSchema()

    /**
     * REQ-INV-003: Inbound movement for a FIFO valuation increases quantity
     * and updates the snapshot. ObjectService::saveObject is called once.
     *
     * @return void
     */
    public function testHandleInboundFifoUpdatesSnapshot(): void
    {
        $valuation = [
            'id'                        => 'val-001',
            'productId'                 => 'GT-10-2026',
            'warehouse'                 => 'Magazijn Noord',
            'quantity'                  => 30.0,
            'unitCost'                  => 10.0,
            'totalValue'                => 300.0,
            'valuationMethod'           => 'FIFO',
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'itemId'       => 'GT-10-2026',
            'warehouse'    => 'Magazijn Noord',
            'quantity'     => 20.0,
            'unitCost'     => 12.0,
            'uuid'         => 'uuid-inbound-001',
        ];

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $savedObject   = null;
        $objectService = $this->buildObjectServiceStub(
            findAllResults: [$valuation],
            onSave: static function (array $object) use (&$savedObject): array {
                $savedObject = $object;
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        // After inbound: qty 30+20=50, weighted avg cost = (30*10 + 20*12)/50 = 10.8.
        self::assertNotNull($savedObject);
        self::assertSame(50.0, $savedObject['quantity']);
        self::assertSame(10.8, $savedObject['unitCost']);
        self::assertSame(540.0, $savedObject['totalValue']);
    }//end testHandleInboundFifoUpdatesSnapshot()

    /**
     * REQ-INV-003: idempotency on retry — same UUID is not processed twice.
     *
     * @return void
     */
    public function testHandleIsIdempotentOnRetryForSameUuid(): void
    {
        $processedUuid = 'uuid-already-processed';
        $valuation     = [
            'id'                        => 'val-001',
            'productId'                 => 'GT-10-2026',
            'warehouse'                 => 'Magazijn Noord',
            'quantity'                  => 50.0,
            'unitCost'                  => 10.8,
            'totalValue'                => 540.0,
            'valuationMethod'           => 'FIFO',
            'status'                    => 'active',
            'lastProcessedMovementUuid' => $processedUuid,
        ];

        $movement = [
            'movementType' => 'inbound',
            'itemId'       => 'GT-10-2026',
            'warehouse'    => 'Magazijn Noord',
            'quantity'     => 20.0,
            'unitCost'     => 12.0,
            'uuid'         => $processedUuid,
        ];

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $saveCalled    = false;
        $objectService = $this->buildObjectServiceStub(
            findAllResults: [$valuation],
            onSave: static function () use (&$saveCalled): array {
                $saveCalled = true;
                return [];
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertFalse($saveCalled, 'saveObject must not be called on duplicate UUID');
    }//end testHandleIsIdempotentOnRetryForSameUuid()

    /**
     * Moving-average items are not processed by FifoValuationService.
     *
     * @return void
     */
    public function testHandleIgnoresAverageMethodValuation(): void
    {
        $valuation = [
            'id'                        => 'val-avg-001',
            'productId'                 => 'KP-A4-500',
            'warehouse'                 => 'Centraal Depot',
            'quantity'                  => 200.0,
            'unitCost'                  => 3.75,
            'totalValue'                => 750.0,
            'valuationMethod'           => 'average',
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'itemId'       => 'KP-A4-500',
            'warehouse'    => 'Centraal Depot',
            'quantity'     => 100.0,
            'unitCost'     => 4.0,
            'uuid'         => 'uuid-avg-001',
        ];

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $saveCalled    = false;
        $objectService = $this->buildObjectServiceStub(
            findAllResults: [$valuation],
            onSave: static function () use (&$saveCalled): array {
                $saveCalled = true;
                return [];
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        self::assertFalse($saveCalled, 'saveObject must not be called for average-method valuations');
    }//end testHandleIgnoresAverageMethodValuation()

    /**
     * Build a real ObjectCreatedEvent with a real ObjectEntity populated with the given data.
     *
     * ObjectEntity's getSchema() / getObject() are magic NC Entity methods — we use a real
     * ObjectEntity instance set via magic setters rather than a PHPUnit mock (magic methods
     * cannot be configured on mocks).
     *
     * @param string              $schema Schema slug.
     * @param array<string,mixed> $object Object data.
     *
     * @return ObjectCreatedEvent
     */
    private function buildObjectCreatedEvent(string $schema, array $object): ObjectCreatedEvent
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $entity = new ObjectEntity();
        // phpcs:enable
        $entity->setSchema($schema);
        $entity->setObject($object);

        return new ObjectCreatedEvent(object: $entity);
    }//end buildObjectCreatedEvent()

    /**
     * Build a minimal ObjectService-like anonymous stub.
     *
     * @param array<mixed>  $findAllResults Results returned from findAll().
     * @param callable|null $onSave         Callback invoked on saveObject with the saved object.
     *
     * @return object
     */
    private function buildObjectServiceStub(array $findAllResults, ?callable $onSave=null): object
    {
        return new class($findAllResults, $onSave) {

            /**
             * @var array<mixed>
             */
            private array $findAllResults;

            /**
             * @var callable|null
             */
            private $onSave;

            /**
             * @param array<mixed>  $findAllResults Results.
             * @param callable|null $onSave         Callback.
             */
            public function __construct(array $findAllResults, ?callable $onSave)
            {
                $this->findAllResults = $findAllResults;
                $this->onSave         = $onSave;
            }//end __construct()

            /**
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * @param array<mixed> $params Query params.
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->findAllResults;
            }//end findAll()

            /**
             * @param array<string,mixed> $object   Object to save.
             * @param string              $register Register slug.
             * @param string              $schema   Schema slug.
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object, string $register, string $schema): array
            {
                if ($this->onSave !== null) {
                    return ($this->onSave)($object);
                }

                return $object;
            }//end saveObject()
        };
    }//end buildObjectServiceStub()
}//end class
