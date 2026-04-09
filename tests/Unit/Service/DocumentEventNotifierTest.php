<?php

/**
 * Shillinq DocumentEventNotifier Unit Tests
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
 * @spec openspec/changes/collaboration/tasks.md#task-11.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\DocumentEventNotifier;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Notification\INotification;
use OCP\Notification\INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for DocumentEventNotifier.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-11.4
 */
class DocumentEventNotifierTest extends TestCase
{

    /**
     * Mock notification manager.
     *
     * @var INotificationManager
     */
    private INotificationManager $notificationManager;

    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Mock object service.
     *
     * @var object
     */
    private object $objectService;

    /**
     * Create a DocumentEventNotifier with configurable mailer availability.
     *
     * @param bool $mailerAvailable Whether IMailer should be available
     *
     * @return DocumentEventNotifier
     */
    private function createNotifier(bool $mailerAvailable): DocumentEventNotifier
    {
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $urlGenerator              = $this->createMock(IURLGenerator::class);
        $logger                    = $this->createMock(LoggerInterface::class);

        $this->objectService = new class {

            /**
             * Roles to return.
             *
             * @var array<int,array<string,mixed>>
             */
            public array $roles = [];

            /**
             * Find objects.
             *
             * @param array<string,string> $filters Filters
             *
             * @return array<int,array<string,mixed>>
             */
            public function findObjects(array $filters): array
            {
                return $this->roles;
            }//end findObjects()
        };

        $this->container = $this->createMock(ContainerInterface::class);

        if ($mailerAvailable === true) {
            $mailer = $this->createMock(IMailer::class);
            $this->container->method('get')
                ->willReturnCallback(function (string $id) use ($mailer) {
                    if ($id === IMailer::class) {
                        return $mailer;
                    }
                    return $this->objectService;
                });
        } else {
            $this->container->method('get')
                ->willReturnCallback(function (string $id) {
                    if ($id === IMailer::class) {
                        throw new \RuntimeException('Mailer not configured');
                    }
                    return $this->objectService;
                });
        }

        return new DocumentEventNotifier(
            container: $this->container,
            notificationManager: $this->notificationManager,
            urlGenerator: $urlGenerator,
            logger: $logger,
        );
    }//end createNotifier()

    /**
     * Test that reviewers and approvers all receive notifications.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.4
     */
    public function testNotifyDispatchesToReviewersAndApprovers(): void
    {
        $notifier = $this->createNotifier(mailerAvailable: false);

        $this->objectService->roles = [
            ['principalId' => 'alice', 'role' => 'reviewer'],
            ['principalId' => 'bob', 'role' => 'reviewer'],
            ['principalId' => 'carol', 'role' => 'approver'],
            ['principalId' => 'dave', 'role' => 'contributor'],
        ];

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturn($notification);
        $notification->method('setUser')->willReturn($notification);
        $notification->method('setDateTime')->willReturn($notification);
        $notification->method('setObject')->willReturn($notification);
        $notification->method('setSubject')->willReturn($notification);

        $this->notificationManager->method('createNotification')
            ->willReturn($notification);
        // 3 notifications: 2 reviewers + 1 approver (contributor excluded).
        $this->notificationManager->expects($this->exactly(3))
            ->method('notify');

        $notifier->notify(
            eventType: 'invoice.approved',
            targetType: 'Invoice',
            targetId: 'inv-001',
            context: ['action' => 'approved'],
        );
    }//end testNotifyDispatchesToReviewersAndApprovers()

    /**
     * Test that missing IMailer does not throw exceptions.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.4
     */
    public function testNotifyWithoutMailerDoesNotThrow(): void
    {
        $notifier = $this->createNotifier(mailerAvailable: false);

        $this->objectService->roles = [
            ['principalId' => 'alice', 'role' => 'approver'],
        ];

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

        // Should not throw even though mailer is unavailable.
        $notifier->notify(
            eventType: 'comment.added',
            targetType: 'Invoice',
            targetId: 'inv-002',
            context: [],
        );
    }//end testNotifyWithoutMailerDoesNotThrow()

    /**
     * Test that contributor roles are not notified.
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-11.4
     */
    public function testNotifySkipsContributorAndViewerRoles(): void
    {
        $notifier = $this->createNotifier(mailerAvailable: false);

        $this->objectService->roles = [
            ['principalId' => 'eve', 'role' => 'contributor'],
            ['principalId' => 'frank', 'role' => 'viewer'],
        ];

        $this->notificationManager->expects($this->never())
            ->method('notify');

        $notifier->notify(
            eventType: 'dispute.opened',
            targetType: 'NegotiationThread',
            targetId: 'nt-001',
            context: [],
        );
    }//end testNotifySkipsContributorAndViewerRoles()
}//end class
