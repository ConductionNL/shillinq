<?php

/**
 * Shillinq Delegation Expiry Job
 *
 * Background job that automatically revokes expired delegations.
 *
 * @category  BackgroundJob
 * @package   OCA\Shillinq\BackgroundJob
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\AuditLogService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Timed job that revokes expired AccessRight delegations every 5 minutes.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4.1
 */
class DelegationExpiryJob extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory         $time                The time factory
     * @param ContainerInterface   $container           The DI container
     * @param AuditLogService      $auditLogService     The audit log service
     * @param INotificationManager $notificationManager The notification manager
     * @param LoggerInterface      $logger              The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private AuditLogService $auditLogService,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);

        // Run every 5 minutes (300 seconds).
        $this->setInterval(seconds: 300);
    }//end __construct()

    /**
     * Execute the delegation expiry check.
     *
     * @param mixed $argument Unused argument
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-4.1
     */
    protected function run($argument): void
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            $activeRights = $objectService->findObjects(
                Application::APP_ID,
                'accessRight',
                ['isActive' => true],
            );

            $now = new \DateTime();

            foreach ($activeRights as $right) {
                $endDate = new \DateTime($right['endDate'] ?? 'now');

                if ($endDate >= $now) {
                    continue;
                }

                // Expire this delegation.
                $right['isActive'] = false;
                $objectService->saveObject(
                    Application::APP_ID,
                    'accessRight',
                    $right,
                );

                $this->auditLogService->log(
                    action: 'delegation-revoked',
                    resourceType: 'accessRight',
                    resourceId: ($right['id'] ?? null),
                    result: 'success',
                    details: ['reason' => 'expired'],
                );

                // Notify grantee.
                $this->sendNotification(
                    userId: ($right['userId'] ?? ''),
                    subject: 'delegation-expired',
                );

                // Notify granting admin.
                if (empty($right['grantedBy']) === false) {
                    $this->sendNotification(
                        userId: $right['grantedBy'],
                        subject: 'delegation-expired',
                    );
                }

                $this->logger->info(
                    'Shillinq: expired delegation revoked',
                    ['accessRightId' => ($right['id'] ?? 'unknown')]
                );
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: delegation expiry job failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Send a notification to a user.
     *
     * @param string $userId  The user ID
     * @param string $subject The notification subject
     *
     * @return void
     */
    private function sendNotification(string $userId, string $subject): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('accessRight', $subject)
                ->setSubject($subject);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: expiry notification failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end sendNotification()
}//end class
