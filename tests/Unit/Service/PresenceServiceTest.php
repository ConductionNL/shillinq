<?php

/**
 * Unit tests for PresenceService.
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PresenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stub matching the OpenRegister ObjectService API so mocks accept named arguments.
 */
abstract class ObjectServiceStub
{
    /**
     * Find all objects matching the given filters.
     *
     * @param string              $schema  The schema name
     * @param array<string,mixed> $filters Key/value filters
     *
     * @return array<string,mixed>
     */
    abstract public function findAll(string $schema, array $filters=[]): array;

    /**
     * Create a new object.
     *
     * @param string              $schema The schema name
     * @param array<string,mixed> $data   The object data
     *
     * @return array<string,mixed>
     */
    abstract public function create(string $schema, array $data): array;

    /**
     * Update an existing object.
     *
     * @param string              $id   The object ID
     * @param array<string,mixed> $data The updated data
     *
     * @return array<string,mixed>
     */
    abstract public function update(string $id, array $data): array;
}//end class

/**
 * Tests for PresenceService.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.3
 */
class PresenceServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var PresenceService
     */
    private PresenceService $service;

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
     * Mock ObjectService.
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $this->logger    = $this->createMock(originalClassName: LoggerInterface::class);

        $this->objectService = $this->getMockBuilder(className: ObjectServiceStub::class)
            ->getMock();

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new PresenceService(
            container: $this->container,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that ping creates a new PresenceRecord when none exists.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.3
     */
    public function testPingCreatesNewRecord(): void
    {
        $this->objectService->method('findAll')
            ->willReturn(['results' => []]);

        $created = [
            'id'         => 'pr-001',
            'userId'     => 'alice',
            'targetType' => 'Invoice',
            'targetId'   => '001',
            'lastSeenAt' => '2026-04-08T12:00:00+00:00',
            'isEditing'  => false,
        ];

        $this->objectService->expects($this->once())
            ->method('create')
            ->willReturn($created);

        $result = $this->service->ping(
            userId: 'alice',
            targetType: 'Invoice',
            targetId: '001',
        );

        self::assertSame(expected: 'pr-001', actual: $result['id']);
        self::assertSame(expected: 'alice', actual: $result['userId']);

    }//end testPingCreatesNewRecord()

    /**
     * Test that ping updates existing record (upsert, not duplicate).
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.3
     */
    public function testPingUpdatesExistingRecord(): void
    {
        $this->objectService->method('findAll')
            ->willReturn(
                    [
                        'results' => [
                            [
                                'id'         => 'pr-001',
                                'userId'     => 'alice',
                                'targetType' => 'Invoice',
                                'targetId'   => '001',
                                'lastSeenAt' => '2026-04-08T11:00:00Z',
                                'isEditing'  => false,
                            ],
                        ],
                    ]
                    );

        $this->objectService->expects($this->once())
            ->method('update')
            ->with(
                id: 'pr-001',
                data: $this->callback(
                    callback: static function ($data) {
                        return isset($data['lastSeenAt']) && $data['isEditing'] === true;
                    }
                ),
            )
            ->willReturn(
                    [
                        'id'         => 'pr-001',
                        'lastSeenAt' => '2026-04-08T12:00:00+00:00',
                        'isEditing'  => true,
                    ]
                    );

        $result = $this->service->ping(
            userId: 'alice',
            targetType: 'Invoice',
            targetId: '001',
            isEditing: true,
        );

        self::assertSame(expected: 'pr-001', actual: $result['id']);
        self::assertTrue(condition: $result['isEditing']);

    }//end testPingUpdatesExistingRecord()

    /**
     * Test that getActiveViewers excludes records older than 120 seconds.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.3
     */
    public function testGetActiveViewersExcludesStaleRecords(): void
    {
        $fresh = (new \DateTime())->format('c');
        $stale = (new \DateTime('-200 seconds'))->format('c');

        $this->objectService->method('findAll')
            ->willReturn(
                    [
                        'results' => [
                            [
                                'userId'     => 'alice',
                                'lastSeenAt' => $fresh,
                            ],
                            [
                                'userId'     => 'bob',
                                'lastSeenAt' => $stale,
                            ],
                        ],
                    ]
                    );

        $result = $this->service->getActiveViewers(
            targetType: 'Invoice',
            targetId: '001',
        );

        self::assertCount(expectedCount: 1, haystack: $result);
        self::assertSame(expected: 'alice', actual: $result[0]['userId']);

    }//end testGetActiveViewersExcludesStaleRecords()
}//end class
