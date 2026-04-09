<?php

/**
 * Shillinq Delegation Service
 *
 * Manages time-limited access delegations.
 *
 * @category  Service
 * @package   OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for creating and revoking time-limited access delegations.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class DelegationService
{


    /**
     * Constructor for DelegationService.
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
     * Create a time-limited delegation.
     *
     * @param string    $userId    The grantee user object ID
     * @param string    $roleId    The delegated role object ID
     * @param string    $grantedBy The admin user object ID
     * @param \DateTime $start     The delegation start date
     * @param \DateTime $end       The delegation end date
     * @param string    $reason    The business justification
     *
     * @return array The created AccessRight object
     *
     * @throws \InvalidArgumentException If endDate is before or equal to startDate
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
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
            throw new \InvalidArgumentException('End date must be after start date');
        }

        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

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

        $this->sendDelegationNotification($userId, $grantedBy, 'delegation_created');

        return $accessRight;

    }//end createDelegation()


    /**
     * Revoke an active delegation.
     *
     * @param string $accessRightId The AccessRight object ID
     *
     * @return array The updated AccessRight object
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function revokeDelegation(string $accessRightId): array
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        $objects = $objectService->findObjects(
            filters: ['id' => $accessRightId],
            register: Application::APP_ID,
            schema: 'accessRight',
        );

        if (empty($objects) === true) {
            throw new \RuntimeException('AccessRight not found: '.$accessRightId);
        }

        $accessRight = $objects[0];
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
        );

        $this->sendDelegationNotification(
            $accessRight['userId'],
            $accessRight['grantedBy'],
            'delegation_revoked'
        );

        return $updated;

    }//end revokeDelegation()


    /**
     * Send a notification about a delegation event.
     *
     * @param string $userId    The grantee user ID
     * @param string $grantedBy The admin user ID
     * @param string $subject   The notification subject key
     *
     * @return void
     */
    private function sendDelegationNotification(string $userId, string $grantedBy, string $subject): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setSubject($subject)
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject('accessRight', $grantedBy);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: failed to send delegation notification', ['exception' => $e->getMessage()]);
        }

    }//end sendDelegationNotification()


}//end class
