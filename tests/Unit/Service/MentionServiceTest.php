<?php

/**
 * Unit tests for MentionService.
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\MentionService;
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
 * Tests for MentionService.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.1
 */
class MentionServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var MentionService
     */
    private MentionService $service;

    /**
     * Mock IUserManager.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager&MockObject $userManager;

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

        $this->userManager         = $this->createMock(IUserManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->mailer              = $this->createMock(IMailer::class);
        $this->urlGenerator        = $this->createMock(IURLGenerator::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        $this->service = new MentionService(
            userManager: $this->userManager,
            notificationManager: $this->notificationManager,
            mailer: $this->mailer,
            urlGenerator: $this->urlGenerator,
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that extractMentions finds @username patterns.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testExtractMentionsFindsUsernames(): void
    {
        $result = $this->service->extractMentions(content: 'Hello @alice and @bob!');

        self::assertContains('alice', $result);
        self::assertContains('bob', $result);
        self::assertCount(2, $result);

    }//end testExtractMentionsFindsUsernames()

    /**
     * Test that processMentions notifies resolved users and skips non-existent.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testProcessMentionsNotifiesResolvedUsersOnly(): void
    {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('getEMailAddress')->willReturn('alice@example.com');

        $this->userManager->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                ['alice', $alice],
                ['nonexistent', null],
            ]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();

        $this->notificationManager
            ->expects($this->once())
            ->method('createNotification')
            ->willReturn($notification);

        $this->notificationManager
            ->expects($this->once())
            ->method('notify')
            ->with($notification);

        $message = $this->createMock(IMessage::class);
        $message->method('setTo')->willReturnSelf();
        $message->method('setSubject')->willReturnSelf();
        $message->method('setPlainBody')->willReturnSelf();

        $this->mailer
            ->expects($this->once())
            ->method('createMessage')
            ->willReturn($message);

        $this->mailer
            ->expects($this->once())
            ->method('send');

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturn('https://example.com/apps/shillinq/');

        $result = $this->service->processMentions(
            content: 'Please review @alice and cc @nonexistent',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        self::assertSame(['alice'], $result);

    }//end testProcessMentionsNotifiesResolvedUsersOnly()

    /**
     * Test that notification subject is comment_mention.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testNotificationSubjectIsCommentMention(): void
    {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('getEMailAddress')->willReturn(null);

        $this->userManager->method('get')
            ->with('alice')
            ->willReturn($alice);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();

        $notification->expects($this->once())
            ->method('setSubject')
            ->with(
                subject: 'comment_mention',
                parameters: $this->callback(static function ($params) {
                    return isset($params['excerpt'])
                        && isset($params['targetType'])
                        && $params['targetType'] === 'Invoice';
                }),
            )
            ->willReturnSelf();

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        $this->notificationManager->method('notify');

        $this->service->processMentions(
            content: 'Check this @alice',
            targetType: 'Invoice',
            targetId: 'inv-002',
        );

    }//end testNotificationSubjectIsCommentMention()

    /**
     * Test that content without mentions returns empty array.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testNoMentionsReturnsEmptyArray(): void
    {
        $result = $this->service->processMentions(
            content: 'No mentions here',
            targetType: 'Invoice',
            targetId: 'inv-003',
        );

        self::assertSame([], $result);

    }//end testNoMentionsReturnsEmptyArray()
}//end class
