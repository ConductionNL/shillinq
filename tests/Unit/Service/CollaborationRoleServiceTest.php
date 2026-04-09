<?php

/**
 * Shillinq CollaborationRoleService Unit Tests
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CollaborationRoleService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CollaborationRoleService.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.2
 */
class CollaborationRoleServiceTest extends TestCase
{

    /**
     * The CollaborationRoleService under test.
     *
     * @var CollaborationRoleService
     */
    private CollaborationRoleService $service;

    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Mock group manager.
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

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

        $this->container    = $this->createMock(ContainerInterface::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $this->objectService = new class {

            /**
             * Roles to return from findObjects.
             *
             * @var array<int,array<string,mixed>>
             */
            public array $roles = [];

            /**
             * Find objects by filter.
             *
             * @param array<string,string> $filters Filters
             *
             * @return array<int,array<string,mixed>>
             */
            public function findObjects(array $filters): array
            {
                return $this->roles;
            }//end findObjects()
        };

        $this->container->method('get')
            ->willReturn($this->objectService);

        $this->service = new CollaborationRoleService(
            container: $this->container,
            groupManager: $this->groupManager,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test contributor can access contributor-level resources.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testContributorMeetsContributorMinimum(): void
    {
        $this->objectService->roles = [
            [
                'principalId'   => 'bob',
                'principalType' => 'user',
                'role'          => 'contributor',
                'expiresAt'     => null,
            ],
        ];

        $this->groupManager->method('isInGroup')->willReturn(false);

        $result = $this->service->checkRole(
            userId: 'bob',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'contributor',
        );

        $this->assertTrue($result);
    }//end testContributorMeetsContributorMinimum()

    /**
     * Test contributor cannot access reviewer-level resources.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testContributorFailsReviewerMinimum(): void
    {
        $this->objectService->roles = [
            [
                'principalId'   => 'bob',
                'principalType' => 'user',
                'role'          => 'contributor',
                'expiresAt'     => null,
            ],
        ];

        $this->groupManager->method('isInGroup')->willReturn(false);

        $result = $this->service->checkRole(
            userId: 'bob',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'reviewer',
        );

        $this->assertFalse($result);
    }//end testContributorFailsReviewerMinimum()

    /**
     * Test expired role returns false regardless of level.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testExpiredRoleReturnsFalse(): void
    {
        $this->objectService->roles = [
            [
                'principalId'   => 'carol',
                'principalType' => 'user',
                'role'          => 'approver',
                'expiresAt'     => '2020-01-01T00:00:00Z',
            ],
        ];

        $this->groupManager->method('isInGroup')->willReturn(false);

        $result = $this->service->checkRole(
            userId: 'carol',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'viewer',
        );

        $this->assertFalse($result);
    }//end testExpiredRoleReturnsFalse()

    /**
     * Test that no role at all returns false.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testNoRoleReturnsFalse(): void
    {
        $this->objectService->roles = [];

        $result = $this->service->checkRole(
            userId: 'dave',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'viewer',
        );

        $this->assertFalse($result);
    }//end testNoRoleReturnsFalse()
}//end class
