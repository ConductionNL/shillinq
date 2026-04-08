<?php

/**
 * Unit tests for DelegationExpiryJob.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\BackgroundJob;

use OCA\Shillinq\BackgroundJob\DelegationExpiryJob;
use OCA\Shillinq\Service\AuditLogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DelegationExpiryJob.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.4
 */
class DelegationExpiryJobTest extends TestCase
{

    /**
     * The job under test.
     *
     * @var DelegationExpiryJob
     */
    private DelegationExpiryJob $job;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock AuditLogService.
     *
     * @var AuditLogService&MockObject
     */
    private AuditLogService&MockObject $auditLogService;

    /**
     * Mock INotificationManager.
     *
     * @var INotificationManager&MockObject
     */
    private INotificationManager&MockObject $notificationManager;

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

        $timeFactory               = $this->createMock(ITimeFactory::class);
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->auditLogService     = $this->createMock(AuditLogService::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->job = new DelegationExpiryJob(
            time: $timeFactory,
            container: $this->container,
            auditLogService: $this->auditLogService,
            notificationManager: $this->notificationManager,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Test that expired delegations are deactivated.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.4
     */
    public function testExpiredDelegationIsDeactivated(): void
    {
        $expiredRight = [
            'id'        => 'ar-1',
            'userId'    => 'user-1',
            'roleId'    => 'role-1',
            'grantedBy' => 'admin-1',
            'isActive'  => true,
            'endDate'   => '2020-01-01T00:00:00Z',
        ];

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'saveObject'])
            ->getMock();

        $objectService->method('findObjects')
            ->willReturn([$expiredRight]);

        $objectService->expects($this->once())
            ->method('saveObject')
            ->with(
                register: 'shillinq',
                schema: 'accessRight',
                object: $this->callback(
                    fn($obj) => $obj['isActive'] === false
                ),
            );

        $this->container->method('get')->willReturn($objectService);

        $this->auditLogService->expects($this->once())
            ->method('log')
            ->with(
                action: 'delegation-revoked',
                resourceType: 'accessRight',
                resourceId: 'ar-1',
                result: 'success',
                details: $this->anything(),
            );

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);

        // Use reflection to call the protected run method.
        $method = new \ReflectionMethod(DelegationExpiryJob::class, 'run');
        $method->invoke($this->job, null);

    }//end testExpiredDelegationIsDeactivated()

    /**
     * Test that future delegations are not affected.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.4
     */
    public function testFutureDelegationIsUnchanged(): void
    {
        $futureRight = [
            'id'        => 'ar-2',
            'userId'    => 'user-2',
            'roleId'    => 'role-1',
            'grantedBy' => 'admin-1',
            'isActive'  => true,
            'endDate'   => '2099-12-31T23:59:59Z',
        ];

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'saveObject'])
            ->getMock();

        $objectService->method('findObjects')
            ->willReturn([$futureRight]);

        $objectService->expects($this->never())
            ->method('saveObject');

        $this->container->method('get')->willReturn($objectService);

        $this->auditLogService->expects($this->never())->method('log');

        $method = new \ReflectionMethod(DelegationExpiryJob::class, 'run');
        $method->invoke($this->job, null);

    }//end testFutureDelegationIsUnchanged()

    /**
     * Test that an audit event with action delegation-revoked is written on expiry.
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-16.4
     */
    public function testAuditEventWrittenOnExpiry(): void
    {
        $expiredRight = [
            'id'        => 'ar-3',
            'userId'    => 'user-3',
            'roleId'    => 'role-2',
            'grantedBy' => 'admin-2',
            'isActive'  => true,
            'endDate'   => '2023-06-15T12:00:00Z',
        ];

        $objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findObjects', 'saveObject'])
            ->getMock();
        $objectService->method('findObjects')->willReturn([$expiredRight]);
        $objectService->method('saveObject')->willReturn($expiredRight);

        $this->container->method('get')->willReturn($objectService);

        $this->auditLogService->expects($this->once())
            ->method('log')
            ->with(
                action: 'delegation-revoked',
                resourceType: 'accessRight',
                resourceId: 'ar-3',
                result: 'success',
                details: ['reason' => 'expired'],
            );

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);

        $method = new \ReflectionMethod(DelegationExpiryJob::class, 'run');
        $method->invoke($this->job, null);

    }//end testAuditEventWrittenOnExpiry()
}//end class
