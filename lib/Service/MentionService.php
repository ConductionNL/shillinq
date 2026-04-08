<?php

/**
 * Shillinq Mention Service
 *
 * Parses comment content for @username patterns and dispatches notifications.
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
 * @spec openspec/changes/collaboration/tasks.md#task-7.1
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
 * Parses comment content for @username patterns, resolves each to a Nextcloud
 * userId, and dispatches in-app and email notifications to mentioned users.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.1
 */
class MentionService
{

    /**
     * Constructor for MentionService.
     *
     * @param IUserManager         $userManager         The user manager
     * @param INotificationManager $notificationManager The notification manager
     * @param IMailer              $mailer              The mailer service
     * @param IURLGenerator        $urlGenerator        The URL generator
     * @param LoggerInterface      $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private IUserManager $userManager,
        private INotificationManager $notificationManager,
        private IMailer $mailer,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process mentions in comment content and dispatch notifications.
     *
     * Extracts @username patterns, resolves each via IUserManager, and sends
     * in-app notifications (and email when configured) to resolved users.
     *
     * @param string $content    The comment text containing @mentions
     * @param string $targetType The entity type (Invoice, PurchaseOrder, etc.)
     * @param string $targetId   The OpenRegister object ID of the target
     *
     * @return array<string> List of resolved userIds that were notified
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.1
     */
    public function processMentions(string $content, string $targetType, string $targetId): array
    {
        $usernames = $this->extractMentions(content: $content);
        $notified  = [];

        foreach ($usernames as $username) {
            $user = $this->userManager->get($username);
            if ($user === null) {
                $this->logger->debug(
                    'MentionService: user not found, skipping mention',
                    ['username' => $username]
                );
                continue;
            }

            $this->sendNotification(
                userId: $user->getUID(),
                content: $content,
                targetType: $targetType,
                targetId: $targetId,
            );

            $this->sendEmailNotification(
                userId: $user->getUID(),
                email: $user->getEMailAddress(),
                content: $content,
                targetType: $targetType,
                targetId: $targetId,
            );

            $notified[] = $user->getUID();
        }//end foreach

        return $notified;
    }//end processMentions()

    /**
     * Extract @username patterns from content.
     *
     * @param string $content The comment text
     *
     * @return array<string> List of unique usernames found
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.1
     */
    public function extractMentions(string $content): array
    {
        preg_match_all(
            pattern: '/@([a-zA-Z0-9_\-.]+)/',
            subject: $content,
            matches: $matches,
        );

        return array_unique($matches[1] ?? []);
    }//end extractMentions()

    /**
     * Send an in-app notification to a mentioned user.
     *
     * @param string $userId     The Nextcloud userId to notify
     * @param string $content    The comment excerpt
     * @param string $targetType The entity type
     * @param string $targetId   The target object ID
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.1
     */
    private function sendNotification(
        string $userId,
        string $content,
        string $targetType,
        string $targetId,
    ): void {
        try {
            $excerpt      = mb_substr(string: $content, start: 0, length: 100);
            $notification = $this->notificationManager->createNotification();

            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject(type: $targetType, id: $targetId)
                ->setSubject(
                    subject: 'comment_mention',
                    parameters: [
                        'excerpt'    => $excerpt,
                        'targetType' => $targetType,
                        'targetId'   => $targetId,
                    ],
                );

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MentionService: failed to send notification',
                [
                    'userId'    => $userId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end sendNotification()

    /**
     * Send an email notification to a mentioned user if mail is configured.
     *
     * @param string      $userId     The Nextcloud userId
     * @param string|null $email      The user's email address
     * @param string      $content    The comment excerpt
     * @param string      $targetType The entity type
     * @param string      $targetId   The target object ID
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.1
     */
    private function sendEmailNotification(
        string $userId,
        ?string $email,
        string $content,
        string $targetType,
        string $targetId,
    ): void {
        if (empty($email) === true) {
            return;
        }

        try {
            $excerpt = mb_substr(string: $content, start: 0, length: 100);
            $link    = $this->urlGenerator->linkToRouteAbsolute(
                routeName: Application::APP_ID.'.dashboard.page',
            );

            $message = $this->mailer->createMessage();
            $message->setTo([$email]);
            $message->setSubject('You were mentioned in a '.$targetType.' comment');
            $message->setPlainBody(
                'You were mentioned in a comment on '.$targetType.' ('.$targetId."):\n\n"
                .$excerpt."\n\nView the document: ".$link
            );

            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MentionService: email notification failed (mail may not be configured)',
                [
                    'userId'    => $userId,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end sendEmailNotification()
}//end class
