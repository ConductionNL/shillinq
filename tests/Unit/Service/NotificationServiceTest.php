<?php

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for NotificationService.
 *
 * @spec openspec/changes/core/tasks.md#task-12.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\NotificationService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the NotificationService class.
 *
 * @spec openspec/changes/core/tasks.md#task-12.1
 */
class NotificationServiceTest extends TestCase
{
    /**
     * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $notificationManager;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var NotificationService
     */
    private NotificationService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->service = new NotificationService(
            notificationManager: $this->notificationManager,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that a completed notification is sent with correct subject and recipient.
     *
     * @spec openspec/changes/core/tasks.md#task-12.1
     *
     * @return void
     */
    public function testNotifyImportCompleteCreatesNotificationWithCorrectSubject(): void
    {
        // Current user is different from recipient.
        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();

        $this->notificationManager
            ->expects($this->once())
            ->method('createNotification')
            ->willReturn($notification);

        $notification->expects($this->once())
            ->method('setSubject')
            ->with(
                'datajob_completed',
                [
                    'fileName'       => 'test.csv',
                    'processedCount' => 10,
                    'failedCount'    => 0,
                ]
            )
            ->willReturnSelf();

        $notification->expects($this->once())
            ->method('setUser')
            ->with('recipient-user')
            ->willReturnSelf();

        $this->notificationManager
            ->expects($this->once())
            ->method('notify')
            ->with($notification);

        $this->service->notifyImportComplete(
            recipientUserId: 'recipient-user',
            fileName: 'test.csv',
            dataJobId: 42,
            status: 'completed',
            processedCount: 10,
            failedCount: 0,
        );
    }//end testNotifyImportCompleteCreatesNotificationWithCorrectSubject()

    /**
     * Test the deep link format includes the DataJob ID.
     *
     * @spec openspec/changes/core/tasks.md#task-12.1
     *
     * @return void
     */
    public function testNotifyImportCompleteIncludesDeepLink(): void
    {
        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $notification->expects($this->once())
            ->method('setLink')
            ->with('/apps/shillinq/data-jobs/99')
            ->willReturnSelf();

        $this->notificationManager
            ->method('createNotification')
            ->willReturn($notification);

        $this->notificationManager
            ->expects($this->once())
            ->method('notify');

        $this->service->notifyImportComplete(
            recipientUserId: 'other-user',
            fileName: 'import.csv',
            dataJobId: 99,
            status: 'failed',
            processedCount: 3,
            failedCount: 2,
        );
    }//end testNotifyImportCompleteIncludesDeepLink()

    /**
     * Test self-action guard: no notification when author equals recipient.
     *
     * @spec openspec/changes/core/tasks.md#task-12.1
     *
     * @return void
     */
    public function testSelfActionGuardSkipsNotification(): void
    {
        $currentUser = $this->createMock(IUser::class);
        $currentUser->method('getUID')->willReturn('same-user');
        $this->userSession->method('getUser')->willReturn($currentUser);

        $this->notificationManager
            ->expects($this->never())
            ->method('createNotification');

        $this->notificationManager
            ->expects($this->never())
            ->method('notify');

        $this->service->notifyImportComplete(
            recipientUserId: 'same-user',
            fileName: 'test.csv',
            dataJobId: 1,
            status: 'completed',
        );
    }//end testSelfActionGuardSkipsNotification()
}//end class
