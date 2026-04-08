<?php

/**
 * Unit tests for PurchasingLimitService.
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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.3
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PurchasingLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PurchasingLimitService.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.3
 */
class PurchasingLimitServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var PurchasingLimitService
     */
    private PurchasingLimitService $service;

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

        $this->service = new PurchasingLimitService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that amount exceeding limit returns false.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.3
     */
    public function testAmountExceedingLimitReturnsFalse(): void
    {
        $objectService = $this->createObjectServiceMock(
            roles: [
                [
                    'id'                      => 'role-1',
                    'isActive'                => true,
                    'purchasingLimitAmount'    => 5000,
                    'purchasingLimitCategory'  => 'IT Equipment',
                ],
            ],
        );

        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->checkLimit(
            userId: 'user-1',
            amount: 8000.0,
            category: 'IT Equipment',
        );

        self::assertFalse($result);

    }//end testAmountExceedingLimitReturnsFalse()

    /**
     * Test that amount within limit in a different category returns true.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.3
     */
    public function testDifferentCategoryAllowsPurchase(): void
    {
        $objectService = $this->createObjectServiceMock(
            roles: [
                [
                    'id'                      => 'role-1',
                    'isActive'                => true,
                    'purchasingLimitAmount'    => 5000,
                    'purchasingLimitCategory'  => 'IT Equipment',
                ],
            ],
        );

        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->checkLimit(
            userId: 'user-1',
            amount: 3000.0,
            category: 'Office Supplies',
        );

        // No limit applies for this category, so should be allowed.
        self::assertTrue($result);

    }//end testDifferentCategoryAllowsPurchase()

    /**
     * Test that a delegation with a higher limit takes precedence.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.3
     */
    public function testHigherDelegationLimitApplies(): void
    {
        $objectService = $this->createObjectServiceMock(
            roles: [
                [
                    'id'                      => 'role-1',
                    'isActive'                => true,
                    'purchasingLimitAmount'    => 5000,
                    'purchasingLimitCategory'  => 'IT Equipment',
                ],
                [
                    'id'                      => 'role-2',
                    'isActive'                => true,
                    'purchasingLimitAmount'    => 15000,
                    'purchasingLimitCategory'  => 'IT Equipment',
                ],
            ],
        );

        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->checkLimit(
            userId: 'user-1',
            amount: 8000.0,
            category: 'IT Equipment',
        );

        // The higher limit (15000) from the delegation applies.
        self::assertTrue($result);

    }//end testHigherDelegationLimitApplies()

    /**
     * Create a mock object service with the given roles.
     *
     * @param array $roles The role objects
     *
     * @return object
     */
    private function createObjectServiceMock(array $roles): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'getObject'])
            ->getMock();

        $accessRights = array_map(
            static fn($role) => [
                'userId'   => 'user-1',
                'roleId'   => $role['id'],
                'isActive' => true,
            ],
            $roles,
        );

        $mock->method('findObjects')
            ->willReturnCallback(
                function (string $register, string $schema, array $filters) use ($accessRights) {
                    if ($schema === 'accessRight') {
                        return $accessRights;
                    }

                    return [];
                }
            );

        $mock->method('getObject')
            ->willReturnCallback(
                function (string $register, string $schema, string $id) use ($roles) {
                    foreach ($roles as $role) {
                        if ($role['id'] === $id) {
                            return $role;
                        }
                    }

                    return null;
                }
            );

        return $mock;
    }//end createObjectServiceMock()
}//end class
