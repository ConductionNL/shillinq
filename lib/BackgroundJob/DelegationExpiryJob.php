<?php

/**
 * Shillinq Delegation Expiry Background Job
 *
 * Automatically revokes expired access delegations.
 *
 * @category  BackgroundJob
 * @package   OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4
 */

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
 * Background job that revokes expired access delegations every 5 minutes.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-4
 */
class DelegationExpiryJob extends TimedJob
{


    /**
     * Constructor for DelegationExpiryJob.
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
        parent::__construct($time);
        // Run every 5 minutes.
        $this->setInterval(300);

    }//end __construct()


    /**
     * Execute the delegation expiry check.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-4
     */
    protected function run($argument): void
    {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

            $activeRights = $objectService->findObjects(
                filters: ['isActive' => true],
                register: Application::APP_ID,
                schema: 'accessRight',
            );

            $now = new \DateTime();

            foreach ($activeRights as $right) {
                if (isset($right['endDate']) === false) {
                    continue;
                }

                $endDate = new \DateTime($right['endDate']);
                if ($endDate >= $now) {
                    continue;
                }

                // Expire this delegation.
                $right['isActive'] = false;
                $objectService->saveObject(
                    register: Application::APP_ID,
                    schema: 'accessRight',
                    object: $right,
                );

                $this->auditLogService->log(
                    action: 'delegation-revoked',
                    resourceType: 'accessRight',
                    resourceId: ($right['id'] ?? null),
                    result: 'success',
                    details: ['reason' => 'expired', 'endDate' => $right['endDate']],
                );

                // Notify grantee and admin.
                $this->sendExpiryNotification($right['userId'] ?? '', $right['grantedBy'] ?? '');

                $this->logger->info(
                    'Shillinq: expired delegation revoked',
                    ['accessRightId' => ($right['id'] ?? 'unknown')]
                );
            }//end foreach
        } catch (\Throwable $e) {
            $this->logger->error('Shillinq: DelegationExpiryJob failed', ['exception' => $e->getMessage()]);
        }//end try

    }//end run()


    /**
     * Send expiry notifications to grantee and admin.
     *
     * @param string $userId    The grantee user ID
     * @param string $grantedBy The admin user ID
     *
     * @return void
     */
    private function sendExpiryNotification(string $userId, string $grantedBy): void
    {
        try {
            foreach ([$userId, $grantedBy] as $recipient) {
                if (empty($recipient) === true) {
                    continue;
                }

                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setSubject('delegation_expired')
                    ->setUser($recipient)
                    ->setDateTime(new \DateTime())
                    ->setObject('accessRight', $userId);
                $this->notificationManager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Shillinq: failed to send expiry notification', ['exception' => $e->getMessage()]);
        }

    }//end sendExpiryNotification()


}//end class
