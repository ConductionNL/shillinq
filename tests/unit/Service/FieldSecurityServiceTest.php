<?php

/**
 * Unit tests for FieldSecurityService.
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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.2
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\FieldSecurityService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FieldSecurityService.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.2
 */
class FieldSecurityServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var FieldSecurityService
     */
    private FieldSecurityService $service;

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

        $this->service = new FieldSecurityService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that restricted fields are stripped from the response.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.2
     */
    public function testRestrictedFieldIsStripped(): void
    {
        $objectService = $this->createObjectServiceMock(
            permissions: [
                [
                    'roleId'     => 'role-1',
                    'schemaName' => 'supplier',
                    'fieldName'  => 'bankAccountNumber',
                    'canRead'    => false,
                    'canWrite'   => false,
                ],
            ],
            accessRights: [
                [
                    'userId'   => 'viewer-user',
                    'roleId'   => 'role-1',
                    'isActive' => true,
                ],
            ],
        );

        $this->container->method('get')->willReturn($objectService);

        $object = [
            'name'              => 'Supplier A',
            'bankAccountNumber' => 'NL91ABNA0417164300',
            'city'              => 'Amsterdam',
        ];

        $result = $this->service->filterResponse(
            object: $object,
            schemaName: 'supplier',
            userId: 'viewer-user',
        );

        self::assertArrayNotHasKey('bankAccountNumber', $result);
        self::assertArrayHasKey('name', $result);
        self::assertArrayHasKey('city', $result);

    }//end testRestrictedFieldIsStripped()

    /**
     * Test that admin users retain all fields.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.2
     */
    public function testAdminRetainsAllFields(): void
    {
        $objectService = $this->createObjectServiceMock(
            permissions: [],
            accessRights: [
                [
                    'userId'   => 'admin-user',
                    'roleId'   => 'admin-role',
                    'isActive' => true,
                ],
            ],
        );

        $this->container->method('get')->willReturn($objectService);

        $object = [
            'name'              => 'Supplier A',
            'bankAccountNumber' => 'NL91ABNA0417164300',
        ];

        $result = $this->service->filterResponse(
            object: $object,
            schemaName: 'supplier',
            userId: 'admin-user',
        );

        self::assertSame($object, $result);

    }//end testAdminRetainsAllFields()

    /**
     * Test that the permission cache prevents duplicate queries.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.2
     */
    public function testPermissionCacheHit(): void
    {
        $objectService = $this->createObjectServiceMock(
            permissions: [],
            accessRights: [
                [
                    'userId'   => 'user-1',
                    'roleId'   => 'role-1',
                    'isActive' => true,
                ],
            ],
        );

        // The container get should only be called once (first call caches).
        $this->container->expects($this->once())
            ->method('get')
            ->willReturn($objectService);

        $object = ['name' => 'Test'];

        $this->service->filterResponse(
            object: $object,
            schemaName: 'test',
            userId: 'user-1',
        );

        // Second call should use cache.
        $this->service->filterResponse(
            object: $object,
            schemaName: 'test',
            userId: 'user-1',
        );

    }//end testPermissionCacheHit()

    /**
     * Test that write permission check returns false for restricted fields.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.2
     */
    public function testWritePermissionDenied(): void
    {
        $objectService = $this->createObjectServiceMock(
            permissions: [
                [
                    'roleId'     => 'role-1',
                    'schemaName' => 'invoice',
                    'fieldName'  => 'totalAmount',
                    'canRead'    => true,
                    'canWrite'   => false,
                ],
            ],
            accessRights: [
                [
                    'userId'   => 'user-1',
                    'roleId'   => 'role-1',
                    'isActive' => true,
                ],
            ],
        );

        $this->container->method('get')->willReturn($objectService);

        // Reset cache from previous test context.
        $this->service->resetCache();

        $result = $this->service->checkWritePermission(
            schemaName: 'invoice',
            fieldName: 'totalAmount',
            userId: 'user-1',
        );

        self::assertFalse($result);

    }//end testWritePermissionDenied()

    /**
     * Create a mock object service with the given permissions and access rights.
     *
     * @param array $permissions  The permission objects to return
     * @param array $accessRights The access right objects to return
     *
     * @return object
     */
    private function createObjectServiceMock(array $permissions, array $accessRights): object
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $mock->method('findObjects')
            ->willReturnCallback(
                function (string $register, string $schema, array $filters) use ($permissions, $accessRights) {
                    if ($schema === 'permission') {
                        return $permissions;
                    }

                    if ($schema === 'accessRight') {
                        return $accessRights;
                    }

                    return [];
                }
            );

        return $mock;
    }//end createObjectServiceMock()
}//end class
