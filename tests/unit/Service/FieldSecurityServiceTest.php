<?php

/**
 * Unit tests for FieldSecurityService.
 *
 * @category  Test
 * @package   OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
 */

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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
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
     * Test that a field with canRead:false is removed from the response.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testFilterResponseStripsRestrictedFields(): void
    {
        $objectService = $this->createObjectServiceMock(
            [
                [
                    'roleId'     => 'role-viewer',
                    'schemaName' => 'supplier',
                    'fieldName'  => 'bankAccountNumber',
                    'canRead'    => false,
                    'canWrite'   => false,
                ],
            ]
        );

        $this->container->method('get')->willReturn($objectService);

        $object = [
            'name'              => 'Test Supplier',
            'bankAccountNumber' => 'NL91ABNA0417164300',
            'city'              => 'Amsterdam',
        ];

        $filtered = $this->service->filterResponse($object, 'supplier', 'user-1');

        self::assertArrayNotHasKey('bankAccountNumber', $filtered);
        self::assertSame('Test Supplier', $filtered['name']);
        self::assertSame('Amsterdam', $filtered['city']);

    }//end testFilterResponseStripsRestrictedFields()


    /**
     * Test that an Admin role (no restrictions) passes all fields through.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testAdminRoleKeepsAllFields(): void
    {
        $objectService = $this->createObjectServiceMock([]);

        $this->container->method('get')->willReturn($objectService);

        $object = [
            'name'              => 'Test Supplier',
            'bankAccountNumber' => 'NL91ABNA0417164300',
        ];

        $filtered = $this->service->filterResponse($object, 'supplier', 'admin-1');

        self::assertSame($object, $filtered);

    }//end testAdminRoleKeepsAllFields()


    /**
     * Test that the permission cache avoids duplicate OpenRegister queries.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testPermissionCachePreventsDuplicateQueries(): void
    {
        $objectService = $this->createObjectServiceMock([]);

        // The container's get() should only be called once (for the first call).
        $this->container->expects($this->once())->method('get')->willReturn($objectService);

        // Call filterResponse twice — second call should use cache.
        $this->service->filterResponse(['a' => 1], 'test', 'user-1');
        $this->service->filterResponse(['b' => 2], 'test', 'user-1');

    }//end testPermissionCachePreventsDuplicateQueries()


    /**
     * Create a mock ObjectService returning the given permissions.
     *
     * @param array $permissions The permission objects to return
     *
     * @return object
     */
    private function createObjectServiceMock(array $permissions): object
    {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $objectService->method('findObjects')->willReturnCallback(
            function (array $filters, string $register, string $schema) use ($permissions) {
                if ($schema === 'user') {
                    return [['id' => 'user-1']];
                }

                if ($schema === 'accessRight') {
                    return [['roleId' => 'role-viewer', 'isActive' => true]];
                }

                if ($schema === 'permission') {
                    return $permissions;
                }

                return [];
            }
        );

        return $objectService;

    }//end createObjectServiceMock()


}//end class
