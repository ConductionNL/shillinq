<?php

/**
 * Shillinq Notification Service
 *
 * Service for dispatching Nextcloud notifications for DataJob events.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Service for dispatching Nextcloud notifications for DataJob events.
 *
 * @spec openspec/changes/core/tasks.md#task-9
 */
class NotificationService
{
    /**
     * Constructor for the NotificationService.
     *
     * @param INotificationManager $notificationManager The notification manager
     * @param LoggerInterface      $logger              The logger interface
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function __construct(
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Notify the user that a DataJob import has completed or failed.
     *
     * @param string $userId          The user who initiated the import
     * @param string $jobId           The DataJob object ID
     * @param string $fileName        The imported file name
     * @param string $status          The final status (completed or failed)
     * @param int    $processedRecords Number of successfully processed records
     * @param int    $failedRecords   Number of failed records
     * @param string $currentUserId   The user triggering the notification (for self-action guard)
     *
     * @return void
     *
     * @spec openspec/changes/core/tasks.md#task-9
     */
    public function notifyImportComplete(
        string $userId,
        string $jobId,
        string $fileName,
        string $status,
        int $processedRecords,
        int $failedRecords,
        string $currentUserId = '',
    ): void {
        // Self-action guard: do not notify if author === recipient.
        if ($currentUserId !== '' && $currentUserId === $userId) {
            return;
        }

        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID);
            $notification->setUser($userId);
            $notification->setDateTime(new \DateTime());
            $notification->setObject(objectType: 'dataJob', objectId: $jobId);

            if ($status === 'completed') {
                $notification->setSubject(
                    subject: 'datajob_completed',
                    parameters: [
                        'fileName'         => $fileName,
                        'processedRecords' => $processedRecords,
                    ]
                );
            } else {
                $notification->setSubject(
                    subject: 'datajob_failed',
                    parameters: [
                        'fileName'      => $fileName,
                        'failedRecords' => $failedRecords,
                    ]
                );
            }

            $notification->setLink('/apps/shillinq/data-jobs/' . $jobId);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to send notification for DataJob',
                ['exception' => $e->getMessage(), 'jobId' => $jobId]
            );
        }//end try
    }//end notifyImportComplete()
}//end class
