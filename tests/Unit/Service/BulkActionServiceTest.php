<?php

/**
 * Unit tests for BulkActionService.
 *
 * @spec openspec/changes/general/tasks.md#task-13.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BulkActionService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BulkActionService.
 *
 * @spec openspec/changes/general/tasks.md#task-13.3
 */
class BulkActionServiceTest extends TestCase
{

    private ContainerInterface $container;

    private LoggerInterface $logger;

    private BulkActionService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);
        $this->service   = new BulkActionService($this->container, $this->logger);
    }//end setUp()

    /**
     * Test that bulkApprove succeeds for all IDs when no errors occur.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkApproveAllSucceed(): void
    {
        $mockObjectService = new class {

            /**
             * Update object mock.
             *
             * @return void
             */
            public function updateObject(string $id = '', array $data = []): void
            {
                // All updates succeed.
            }//end updateObject()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->bulkApprove('Invoice', ['a', 'b', 'c']);

        $this->assertEquals(3, $result['succeeded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }//end testBulkApproveAllSucceed()

    /**
     * Test that bulkApprove with partial failures returns correct counts.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkApproveWithPartialFailures(): void
    {
        $mockObjectService = new class {

            /**
             * Update object mock — fails for ID 'c'.
             *
             * @return void
             *
             * @throws \RuntimeException When ID is 'c'.
             */
            public function updateObject(string $id = '', array $data = []): void
            {
                if ($id === 'c') {
                    throw new \RuntimeException('Object c has dependency');
                }
            }//end updateObject()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->bulkApprove('Invoice', ['a', 'b', 'c', 'd', 'e']);

        $this->assertEquals(4, $result['succeeded']);
        $this->assertEquals(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertEquals('c', $result['errors'][0]['id']);
    }//end testBulkApproveWithPartialFailures()

    /**
     * Test that bulkDelete processes all IDs without aborting on first failure.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkDeleteContinuesAfterFailure(): void
    {
        $mockObjectService = new class {

            /**
             * Delete object mock — fails for first ID.
             *
             * @return void
             *
             * @throws \RuntimeException When ID is 'x'.
             */
            public function deleteObject(string $id = ''): void
            {
                if ($id === 'x') {
                    throw new \RuntimeException('Cannot delete x');
                }
            }//end deleteObject()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->bulkDelete('ExpenseClaim', ['x', 'y', 'z']);

        $this->assertEquals(2, $result['succeeded']);
        $this->assertEquals(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertEquals('x', $result['errors'][0]['id']);
    }//end testBulkDeleteContinuesAfterFailure()

    /**
     * Test that bulkAssign sets the correct assigneeId on each object.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkAssignUpdatesAssignee(): void
    {
        $updatedData = new \ArrayObject();
        $mockObjectService = new class ($updatedData) {

            private \ArrayObject $data;

            /**
             * Constructor.
             *
             * @param \ArrayObject $data Shared data tracker.
             *
             * @return void
             */
            public function __construct(\ArrayObject $data)
            {
                $this->data = $data;
            }//end __construct()

            /**
             * Update object mock.
             *
             * @return void
             */
            public function updateObject(string $id = '', array $data = []): void
            {
                $this->data[$id] = $data;
            }//end updateObject()
        };

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($mockObjectService);

        $result = $this->service->bulkAssign('Invoice', ['a', 'b'], 'user-42');

        $this->assertEquals(2, $result['succeeded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(['assigneeId' => 'user-42'], ($updatedData['a'] ?? []));
        $this->assertEquals(['assigneeId' => 'user-42'], ($updatedData['b'] ?? []));
    }//end testBulkAssignUpdatesAssignee()

    /**
     * Test that bulkApprove with an empty IDs array returns zero counts.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkApproveEmptyIds(): void
    {
        $result = $this->service->bulkApprove('Invoice', []);

        $this->assertEquals(0, $result['succeeded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);
    }//end testBulkApproveEmptyIds()
}//end class
