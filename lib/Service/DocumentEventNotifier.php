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
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Notify relevant users about a document event.
     *
     * Fetches all CollaborationRole objects with role reviewer or approver for
     * the target, and dispatches in-app notifications and emails to each.
     *
     * @param string              $eventType  The event type (e.g. invoice.approved, comment.added)
     * @param string              $targetType The entity type
     * @param string              $targetId   The target object ID
     * @param array<string,mixed> $context    Additional context for the notification
     *
     * @return int Number of users notified
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    public function notify(string $eventType, string $targetType, string $targetId, array $context = []): int
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

            if ($principalType !== 'user' || empty($principalId) === true) {
                continue;
            }

            // Avoid duplicate notifications.
            if (in_array($principalId, $notifiedUsers, true) === true) {
                continue;
            }

            $this->sendInAppNotification(
                userId: $principalId,
                eventType: $eventType,
                targetType: $targetType,
                targetId: $targetId,
                context: $context,
            );

            $this->sendEmailNotification(
                userId: $principalId,
                eventType: $eventType,
                targetType: $targetType,
                targetId: $targetId,
                context: $context,
            );

            $notifiedUsers[] = $principalId;
        }//end foreach

        return count($notifiedUsers);
    }//end notify()

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
     * Degrades gracefully if mail is not configured.
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
    private function sendEmailNotification(
        string $userId,
        string $eventType,
        string $targetType,
        string $targetId,
        array $context,
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

            $link    = $this->urlGenerator->linkToRouteAbsolute(
                routeName: Application::APP_ID.'.dashboard.page',
            );
            $subject = ucfirst(str_replace(
                search: '.',
                replace: ': ',
                subject: $eventType,
            )).' — '.$targetType.' '.$targetId;

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->setSubject($subject);
            $message->setPlainBody(
                'Event: '.$eventType."\n"
                .'Document: '.$targetType.' '.$targetId."\n\n"
                .'View the document: '.$link
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
