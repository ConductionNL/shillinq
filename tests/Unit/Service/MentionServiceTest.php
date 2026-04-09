<?php

/**
 * Shillinq MentionService Unit Tests
 *
 * @category Tests
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
use OCP\Notification\INotification;
use OCP\Notification\INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MentionService.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.1
 */
class MentionServiceTest extends TestCase
{

    /**
     * The MentionService under test.
     *
     * @var MentionService
     */
    private MentionService $service;

    /**
     * Mock user manager.
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * Mock notification manager.
     *
     * @var INotificationManager
     */
    private INotificationManager $notificationManager;

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
        $urlGenerator              = $this->createMock(IURLGenerator::class);
        $logger                    = $this->createMock(LoggerInterface::class);

        $this->service = new MentionService(
            userManager: $this->userManager,
            notificationManager: $this->notificationManager,
            urlGenerator: $urlGenerator,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that a valid mention sends a notification and returns the userId.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testProcessMentionsNotifiesResolvedUser(): void
    {
        $aliceUser = $this->createMock(IUser::class);
        $aliceUser->method('getUID')->willReturn('alice');

        $this->userManager->method('get')
            ->willReturnCallback(function (string $uid) use ($aliceUser) {
                if ($uid === 'alice') {
                    return $aliceUser;
                }
                return null;
            });

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        $this->notificationManager->expects($this->once())
            ->method('notify');

        $result = $this->service->processMentions(
            content: 'Please review @alice and cc @nonexistent',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        $this->assertContains(needle: 'alice', haystack: $result);
        $this->assertNotContains(needle: 'nonexistent', haystack: $result);
    }//end testProcessMentionsNotifiesResolvedUser()

    /**
     * Test that non-existent users are skipped without exceptions.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testProcessMentionsSkipsNonexistentUser(): void
    {
        $this->userManager->method('get')->willReturn(null);
        $this->notificationManager->expects($this->never())->method('notify');

        $result = $this->service->processMentions(
            content: 'Hello @nonexistent',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        $this->assertEmpty($result);
    }//end testProcessMentionsSkipsNonexistentUser()

    /**
     * Test that notification subject is 'comment_mention'.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testProcessMentionsUsesCorrectSubject(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');

        $this->userManager->method('get')->willReturn($user);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->expects($this->once())
            ->method('setSubject')
            ->with(
                $this->equalTo('comment_mention'),
                $this->callback(function ($params) {
                    return isset($params['author'])
                        && $params['author'] === 'system'
                        && isset($params['excerpt']);
                })
            )
            ->willReturn($notification);

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        $this->notificationManager->expects($this->once())
            ->method('notify');

        $this->service->processMentions(
            content: 'FYI @bob please check',
            targetType: 'Contract',
            targetId: 'ctr-001',
        );
    }//end testProcessMentionsUsesCorrectSubject()

    /**
     * Test that content without mentions returns an empty array.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.1
     */
    public function testProcessMentionsWithNoMentionsReturnsEmpty(): void
    {
        $this->userManager->expects($this->never())->method('get');

        $result = $this->service->processMentions(
            content: 'No mentions here.',
            targetType: 'Invoice',
            targetId: 'inv-001',
        );

        $this->assertSame(expected: [], actual: $result);
    }//end testProcessMentionsWithNoMentionsReturnsEmpty()
}//end class
