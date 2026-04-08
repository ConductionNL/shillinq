<?php

/**
 * Unit tests for DocumentEventNotifier.
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CollaborationRoleService;
use OCA\Shillinq\Service\DocumentEventNotifier;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DocumentEventNotifier.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.4
 */
class DocumentEventNotifierTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var DocumentEventNotifier
     */
    private DocumentEventNotifier $service;

    /**
     * Mock CollaborationRoleService.
     *
     * @var CollaborationRoleService&MockObject
     */
    private CollaborationRoleService&MockObject $roleService;

    /**
     * Mock INotificationManager.
     *
     * @var INotificationManager&MockObject
     */
    private INotificationManager&MockObject $notificationManager;

    /**
     * Mock IMailer.
     *
     * @var IMailer&MockObject
     */
    private IMailer&MockObject $mailer;

    /**
     * Mock IUserManager.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

    /**
     * Mock IURLGenerator.
     *
     * @var IURLGenerator&MockObject
     */
    private IURLGenerator&MockObject $urlGenerator;

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

        $this->roleService         = $this->createMock(CollaborationRoleService::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->mailer              = $this->createMock(IMailer::class);
        $this->userManager         = $this->createMock(IUserManager::class);
        $this->urlGenerator        = $this->createMock(IURLGenerator::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->service = new DocumentEventNotifier(
            roleService: $this->roleService,
            notificationManager: $this->notificationManager,
            mailer: $this->mailer,
            userManager: $this->userManager,
            urlGenerator: $this->urlGenerator,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that notify dispatches to all reviewers and approvers.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.4
     */
    public function testNotifyDispatchesToReviewersAndApprovers(): void
    {
        $this->roleService->method('getRolesForTarget')
            ->willReturn([
                ['principalType' => 'user', 'principalId' => 'alice', 'role' => 'reviewer'],
                ['principalType' => 'user', 'principalId' => 'bob',   'role' => 'reviewer'],
                ['principalType' => 'user', 'principalId' => 'carol', 'role' => 'approver'],
                ['principalType' => 'user', 'principalId' => 'dave',  'role' => 'contributor'],
            ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager
            ->expects($this->exactly(3))
            ->method('createNotification')
            ->willReturn($notification);

        $this->notificationManager
            ->expects($this->exactly(3))
            ->method('notify');

        // Set up user mocks for email.
        foreach (['alice', 'bob', 'carol'] as $uid) {
            $user = $this->createMock(IUser::class);
            $user->method('getEMailAddress')->willReturn($uid.'@example.com');
            $this->userManager->method('get')
                ->with($uid)
                ->willReturn($user);
        }

        $message = $this->createMock(IMessage::class);
        $message->method('setTo')->willReturnSelf();
        $message->method('setSubject')->willReturnSelf();
        $message->method('setPlainBody')->willReturnSelf();

        $this->mailer->method('createMessage')->willReturn($message);
        $this->mailer->expects($this->exactly(3))->method('send');

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://example.com/apps/shillinq/');

        $count = $this->service->notify(
            eventType: 'invoice.approved',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        self::assertSame(3, $count);

    }//end testNotifyDispatchesToReviewersAndApprovers()

    /**
     * Test that notify gracefully handles missing mailer.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.4
     */
    public function testNotifyHandlesMailerFailureGracefully(): void
    {
        $this->roleService->method('getRolesForTarget')
            ->willReturn([
                ['principalType' => 'user', 'principalId' => 'alice', 'role' => 'reviewer'],
            ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        $this->notificationManager->expects($this->once())
            ->method('notify');

        $user = $this->createMock(IUser::class);
        $user->method('getEMailAddress')->willReturn('alice@example.com');
        $this->userManager->method('get')->willReturn($user);
        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://example.com');

        // Mailer throws exception.
        $this->mailer->method('createMessage')
            ->willThrowException(new \RuntimeException('Mail not configured'));

        // Should not throw — only logs a warning.
        $count = $this->service->notify(
            eventType: 'comment.added',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        self::assertSame(1, $count);

    }//end testNotifyHandlesMailerFailureGracefully()

    /**
     * Test that viewers and contributors are not notified.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.4
     */
    public function testViewersAndContributorsNotNotified(): void
    {
        $this->roleService->method('getRolesForTarget')
            ->willReturn([
                ['principalType' => 'user', 'principalId' => 'viewer1',  'role' => 'viewer'],
                ['principalType' => 'user', 'principalId' => 'contrib1', 'role' => 'contributor'],
            ]);

        $this->notificationManager->expects($this->never())
            ->method('createNotification');

        $count = $this->service->notify(
            eventType: 'invoice.approved',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        self::assertSame(0, $count);

    }//end testViewersAndContributorsNotNotified()
}//end class
