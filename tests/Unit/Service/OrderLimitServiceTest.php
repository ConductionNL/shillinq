<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Unit tests for OrderLimitService.
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
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\OrderLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Ensure OCP stub classes are available for mocking in unit-test mode.
 *
 * The nextcloud/ocp stubs are not autoloaded via Composer, so we register
 * the PSR-4 prefix for the OCP namespace directly.
 */
$ocpStubDir = dirname(__DIR__, 3) . '/vendor/nextcloud/ocp';
if (is_dir($ocpStubDir) === true && class_exists(\OCP\AppFramework\App::class) === false) {
    $loader = new \Composer\Autoload\ClassLoader();
    $loader->addPsr4('OCP\\', $ocpStubDir . '/OCP/');
    $loader->register(prepend: true);
}

/**
 * Tests for OrderLimitService.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
 */
class OrderLimitServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var OrderLimitService
     */
    private OrderLimitService $service;

    /**
     * Mock IAppConfig.
     *
     * @var \OCP\IAppConfig&MockObject
     */
    private \OCP\IAppConfig&MockObject $appConfig;

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

        $this->appConfig = $this->createMock(\OCP\IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new OrderLimitService(
            appConfig: $this->appConfig,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that check returns false when no limit is configured.
     *
     * When neither a per-user limit nor a default limit exists in AppConfig,
     * requiresApproval must be false and limit must be null.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
     */
    public function testCheckReturnsFalseWhenNoLimit(): void
    {
        $this->appConfig->expects($this->exactly(2))
            ->method('getValueString')
            ->willReturn('');

        $result = $this->service->check(userId: 'user-1', basketTotal: 500.00);

        self::assertFalse($result['requiresApproval']);
        self::assertNull($result['limit']);

    }//end testCheckReturnsFalseWhenNoLimit()

    /**
     * Test that check returns true when the basket total exceeds the user limit.
     *
     * When the user has a per-user limit of 1000 and the basket total is 1500,
     * requiresApproval must be true.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
     */
    public function testCheckReturnsTrueWhenOverLimit(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with('shillinq', 'ordering.limitEur.user-1', '')
            ->willReturn('1000');

        $result = $this->service->check(userId: 'user-1', basketTotal: 1500.00);

        self::assertTrue($result['requiresApproval']);
        self::assertSame(1000.0, $result['limit']);

    }//end testCheckReturnsTrueWhenOverLimit()

    /**
     * Test that check returns false when the basket total is under the user limit.
     *
     * When the user has a per-user limit of 1000 and the basket total is 500,
     * requiresApproval must be false.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
     */
    public function testCheckReturnsFalseWhenUnderLimit(): void
    {
        $this->appConfig->expects($this->once())
            ->method('getValueString')
            ->with('shillinq', 'ordering.limitEur.user-1', '')
            ->willReturn('1000');

        $result = $this->service->check(userId: 'user-1', basketTotal: 500.00);

        self::assertFalse($result['requiresApproval']);
        self::assertSame(1000.0, $result['limit']);

    }//end testCheckReturnsFalseWhenUnderLimit()

    /**
     * Test that check falls back to the default limit when no user-specific limit exists.
     *
     * When the per-user key returns empty but the global default key returns a value,
     * the default limit is used for the comparison.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.2
     */
    public function testCheckFallsBackToDefaultLimit(): void
    {
        $this->appConfig->expects($this->exactly(2))
            ->method('getValueString')
            ->willReturnCallback(function (string $appId, string $key, string $default): string {
                if ($key === 'ordering.limitEur.user-2') {
                    return '';
                }

                if ($key === 'ordering.limitEur.default') {
                    return '2000';
                }

                return $default;
            });

        $result = $this->service->check(userId: 'user-2', basketTotal: 2500.00);

        self::assertTrue($result['requiresApproval']);
        self::assertSame(2000.0, $result['limit']);

    }//end testCheckFallsBackToDefaultLimit()
}//end class
