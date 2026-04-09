<?php

/**
 * Shillinq PresenceService Unit Tests
 *
 * @category Tests
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PresenceService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for PresenceService.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.3
 */
class PresenceServiceTest extends TestCase
{

    /**
     * The PresenceService under test.
     *
     * @var PresenceService
     */
    private PresenceService $service;

    /**
     * Mock object service for testing.
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

        $container = $this->createMock(ContainerInterface::class);
        $logger    = $this->createMock(LoggerInterface::class);

        $this->objectService = new class {

            /**
             * Records stored in the mock.
             *
             * @var array<int,array<string,mixed>>
             */
            public array $records = [];

            /**
             * Track whether saveObject was called.
             *
             * @var int
             */
            public int $saveCount = 0;

            /**
             * Find objects by filters.
             *
             * @param array<string,string> $filters Filters
             *
             * @return array<int,array<string,mixed>>
             */
            public function findObjects(array $filters): array
            {
                return array_values(
                    array_filter(
                        $this->records,
                        function (array $record) use ($filters): bool {
                            foreach ($filters as $key => $value) {
                                if (($record[$key] ?? null) !== $value) {
                                    return false;
                                }
                            }
                            return true;
                        }
                    )
                );
            }//end findObjects()

            /**
             * Save an object.
             *
             * @param array<string,mixed> $object The object
             *
             * @return array<string,mixed>
             */
            public function saveObject(array $object): array
            {
                $this->saveCount++;
                // Find and update existing or add new.
                foreach ($this->records as $idx => $record) {
                    $match = ($record['userId'] ?? '') === ($object['userId'] ?? '')
                        && ($record['targetType'] ?? '') === ($object['targetType'] ?? '')
                        && ($record['targetId'] ?? '') === ($object['targetId'] ?? '');
                    if ($match === true) {
                        $this->records[$idx] = $object;
                        return $object;
                    }
                }
                $this->records[] = $object;
                return $object;
            }//end saveObject()
        };

        $container->method('get')->willReturn($this->objectService);

        $this->service = new PresenceService(
            container: $container,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that ping creates a new presence record.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.3
     */
    public function testPingCreatesNewRecord(): void
    {
        $result = $this->service->ping(
            userId: 'alice',
            targetType: 'Invoice',
            targetId: '001',
            isEditing: false,
        );

        $this->assertSame(expected: 'alice', actual: $result['userId']);
        $this->assertSame(expected: 'Invoice', actual: $result['targetType']);
        $this->assertSame(expected: '001', actual: $result['targetId']);
        $this->assertFalse($result['isEditing']);
        $this->assertSame(expected: 1, actual: $this->objectService->saveCount);
    }//end testPingCreatesNewRecord()

    /**
     * Test that a second ping updates the existing record, not duplicating.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.3
     */
    public function testPingUpdatesExistingRecord(): void
    {
        $this->service->ping(
            userId: 'alice',
            targetType: 'Invoice',
            targetId: '001',
            isEditing: false,
        );

        $this->service->ping(
            userId: 'alice',
            targetType: 'Invoice',
            targetId: '001',
            isEditing: true,
        );

        $this->assertCount(expectedCount: 1, haystack: $this->objectService->records);
        $this->assertTrue($this->objectService->records[0]['isEditing']);
    }//end testPingUpdatesExistingRecord()

    /**
     * Test that getActiveViewers excludes stale records.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.3
     */
    public function testGetActiveViewersExcludesStaleRecords(): void
    {
        $now  = new \DateTime();
        $old  = (new \DateTime())->modify('-200 seconds');

        $this->objectService->records = [
            [
                'userId'     => 'alice',
                'targetType' => 'Invoice',
                'targetId'   => '001',
                'lastSeenAt' => $now->format('c'),
                'isEditing'  => false,
            ],
            [
                'userId'     => 'bob',
                'targetType' => 'Invoice',
                'targetId'   => '001',
                'lastSeenAt' => $old->format('c'),
                'isEditing'  => false,
            ],
        ];

        $active = $this->service->getActiveViewers(
            targetType: 'Invoice',
            targetId: '001',
        );

        $this->assertCount(expectedCount: 1, haystack: $active);
        $this->assertSame(expected: 'alice', actual: $active[0]['userId']);
    }//end testGetActiveViewersExcludesStaleRecords()
}//end class
