<?php

/**
 * Unit tests for BulkActionService.
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
 * @spec openspec/changes/general/tasks.md#task-13.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BulkActionService;
use PHPUnit\Framework\MockObject\MockObject;
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

    /**
     * The service under test.
     *
     * @var BulkActionService
     */
    private BulkActionService $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock object service.
     *
     * @var object
     */
    private object $objectService;

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

        $this->objectService = new class {

            /**
             * IDs that should fail when updated.
             *
             * @var array
             */
            public array $failIds = [];

            /**
             * Updated objects for test assertions.
             *
             * @var array
             */
            public array $updated = [];

            /**
             * Deleted objects for test assertions.
             *
             * @var array
             */
            public array $deleted = [];

            /**
             * Update an object, throwing on failIds.
             *
             * @param string $register The register
             * @param string $schema   The schema
             * @param string $id       The object ID
             * @param array  $object   The update data
             *
             * @return array The updated object
             *
             * @throws \RuntimeException If the ID is in failIds
             */
            public function updateObject(string $register, string $schema, string $id, array $object): array
            {
                if (in_array($id, $this->failIds, true)) {
                    throw new \RuntimeException("Object {$id} has a dependency");
                }

                $this->updated[] = $id;

                return $object;
            }

            /**
             * Delete an object, throwing on failIds.
             *
             * @param string $register The register
             * @param string $schema   The schema
             * @param string $id       The object ID
             *
             * @return void
             *
             * @throws \RuntimeException If the ID is in failIds
             */
            public function deleteObject(string $register, string $schema, string $id): void
            {
                if (in_array($id, $this->failIds, true)) {
                    throw new \RuntimeException("Cannot delete {$id}");
                }

                $this->deleted[] = $id;
            }
        };

        $this->container->method('get')
            ->willReturn($this->objectService);

        $this->service = new BulkActionService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that bulkApprove succeeds for all IDs when none fail.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkApproveSucceedsForAllIds(): void
    {
        $ids = ['a', 'b', 'c', 'd', 'e'];

        $result = $this->service->bulkApprove('Invoice', $ids);

        self::assertSame(5, $result['succeeded']);
        self::assertSame(0, $result['failed']);
        self::assertEmpty($result['errors']);
    }//end testBulkApproveSucceedsForAllIds()

    /**
     * Test that bulkApprove with partial failures reports correct counts.
     *
     * GIVEN 5 IDs where 1 fails
     * THEN the result MUST show succeeded: 4, failed: 1, and the failed ID in errors.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkApproveWithPartialFailureReportsCorrectCounts(): void
    {
        $ids = ['a', 'b', 'c', 'd', 'e'];
        $this->objectService->failIds = ['c'];

        $result = $this->service->bulkApprove('Invoice', $ids);

        self::assertSame(4, $result['succeeded']);
        self::assertSame(1, $result['failed']);
        self::assertCount(1, $result['errors']);
        self::assertSame('c', $result['errors'][0]['id']);
    }//end testBulkApproveWithPartialFailureReportsCorrectCounts()

    /**
     * Test that bulkDelete does not abort on first failure.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkDeleteContinuesAfterFailure(): void
    {
        $ids = ['a', 'b', 'c', 'd', 'e'];
        $this->objectService->failIds = ['b'];

        $result = $this->service->bulkDelete('Invoice', $ids);

        self::assertSame(4, $result['succeeded']);
        self::assertSame(1, $result['failed']);
        self::assertSame('b', $result['errors'][0]['id']);
        self::assertContains('a', $this->objectService->deleted);
        self::assertContains('c', $this->objectService->deleted);
        self::assertContains('d', $this->objectService->deleted);
        self::assertContains('e', $this->objectService->deleted);
    }//end testBulkDeleteContinuesAfterFailure()

    /**
     * Test that bulkAssign updates all objects with the assignee ID.
     *
     * @return void
     *
     * @spec openspec/changes/general/tasks.md#task-13.3
     */
    public function testBulkAssignUpdatesAllObjects(): void
    {
        $ids = ['x', 'y', 'z'];

        $result = $this->service->bulkAssign('ExpenseClaim', $ids, 'user-123');

        self::assertSame(3, $result['succeeded']);
        self::assertSame(0, $result['failed']);
        self::assertCount(3, $this->objectService->updated);
    }//end testBulkAssignUpdatesAllObjects()
}//end class
