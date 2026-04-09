<?php

/**
 * Shillinq Document Event Notifier
 *
 * Service for dispatching notifications and emails when document events occur.
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
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for dispatching notifications and emails when document events occur.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-7.4
 */
class DocumentEventNotifier
{

    /**
     * The mailer instance, or null if not available.
     *
     * @var IMailer|null
     */
    private ?IMailer $mailer = null;

    /**
     * Constructor for the DocumentEventNotifier.
     *
     * @param ContainerInterface   $container           The service container
     * @param INotificationManager $notificationManager The notification manager
     * @param IURLGenerator        $urlGenerator        The URL generator
     * @param LoggerInterface      $logger              The logger
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    public function __construct(
        private ContainerInterface $container,
        private INotificationManager $notificationManager,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
        try {
            $this->mailer = $this->container->get(IMailer::class);
        } catch (\Throwable $e) {
            $this->logger->info(
                'Shillinq: IMailer not available, email notifications disabled',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end __construct()

    /**
     * Dispatch notifications (and optional emails) for a document event.
     *
     * Finds all CollaborationRole objects with role reviewer or approver on the
     * given target and sends each principal a notification. If the mailer is
     * configured, an email is also sent.
     *
     * @param string              $eventType  The event type (e.g. 'status_changed', 'comment_added')
     * @param string              $targetType The type of the target object
     * @param string              $targetId   The unique identifier of the target object
     * @param array<string,mixed> $context    Additional context data for the notification
     *
     * @return void
     *
     * @spec openspec/changes/collaboration/tasks.md#task-7.4
     */
    public function notify(string $eventType, string $targetType, string $targetId, array $context): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: could not resolve ObjectService for event notification',
                ['exception' => $e->getMessage()]
            );
            return;
        }//end try

        try {
            $roles = $objectService->findObjects(
                filters: [
                    'targetType' => $targetType,
                    'targetId'   => $targetId,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to fetch collaboration roles for notification',
                ['exception' => $e->getMessage()]
            );
            return;
        }//end try

        $notifiableRoles = ['reviewer', 'approver'];

        foreach ($roles as $role) {
            $roleName = ($role['role'] ?? '');
            if (in_array(needle: $roleName, haystack: $notifiableRoles, strict: true) === false) {
                continue;
            }

            $principalId = ($role['principalId'] ?? '');
            if (empty($principalId) === true) {
                continue;
            }

            $this->sendNotification(
                userId: $principalId,
                eventType: $eventType,
                targetType: $targetType,
                targetId: $targetId,
                context: $context
            );

            $this->sendEmail(
                userId: $principalId,
                eventType: $eventType,
                targetType: $targetType,
                targetId: $targetId,
                context: $context
            );
        }//end foreach
    }//end notify()

    /**
     * Send a Nextcloud notification to a user.
     *
     * @param string              $userId     The recipient user ID
     * @param string              $eventType  The event type
     * @param string              $targetType The target object type
     * @param string              $targetId   The target object ID
     * @param array<string,mixed> $context    Additional context data
     *
     * @return void
     */
    private function sendNotification(
        string $userId,
        string $eventType,
        string $targetType,
        string $targetId,
        array $context,
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(app: 'shillinq')
                ->setUser(user: $userId)
                ->setDateTime(dateTime: new \DateTime())
                ->setObject(objectType: $targetType, objectId: $targetId)
                ->setSubject(subject: $eventType, parameters: $context);
            $this->notificationManager->notify(notification: $notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to send event notification',
                [
                    'userId'    => $userId,
                    'eventType' => $eventType,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end sendNotification()

    /**
     * Send an email notification to a user if the mailer is available.
     *
     * @param string              $userId     The recipient user ID
     * @param string              $eventType  The event type
     * @param string              $targetType The target object type
     * @param string              $targetId   The target object ID
     * @param array<string,mixed> $context    Additional context data
     *
     * @return void
     */
    private function sendEmail(
        string $userId,
        string $eventType,
        string $targetType,
        string $targetId,
        array $context,
    ): void {
        if ($this->mailer === null) {
            return;
        }

        try {
            $link = $this->urlGenerator->linkToRouteAbsolute(
                routeName: 'shillinq.page.index',
                arguments: ['target' => $targetType.'/'.$targetId]
            );

            $message = $this->mailer->createMessage();
            $message->setTo(recipients: [$userId]);
            $message->setSubject(subject: 'Shillinq: '.$eventType.' on '.$targetType.' '.$targetId);
            $message->setPlainBody(
                body: 'A '.$eventType.' event occurred on '.$targetType.' '.$targetId.'.'
                    ."\n\nView it here: ".$link
            );

            $this->mailer->send(message: $message);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: failed to send email notification, mailer may not be configured',
                [
                    'userId'    => $userId,
                    'eventType' => $eventType,
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end sendEmail()
}//end class
