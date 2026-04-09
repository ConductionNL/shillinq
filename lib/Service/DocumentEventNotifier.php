<?php

/**
 * Shillinq Document Event Notifier
 *
 * Dispatches notifications when document events fire.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.4
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Dispatches notification events when internal Shillinq events fire.
 *
 * Notifies all users with reviewer or approver CollaborationRole on the target
 * document. Uses IMailer for email and INotificationManager for in-app notifications.
 * Group principals are expanded to their individual member user IDs.
 * Degrades gracefully if mail is not configured.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.4
 */
class DocumentEventNotifier
{
    /**
     * Constructor for DocumentEventNotifier.
     *
     * @param CollaborationRoleService $roleService         The collaboration role service
     * @param INotificationManager     $notificationManager The notification manager
     * @param IMailer                  $mailer              The mailer service
     * @param IUserManager             $userManager         The user manager
     * @param IGroupManager            $groupManager        The group manager for group expansion
     * @param IURLGenerator            $urlGenerator        The URL generator
     * @param LoggerInterface          $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private CollaborationRoleService $roleService,
        private INotificationManager $notificationManager,
        private IMailer $mailer,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Notify relevant users about a document event.
     *
     * Fetches all CollaborationRole objects with role reviewer or approver for
     * the target, and dispatches in-app notifications and emails to each.
     * Group principals are expanded to their individual member user IDs.
     *
     * @param string              $eventType  The event type (e.g. invoice.approved, comment.added)
     * @param string              $targetType The entity type
     * @param string              $targetId   The target object ID
     * @param array<string,mixed> $context    Additional context for the in-app notification
     *
     * @return int Number of users notified
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    public function notify(string $eventType, string $targetType, string $targetId, array $context=[]): int
    {
        $roles = $this->roleService->getRolesForTarget(
            targetType: $targetType,
            targetId: $targetId,
        );

        $notifiedUsers = [];

        foreach ($roles as $role) {
            $roleName = ($role['role'] ?? '');
            if (in_array($roleName, ['reviewer', 'approver'], true) === false) {
                continue;
            }

            $principalType = ($role['principalType'] ?? 'user');
            $principalId   = ($role['principalId'] ?? '');

            if (empty($principalId) === true) {
                continue;
            }

            // Expand group principals to individual member user IDs.
            if ($principalType === 'group') {
                $userIds = $this->resolveGroupMembers(groupId: $principalId);
            } else if ($principalType === 'user') {
                $userIds = [$principalId];
            } else {
                continue;
            }

            foreach ($userIds as $userId) {
                // Avoid duplicate notifications.
                if (in_array($userId, $notifiedUsers, true) === true) {
                    continue;
                }

                $this->sendInAppNotification(
                    userId: $userId,
                    eventType: $eventType,
                    targetType: $targetType,
                    targetId: $targetId,
                    context: $context,
                );

                $this->sendEmailNotification(
                    userId: $userId,
                    eventType: $eventType,
                    targetType: $targetType,
                    targetId: $targetId,
                );

                $notifiedUsers[] = $userId;
            }//end foreach
        }//end foreach

        return count($notifiedUsers);
    }//end notify()

    /**
     * Resolve a group ID to the list of member user IDs.
     *
     * @param string $groupId The Nextcloud group ID
     *
     * @return string[] Member user IDs
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    private function resolveGroupMembers(string $groupId): array
    {
        try {
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                return [];
            }

            $userIds = [];
            foreach ($group->getUsers() as $user) {
                $userIds[] = $user->getUID();
            }

            return $userIds;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'DocumentEventNotifier: failed to resolve group members',
                [
                    'groupId'   => $groupId,
                    'exception' => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end resolveGroupMembers()

    /**
     * Send an in-app notification for a document event.
     *
     * @param string              $userId     The Nextcloud userId
     * @param string              $eventType  The event type
     * @param string              $targetType The entity type
     * @param string              $targetId   The target object ID
     * @param array<string,mixed> $context    Additional context
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    private function sendInAppNotification(
        string $userId,
        string $eventType,
        string $targetType,
        string $targetId,
        array $context,
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();

            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject(type: $targetType, id: $targetId)
                ->setSubject(
                    subject: $eventType,
                    parameters: array_merge(
                        $context,
                        [
                            'targetType' => $targetType,
                            'targetId'   => $targetId,
                        ],
                    ),
                );

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'DocumentEventNotifier: in-app notification failed',
                [
                    'userId'    => $userId,
                    'eventType' => $eventType,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end sendInAppNotification()

    /**
     * Send an email notification for a document event.
     *
     * Degrades gracefully if mail is not configured. Context values are intentionally
     * omitted from the email body to prevent PII leakage (comment content, user IDs).
     *
     * @param string $userId     The Nextcloud userId
     * @param string $eventType  The event type
     * @param string $targetType The entity type
     * @param string $targetId   The target object ID
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    private function sendEmailNotification(
        string $userId,
        string $eventType,
        string $targetType,
        string $targetId,
    ): void {
        try {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                return;
            }

            $email = $user->getEMailAddress();
            if (empty($email) === true) {
                return;
            }

            $link       = $this->urlGenerator->linkToRouteAbsolute(
                routeName: Application::APP_ID.'.dashboard.page',
            );
            $eventLabel = str_replace(
                search: '.',
                replace: ': ',
                subject: $eventType,
            );
            $subject    = ucfirst($eventLabel).' — '.$targetType.' '.$targetId;

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->setSubject($subject);

            // Email body must not include context values — they may contain comment
            // content, user IDs, or other PII. Only structural identifiers are safe.
            $message->setPlainBody(
                'Event: '.$eventType."\n"
                .'Document type: '.$targetType."\n"
                .'Document ID: '.$targetId."\n\n"
                .'View in Shillinq: '.$link
            );

            $this->mailer->send($message);
        } catch (\Throwable $e) {
            // Degrade gracefully — email might not be configured.
            $this->logger->warning(
                'DocumentEventNotifier: email notification failed (mail may not be configured)',
                [
                    'userId'    => $userId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end sendEmailNotification()
}//end class
