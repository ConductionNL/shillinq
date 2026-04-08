<?php

/**
 * Unit tests for CollaborationRoleService.
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CollaborationRoleService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CollaborationRoleService.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.2
 */
class CollaborationRoleServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var CollaborationRoleService
     */
    private CollaborationRoleService $service;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock IGroupManager.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager&MockObject $groupManager;

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

        $this->container    = $this->createMock(ContainerInterface::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        // Create a generic mock for OpenRegister ObjectService.
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'find'])
            ->getMock();

        $this->container->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $this->service = new CollaborationRoleService(
            container: $this->container,
            groupManager: $this->groupManager,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test contributor role meets contributor minimum.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testContributorRoleMeetsContributorMinimum(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'principalType' => 'user',
                        'principalId'   => 'bob',
                        'role'          => 'contributor',
                    ],
                ],
            ]);

        $result = $this->service->checkRole(
            userId: 'bob',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'contributor',
        );

        self::assertTrue($result);

    }//end testContributorRoleMeetsContributorMinimum()

    /**
     * Test contributor role does NOT meet reviewer minimum.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testContributorRoleDoesNotMeetReviewerMinimum(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'principalType' => 'user',
                        'principalId'   => 'bob',
                        'role'          => 'contributor',
                    ],
                ],
            ]);

        // Fallback also returns false since reviewer > contributor.
        $this->objectService->method('find')->willReturn(null);

        $result = $this->service->checkRole(
            userId: 'bob',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'reviewer',
        );

        self::assertFalse($result);

    }//end testContributorRoleDoesNotMeetReviewerMinimum()

    /**
     * Test expired role returns false regardless of role level.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testExpiredRoleReturnsFalse(): void
    {
        $this->objectService->method('findAll')
            ->willReturn([
                'results' => [
                    [
                        'principalType' => 'user',
                        'principalId'   => 'carol',
                        'role'          => 'approver',
                        'expiresAt'     => '2020-01-01T00:00:00Z',
                    ],
                ],
            ]);

        $this->objectService->method('find')->willReturn(null);

        $result = $this->service->checkRole(
            userId: 'carol',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'viewer',
        );

        self::assertFalse($result);

    }//end testExpiredRoleReturnsFalse()

    /**
     * Test that no role falls back to AccessControl check.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testNoRoleFallsBackToAccessControl(): void
    {
        $this->objectService->method('findAll')
            ->willReturn(['results' => []]);

        // Fallback: object exists so user gets contributor level.
        $this->objectService->method('find')
            ->willReturn(['id' => 'inv-001']);

        $resultContributor = $this->service->checkRole(
            userId: 'dave',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'contributor',
        );

        self::assertTrue($resultContributor);

    }//end testNoRoleFallsBackToAccessControl()

    /**
     * Test that no role and no object access returns false for reviewer.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.2
     */
    public function testNoRoleNoAccessReturnsFalseForReviewer(): void
    {
        $this->objectService->method('findAll')
            ->willReturn(['results' => []]);

        $this->objectService->method('find')->willReturn(null);

        $result = $this->service->checkRole(
            userId: 'eve',
            targetType: 'Invoice',
            targetId: 'inv-001',
            minimumRole: 'reviewer',
        );

        self::assertFalse($result);

    }//end testNoRoleNoAccessReturnsFalseForReviewer()
}//end class
