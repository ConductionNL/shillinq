<?php

/**
 * Unit tests for PurchasingLimitService.
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

use OCA\Shillinq\Service\PurchasingLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PurchasingLimitService.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
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
     * Test that an amount exceeding the limit returns false (blocked).
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testAmountExceedingLimitReturnsBlocked(): void
    {
        $objectService = $this->createObjectServiceMock(
            [
                [
                    'id'                        => 'role-buyer',
                    'purchasingLimitAmount'      => 5000,
                    'purchasingLimitCategory'    => 'IT Equipment',
                ],
            ]
        );

        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->checkLimit('user-1', 8000.0, 'IT Equipment');

        self::assertFalse($result);

    }//end testAmountExceedingLimitReturnsBlocked()


    /**
     * Test that a different category does not trigger the limit.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testDifferentCategoryAllowsPurchase(): void
    {
        $objectService = $this->createObjectServiceMock(
            [
                [
                    'id'                        => 'role-buyer',
                    'purchasingLimitAmount'      => 5000,
                    'purchasingLimitCategory'    => 'IT Equipment',
                ],
            ]
        );

        $this->container->method('get')->willReturn($objectService);

        $result = $this->service->checkLimit('user-1', 3000.0, 'Office Supplies');

        self::assertTrue($result);

    }//end testDifferentCategoryAllowsPurchase()


    /**
     * Test that an active delegation with a higher limit applies.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16
     */
    public function testHigherDelegatedLimitApplies(): void
    {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $objectService->method('findObjects')->willReturnCallback(
            function (array $filters, string $register, string $schema) {
                if ($schema === 'accessRight') {
                    return [
                        [
                            'roleId'   => 'role-base',
                            'isActive' => true,
                        ],
                        [
                            'roleId'   => 'role-elevated',
                            'isActive' => true,
                        ],
                    ];
                }

                if ($schema === 'role') {
                    if (isset($filters['id']) === true && $filters['id'] === 'role-base') {
                        return [
                            [
                                'id'                        => 'role-base',
                                'purchasingLimitAmount'      => 5000,
                                'purchasingLimitCategory'    => 'IT Equipment',
                            ],
                        ];
                    }

                    if (isset($filters['id']) === true && $filters['id'] === 'role-elevated') {
                        return [
                            [
                                'id'                        => 'role-elevated',
                                'purchasingLimitAmount'      => 10000,
                                'purchasingLimitCategory'    => 'IT Equipment',
                            ],
                        ];
                    }
                }//end if

                return [];
            }
        );

        $this->container->method('get')->willReturn($objectService);

        // 8000 exceeds 5000 base limit but is within 10000 elevated limit.
        $result = $this->service->checkLimit('user-1', 8000.0, 'IT Equipment');

        self::assertTrue($result);

    }//end testHigherDelegatedLimitApplies()


    /**
     * Create a mock ObjectService with a single role.
     *
     * @param array $roles The role objects to return
     *
     * @return object
     */
    private function createObjectServiceMock(array $roles): object
    {
        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects'])
            ->getMock();

        $objectService->method('findObjects')->willReturnCallback(
            function (array $filters, string $register, string $schema) use ($roles) {
                if ($schema === 'accessRight') {
                    return array_map(
                        static fn(array $role): array => [
                            'roleId'   => $role['id'],
                            'isActive' => true,
                        ],
                        $roles
                    );
                }

                if ($schema === 'role') {
                    return $roles;
                }

                return [];
            }
        );

        return $objectService;

    }//end createObjectServiceMock()


}//end class
