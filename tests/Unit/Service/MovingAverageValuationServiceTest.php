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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
// phpcs:ignore -- class exists check handled at runtime.
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for MovingAverageValuationService.
 *
 * Covers REQ-INV-004: weighted average recalculation on inbound receipt,
 * outbound COGS amount at current average cost, and idempotency.
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */
class MovingAverageValuationServiceTest extends TestCase
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
        $this->container  = $this->createMock(ContainerInterface::class);
        $this->appConfig  = $this->createMock(IAppConfig::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
        $this->cogsPoster = $this->createMock(CogsPosterService::class);
        // phpcs:enable

        $this->service = new MovingAverageValuationService(
            container: $this->container,
            appConfig: $this->appConfig,
            logger: $this->logger,
            cogsPoster: $this->cogsPoster,
        );
    }//end setUp()

    /**
     * REQ-INV-004: moving-average recalculates correctly on inbound receipt.
     *
     * Spec scenario: KP-A4-500 qty 100, unitCost 3.50 + receipt qty 200 @ 4.00
     * => new_unitCost = (100*3.50 + 200*4.00) / 300 = 3.8333 (4 dp)
     * => totalValue = 300 * 3.8333 = 1149.99 (2 dp)
     *
     * @return void
     */
    public function testHandleInboundRecalculatesWeightedAverage(): void
    {
        $valuation = [
            'id'                        => 'val-avg-001',
            'productId'                 => 'KP-A4-500',
            'warehouse'                 => 'Centraal Depot',
            'quantity'                  => 100.0,
            'unitCost'                  => 3.50,
            'totalValue'                => 350.0,
            'valuationMethod'           => 'average',
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'itemId'       => 'KP-A4-500',
            'warehouse'    => 'Centraal Depot',
            'quantity'     => 200.0,
            'unitCost'     => 4.00,
            'uuid'         => 'uuid-avg-inbound-001',
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

        // new_avg = (100*3.50 + 200*4.00) / 300 = 1150/300 = 3.8333...
        self::assertNotNull($savedObject);
        self::assertSame(300.0, $savedObject['quantity']);
        self::assertSame(3.8333, $savedObject['unitCost']);
        self::assertSame(1150.0, $savedObject['totalValue']);
    }//end testHandleInboundRecalculatesWeightedAverage()

    /**
     * REQ-INV-004: outbound movement posts COGS at current average cost.
     *
     * Spec scenario: KP-A4-500 unitCost 3.8333, outbound qty 50
     * => COGS = 50 × 3.8333 = 191.67
     *
     * @return void
     */
    public function testHandleOutboundPostsCogsAtCurrentAverageCost(): void
    {
        $valuation = [
            'id'                        => 'val-avg-001',
            'productId'                 => 'KP-A4-500',
            'warehouse'                 => 'Centraal Depot',
            'quantity'                  => 300.0,
            'unitCost'                  => 3.8333,
            'totalValue'                => 1150.0,
            'valuationMethod'           => 'average',
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'outbound',
            'itemId'       => 'KP-A4-500',
            'warehouse'    => 'Centraal Depot',
            'quantity'     => 50.0,
            'unitCost'     => 3.8333,
            'uuid'         => 'uuid-avg-outbound-001',
        ];

        $this->appConfig->method('getValueString')->willReturn('shillinq');

        $objectService = $this->buildObjectServiceStub(
            findAllResults: [$valuation],
            onSave: static function (array $object): array {
                return $object;
            }
        );

        $this->container->method('get')->willReturn($objectService);

        $cogsAmountPassed = null;
        $this->cogsPoster
            ->expects(self::once())
            ->method('postCogs')
            ->willReturnCallback(
                static function (array $m, array $v, float $cogsAmount) use (&$cogsAmountPassed): void {
                    $cogsAmountPassed = $cogsAmount;
                }
            );

        $event = $this->buildObjectCreatedEvent(schema: 'StockMovement', object: $movement);

        $this->service->handle(event: $event);

        // COGS = 50 × 3.8333 = 191.665 rounded to 2dp = 191.67.
        self::assertSame(191.67, $cogsAmountPassed);
    }//end testHandleOutboundPostsCogsAtCurrentAverageCost()

    /**
     * FIFO items are silently ignored by MovingAverageValuationService.
     *
     * @return void
     */
    public function testHandleIgnoresFifoMethodValuation(): void
    {
        $valuation = [
            'id'                        => 'val-fifo-001',
            'productId'                 => 'GT-10-2026',
            'warehouse'                 => 'Magazijn Noord',
            'quantity'                  => 50.0,
            'unitCost'                  => 12.5,
            'totalValue'                => 625.0,
            'valuationMethod'           => 'FIFO',
            'status'                    => 'active',
            'lastProcessedMovementUuid' => '',
        ];

        $movement = [
            'movementType' => 'inbound',
            'itemId'       => 'GT-10-2026',
            'warehouse'    => 'Magazijn Noord',
            'quantity'     => 20.0,
            'unitCost'     => 13.0,
            'uuid'         => 'uuid-fifo-001',
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

        self::assertFalse($saveCalled, 'saveObject must not be called for FIFO-method valuations');
    }//end testHandleIgnoresFifoMethodValuation()

    /**
     * Build a real ObjectCreatedEvent using a real ObjectEntity populated with the given data.
     *
     * ObjectEntity's getSchema() / getObject() are magic NC Entity methods, so we use
     * a real ObjectEntity instance set via magic setters rather than a PHPUnit mock.
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
