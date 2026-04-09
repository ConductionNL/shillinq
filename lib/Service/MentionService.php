<?php

/**
 * Shillinq Mention Service
 *
 * Service for processing @mentions in content and dispatching notifications.
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
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Service for processing @mentions in content and dispatching notifications.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.1
 */
class MentionService
{

    /**
     * Constructor for the MentionService.
     *
     * @param IUserManager         $userManager         The user manager
     * @param INotificationManager $notificationManager The notification manager
     * @param IURLGenerator        $urlGenerator        The URL generator
     * @param LoggerInterface      $logger              The logger
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.1
     */
    public function __construct(
        private IUserManager $userManager,
        private INotificationManager $notificationManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Extract @username mentions from content, resolve users, and send notifications.
     *
     * @param string $content    The content to scan for @mentions
     * @param string $targetType The type of the target object (e.g. 'document', 'comment')
     * @param string $targetId   The unique identifier of the target object
     *
     * @return array<string> List of resolved user IDs that were notified
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.1
     */
    public function processMentions(string $content, string $targetType, string $targetId): array
    {
        $matches = [];
        preg_match_all(
            pattern: '/(?:^|\s)@([a-zA-Z0-9_.]+)/',
            subject: $content,
            matches: $matches
        );

        if (empty($matches[1]) === true) {
            return [];
        }

        $usernames   = array_unique(values: $matches[1]);
        $resolvedIds = [];

        foreach ($usernames as $username) {
            $user = $this->userManager->get(uid: $username);
            if ($user === null) {
                $this->logger->debug(
                    'Shillinq: mentioned user not found, skipping',
                    ['username' => $username]
                );
                continue;
            }

            try {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(app: 'shillinq')
                    ->setUser(user: $user->getUID())
                    ->setDateTime(dateTime: new \DateTime())
                    ->setObject(objectType: $targetType, objectId: $targetId)
                    ->setSubject(
                        subject: 'comment_mention',
                        parameters: [
                            'author'  => 'system',
                            'excerpt' => substr(string: $content, offset: 0, length: 100),
                        ]
                    );
                $this->notificationManager->notify(notification: $notification);

                $resolvedIds[] = $user->getUID();
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Shillinq: failed to send mention notification',
                    [
                        'username'  => $username,
                        'exception' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return $resolvedIds;
    }//end processMentions()
}//end class
