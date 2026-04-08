<?php

/**
 * Unit tests for NotificationService.
 *
 * @spec openspec/changes/core/tasks.md#task-12
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\NotificationService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test suite for NotificationService.
 *
 * @spec openspec/changes/core/tasks.md#task-12
 */
class NotificationServiceTest extends TestCase
{
    /**
     * The notification manager mock.
     *
     * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $notificationManager;

    /**
     * The logger mock.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * The service under test.
     *
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
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new NotificationService(
            notificationManager: $this->notificationManager,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that a completed DataJob sends the correct notification.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testNotifyImportCompleteWithCompletedStatus(): void
    {
        $notification = $this->createMock(INotification::class);
        $this->notificationManager
            ->expects($this->once())
            ->method('createNotification')
            ->willReturn($notification);

        $notification->expects($this->once())
            ->method('setApp')
            ->with('shillinq')
            ->willReturnSelf();

        $notification->expects($this->once())
            ->method('setUser')
            ->with('testuser')
            ->willReturnSelf();

        $notification->expects($this->once())
            ->method('setDateTime')
            ->willReturnSelf();

        $notification->expects($this->once())
            ->method('setObject')
            ->with(objectType: 'dataJob', objectId: 'job-123')
            ->willReturnSelf();

        $notification->expects($this->once())
            ->method('setSubject')
            ->with(
                subject: 'datajob_completed',
                parameters: [
                    'fileName'         => 'test.csv',
                    'processedRecords' => 10,
                ]
            )
            ->willReturnSelf();

        $notification->expects($this->once())
            ->method('setLink')
            ->willReturnSelf();

        $this->notificationManager
            ->expects($this->once())
            ->method('notify')
            ->with($notification);

        $this->service->notifyImportComplete(
            userId: 'testuser',
            jobId: 'job-123',
            fileName: 'test.csv',
            status: 'completed',
            processedRecords: 10,
            failedRecords: 0,
        );
    }//end testNotifyImportCompleteWithCompletedStatus()

    /**
     * Test that a failed DataJob sends the correct notification.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testNotifyImportCompleteWithFailedStatus(): void
    {
        $notification = $this->createMock(INotification::class);
        $this->notificationManager
            ->expects($this->once())
            ->method('createNotification')
            ->willReturn($notification);

        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();

        $notification->expects($this->once())
            ->method('setSubject')
            ->with(
                subject: 'datajob_failed',
                parameters: [
                    'fileName'      => 'fail.csv',
                    'failedRecords' => 3,
                ]
            )
            ->willReturnSelf();

        $this->notificationManager
            ->expects($this->once())
            ->method('notify');

        $this->service->notifyImportComplete(
            userId: 'testuser',
            jobId: 'job-456',
            fileName: 'fail.csv',
            status: 'failed',
            processedRecords: 7,
            failedRecords: 3,
        );
    }//end testNotifyImportCompleteWithFailedStatus()

    /**
     * Test self-action guard: no notification when author === recipient.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testSelfActionGuardPreventsNotification(): void
    {
        $this->notificationManager
            ->expects($this->never())
            ->method('createNotification');

        $this->notificationManager
            ->expects($this->never())
            ->method('notify');

        $this->service->notifyImportComplete(
            userId: 'sameuser',
            jobId: 'job-789',
            fileName: 'self.csv',
            status: 'completed',
            processedRecords: 5,
            failedRecords: 0,
            currentUserId: 'sameuser',
        );
    }//end testSelfActionGuardPreventsNotification()

    /**
     * Test that notification is sent when currentUser differs from recipient.
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-12
     */
    public function testDifferentUserReceivesNotification(): void
    {
        $notification = $this->createMock(INotification::class);
        $this->notificationManager
            ->expects($this->once())
            ->method('createNotification')
            ->willReturn($notification);

        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();

        $this->notificationManager
            ->expects($this->once())
            ->method('notify');

        $this->service->notifyImportComplete(
            userId: 'recipient',
            jobId: 'job-101',
            fileName: 'other.csv',
            status: 'completed',
            processedRecords: 3,
            failedRecords: 0,
            currentUserId: 'sender',
        );
    }//end testDifferentUserReceivesNotification()
}//end class
