<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Unit tests for ThreeWayMatchingService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ThreeWayMatchingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ThreeWayMatchingService.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
 */
class ThreeWayMatchingServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ThreeWayMatchingService
     */
    private ThreeWayMatchingService $service;

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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new ThreeWayMatchingService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Helper: create an anonymous ObjectService mock with configurable return data.
     *
     * @param array<string,mixed> $objectMap Map of filter key => return data for getObjects
     * @param array<string,mixed> $updates   Collects updateObject calls by reference
     *
     * @return object
     */
    private function createObjectService(array $objectMap = [], array &$updates = []): object
    {
        return new class ($objectMap, $updates) {

            /**
             * @param array<string,mixed> $objectMap Map of filter key => return data
             * @param array<string,mixed> $updates   Collects update calls by reference
             */
            public function __construct(
                private array $objectMap,
                private array &$updates,
            ) {
            }//end __construct()


            /**
             * @param string              $objectType The object type
             * @param array<string,mixed> $filters    The filters to apply
             *
             * @return array<int,array<string,mixed>>
             */
            public function getObjects(string $objectType, array $filters = []): array
            {
                foreach ($filters as $key => $value) {
                    $filterKey = $objectType . '::' . $key . '=' . $value;
                    if (isset($this->objectMap[$filterKey]) === true) {
                        return $this->objectMap[$filterKey];
                    }
                }

                return ($this->objectMap[$objectType] ?? []);
            }//end getObjects()


            /**
             * @param string              $objectType The object type
             * @param string              $id         The object ID
             * @param array<string,mixed> $data       The data to update
             *
             * @return void
             */
            public function updateObject(string $objectType, string $id, array $data): void
            {
                $this->updates[] = [
                    'objectType' => $objectType,
                    'id'         => $id,
                    'data'       => $data,
                ];
            }//end updateObject()
        };

    }//end createObjectService()

    /**
     * Test that match sets status to 'matched' when all quantities are equal.
     *
     * When ordered=10, received=10, invoiced=10 the match status for the
     * purchase order line must be 'matched' and no discrepancies returned.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
     */
    public function testMatchSetsMatchedWhenAllEqual(): void
    {
        $updates       = [];
        $objectService = $this->createObjectService(
            objectMap: [
                'purchaseOrderLine::purchaseOrderId=po-1' => [
                    [
                        'id'       => 'pol-1',
                        'quantity' => 10,
                    ],
                ],
                'goodsReceiptLine::purchaseOrderLineId=pol-1' => [
                    ['receivedQuantity' => 10],
                ],
                'invoiceLine::purchaseOrderLineId=pol-1' => [
                    ['quantity' => 10],
                ],
            ],
            updates: $updates,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $discrepancies = $this->service->match(purchaseOrderId: 'po-1');

        self::assertCount(0, $discrepancies);
        self::assertCount(1, $updates);
        self::assertSame('matched', $updates[0]['data']['matchStatus']);

    }//end testMatchSetsMatchedWhenAllEqual()

    /**
     * Test that match sets status to 'discrepancy' on quantity difference.
     *
     * When ordered=10 but received=8, the match status must be 'discrepancy'
     * and the discrepancy report must contain the affected line.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
     */
    public function testMatchSetsDiscrepancyOnQuantityDifference(): void
    {
        $updates       = [];
        $objectService = $this->createObjectService(
            objectMap: [
                'purchaseOrderLine::purchaseOrderId=po-2' => [
                    [
                        'id'       => 'pol-2',
                        'quantity' => 10,
                    ],
                ],
                'goodsReceiptLine::purchaseOrderLineId=pol-2' => [
                    ['receivedQuantity' => 8],
                ],
                'invoiceLine::purchaseOrderLineId=pol-2' => [
                    ['quantity' => 10],
                ],
            ],
            updates: $updates,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $discrepancies = $this->service->match(purchaseOrderId: 'po-2');

        self::assertCount(1, $discrepancies);
        self::assertSame('discrepancy', $discrepancies[0]['matchStatus']);
        self::assertSame(10.0, $discrepancies[0]['orderedQuantity']);
        self::assertSame(8.0, $discrepancies[0]['receivedQuantity']);
        self::assertSame('discrepancy', $updates[0]['data']['matchStatus']);

    }//end testMatchSetsDiscrepancyOnQuantityDifference()

    /**
     * Test that match is idempotent — calling twice yields the same result.
     *
     * Running match() twice with the same underlying data must produce
     * identical discrepancy reports.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
     */
    public function testMatchIsIdempotent(): void
    {
        $updates1       = [];
        $objectService1 = $this->createObjectService(
            objectMap: [
                'purchaseOrderLine::purchaseOrderId=po-3' => [
                    [
                        'id'       => 'pol-3',
                        'quantity' => 10,
                    ],
                ],
                'goodsReceiptLine::purchaseOrderLineId=pol-3' => [
                    ['receivedQuantity' => 10],
                ],
                'invoiceLine::purchaseOrderLineId=pol-3' => [
                    ['quantity' => 10],
                ],
            ],
            updates: $updates1,
        );

        $updates2       = [];
        $objectService2 = $this->createObjectService(
            objectMap: [
                'purchaseOrderLine::purchaseOrderId=po-3' => [
                    [
                        'id'       => 'pol-3',
                        'quantity' => 10,
                    ],
                ],
                'goodsReceiptLine::purchaseOrderLineId=pol-3' => [
                    ['receivedQuantity' => 10],
                ],
                'invoiceLine::purchaseOrderLineId=pol-3' => [
                    ['quantity' => 10],
                ],
            ],
            updates: $updates2,
        );

        $this->container->expects($this->exactly(2))
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturnOnConsecutiveCalls($objectService1, $objectService2);

        $result1 = $this->service->match(purchaseOrderId: 'po-3');
        $result2 = $this->service->match(purchaseOrderId: 'po-3');

        self::assertSame($result1, $result2);
        self::assertSame($updates1[0]['data']['matchStatus'], $updates2[0]['data']['matchStatus']);

    }//end testMatchIsIdempotent()

    /**
     * Test that match handles PO lines with no goods receipts gracefully.
     *
     * When a purchase order line has no associated goods receipt lines,
     * receivedQuantity should be 0 and the status should be 'discrepancy'
     * (assuming ordered quantity > 0).
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
     */
    public function testMatchHandlesNoReceiptsGracefully(): void
    {
        $updates       = [];
        $objectService = $this->createObjectService(
            objectMap: [
                'purchaseOrderLine::purchaseOrderId=po-4' => [
                    [
                        'id'       => 'pol-4',
                        'quantity' => 5,
                    ],
                ],
                'goodsReceiptLine::purchaseOrderLineId=pol-4' => [],
                'invoiceLine::purchaseOrderLineId=pol-4'      => [],
            ],
            updates: $updates,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $discrepancies = $this->service->match(purchaseOrderId: 'po-4');

        self::assertCount(1, $discrepancies);
        self::assertSame('discrepancy', $discrepancies[0]['matchStatus']);
        self::assertSame(0.0, $discrepancies[0]['receivedQuantity']);
        self::assertSame(0.0, $discrepancies[0]['invoicedQuantity']);
        self::assertSame(5.0, $discrepancies[0]['orderedQuantity']);

    }//end testMatchHandlesNoReceiptsGracefully()
}//end class
