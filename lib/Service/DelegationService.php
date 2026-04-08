<?php

/**
 * Shillinq Delegation Service
 *
 * Manages time-limited role delegations (AccessRight objects).
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.5
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
 * Service for creating and revoking access right delegations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.5
 */
class DelegationService
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
     * Create a new time-limited delegation.
     *
     * @param string    $userId    The grantee user ID
     * @param string    $roleId    The delegated role ID
     * @param string    $grantedBy The admin user ID
     * @param \DateTime $start     The delegation start date
     * @param \DateTime $end       The delegation end date
     * @param string    $reason    The business justification
     *
     * @return array The created AccessRight object
     *
     * @throws \InvalidArgumentException If endDate <= startDate
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.5
     */
    public function createDelegation(
        string $userId,
        string $roleId,
        string $grantedBy,
        \DateTime $start,
        \DateTime $end,
        string $reason,
    ): array {
        if ($end <= $start) {
            throw new \InvalidArgumentException(
                'End date must be after start date'
            );
        }

        $objectService = $this->container->get(
            'OCA\OpenRegister\Service\ObjectService'
        );

        $accessRight = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'accessRight',
            object: [
                'userId'    => $userId,
                'roleId'    => $roleId,
                'grantedBy' => $grantedBy,
                'startDate' => $start->format('c'),
                'endDate'   => $end->format('c'),
                'isActive'  => true,
                'reason'    => $reason,
            ],
        );

        $this->auditLogService->log(
            action: 'delegation-created',
            resourceType: 'accessRight',
            resourceId: ($accessRight['id'] ?? null),
            result: 'success',
            details: [
                'userId'    => $userId,
                'roleId'    => $roleId,
                'grantedBy' => $grantedBy,
            ],
        );

        $this->sendDelegationNotification(
            userId: $userId,
            grantedBy: $grantedBy,
            subject: 'delegation-created',
        );

        return $accessRight;
    }//end createDelegation()

    /**
     * Revoke an existing delegation by setting isActive to false.
     *
     * @param string $accessRightId The access right object ID
     * @param string $revokedBy     The user performing the revocation
     *
     * @return array The updated AccessRight object
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3.5
     */
    public function revokeDelegation(string $accessRightId, string $revokedBy): array
    {
        $objectService = $this->container->get(
            'OCA\OpenRegister\Service\ObjectService'
        );

        $accessRight = $objectService->getObject(
            register: Application::APP_ID,
            schema: 'accessRight',
            id: $accessRightId,
        );

        $accessRight['isActive'] = false;

        $updated = $objectService->saveObject(
            register: Application::APP_ID,
            schema: 'accessRight',
            object: $accessRight,
        );

        $this->auditLogService->log(
            action: 'delegation-revoked',
            resourceType: 'accessRight',
            resourceId: $accessRightId,
            result: 'success',
            details: ['revokedBy' => $revokedBy],
        );

        $this->sendDelegationNotification(
            userId: ($accessRight['userId'] ?? ''),
            grantedBy: $revokedBy,
            subject: 'delegation-revoked',
        );

        return $updated;
    }//end revokeDelegation()

    /**
     * Send a delegation notification to the grantee and admin.
     *
     * @param string $userId    The grantee user ID
     * @param string $grantedBy The admin user ID
     * @param string $subject   The notification subject
     *
     * @return void
     */
    private function sendDelegationNotification(
        string $userId,
        string $grantedBy,
        string $subject,
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('accessRight', $subject)
                ->setSubject($subject);
            $this->notificationManager->notify($notification);

            if ($grantedBy !== $userId) {
                $adminNotification = $this->notificationManager->createNotification();
                $adminNotification->setApp(Application::APP_ID)
                    ->setUser($grantedBy)
                    ->setDateTime(new \DateTime())
                    ->setObject('accessRight', $subject)
                    ->setSubject($subject);
                $this->notificationManager->notify($adminNotification);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Shillinq: delegation notification failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end sendDelegationNotification()
}//end class
