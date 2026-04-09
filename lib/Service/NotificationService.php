<?php

/**
 * Shillinq Notification Service
 *
 * Dispatches Nextcloud notifications for DataJob completion events.
 *
 * @spec openspec/changes/core/tasks.md#task-9.1
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

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Service for dispatching Nextcloud notifications on DataJob events.
 *
 * @spec openspec/changes/core/tasks.md#task-9.1
 */
class NotificationService
{
    /**
     * Constructor for NotificationService.
     *
     * @param INotificationManager $notificationManager The notification manager
     * @param IUserSession         $userSession         The user session
     * @param LoggerInterface      $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private INotificationManager $notificationManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Notify a user that a CSV import has completed.
     *
     * Self-action guard: if the current user is the recipient, no notification is sent.
     *
     * @param string $recipientUserId The user to notify
     * @param string $fileName        The imported file name
     * @param int    $dataJobId       The DataJob object ID
     * @param string $status          The final status (completed or failed)
     * @param int    $processedCount  Number of successfully imported records
     * @param int    $failedCount     Number of failed records
     *
     * @spec openspec/changes/core/tasks.md#task-9.1
     *
     * @return void
     */
    public function notifyImportComplete(
        string $recipientUserId,
        string $fileName,
        int $dataJobId,
        string $status,
        int $processedCount=0,
        int $failedCount=0,
    ): void {
        // Self-action guard: skip if author === recipient.
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null && $currentUser->getUID() === $recipientUserId) {
            $this->logger->debug(
                'Shillinq: skipping self-notification for DataJob '.$dataJobId
            );
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();

            if ($status === 'completed') {
                $subject = 'datajob_completed';
            } else {
                $subject = 'datajob_failed';
            }

            $notification
                ->setApp(Application::APP_ID)
                ->setUser($recipientUserId)
                ->setDateTime(new \DateTime())
                ->setObject('dataJob', (string) $dataJobId)
                ->setSubject(
                    $subject,
                    [
                        'fileName'       => $fileName,
                        'processedCount' => $processedCount,
                        'failedCount'    => $failedCount,
                    ]
                )
                ->setLink('/apps/shillinq/data-jobs/'.$dataJobId);

            $this->notificationManager->notify($notification);

            $this->logger->info(
                'Shillinq: notification sent to '.$recipientUserId.' for DataJob '.$dataJobId
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to send notification',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end notifyImportComplete()
}//end class
