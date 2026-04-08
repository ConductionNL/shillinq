<?php

/**
 * Shillinq Recertification Service
 *
 * Handles access recertification campaign logic: notifications and review decisions.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.6
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing access recertification campaigns.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.6
 */
class RecertificationService
{
    /**
     * Constructor.
     *
     * @param ContainerInterface   $container           The DI container
     * @param INotificationManager $notificationManager The notification manager
     * @param AuditLogService      $auditLogService     The audit log service
     * @param LoggerInterface      $logger              The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private INotificationManager $notificationManager,
        private AuditLogService $auditLogService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Dispatch review notifications to all role-owners for a campaign.
     *
     * @param array $campaign The recertification campaign object
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.6
     */
    public function dispatchReviewNotifications(array $campaign): void
    {
        try {
            $objectService = $this->container->get(
                'OCA\OpenRegister\Service\ObjectService'
            );

            // Find all active users who need to be reviewed.
            $users = $objectService->findObjects(
                register: Application::APP_ID,
                schema: 'user',
                filters: ['isActive' => true],
            );

            foreach ($users as $user) {
                if (empty($user['username']) === true) {
                    continue;
                }

                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($user['username'])
                    ->setDateTime(new \DateTime())
                    ->setObject('accessRecertification', ($campaign['id'] ?? 'unknown'))
                    ->setSubject('recertification-review-required');
                $this->notificationManager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: recertification notification dispatch failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end dispatchReviewNotifications()

    /**
     * Process review decisions for a recertification campaign.
     *
     * @param string $campaignId The campaign object ID
     * @param array  $decisions  Array of decisions: [{userId, action: confirm|revoke}]
     *
     * @return array Summary of processed decisions
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.6
     */
    public function processReviewDecisions(string $campaignId, array $decisions): array
    {
        $objectService = $this->container->get(
            'OCA\OpenRegister\Service\ObjectService'
        );

        $processed = [];

        foreach ($decisions as $decision) {
            $userId = ($decision['userId'] ?? '');
            $action = ($decision['action'] ?? '');

            if ($action === 'revoke' && empty($userId) === false) {
                try {
                    $users = $objectService->findObjects(
                        register: Application::APP_ID,
                        schema: 'user',
                        filters: ['username' => $userId],
                    );

                    if (empty($users) === false) {
                        $user = $users[0];
                        $user['isActive'] = false;
                        $objectService->saveObject(
                            register: Application::APP_ID,
                            schema: 'user',
                            object: $user,
                        );

                        $this->auditLogService->log(
                            action: 'update',
                            resourceType: 'user',
                            resourceId: ($user['id'] ?? null),
                            result: 'success',
                            details: [
                                'reason'     => 'recertification-revoked',
                                'campaignId' => $campaignId,
                            ],
                        );

                        $notification = $this->notificationManager->createNotification();
                        $notification->setApp(Application::APP_ID)
                            ->setUser($userId)
                            ->setDateTime(new \DateTime())
                            ->setObject('accessRecertification', $campaignId)
                            ->setSubject('access-revoked');
                        $this->notificationManager->notify($notification);
                    }//end if
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Shillinq: recertification revoke failed',
                        [
                            'userId'    => $userId,
                            'exception' => $e->getMessage(),
                        ]
                    );
                }//end try
            }//end if

            $processed[] = [
                'userId' => $userId,
                'action' => $action,
                'status' => 'processed',
            ];
        }//end foreach

        return $processed;
    }//end processReviewDecisions()
}//end class
