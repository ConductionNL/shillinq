<?php

/**
 * Shillinq Recertification Service
 *
 * Manages access recertification campaigns and review decisions.
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
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\Notification\IManager as INotificationManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing access recertification campaigns.
 *
 * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
 */
class RecertificationService
{
    /**
     * Constructor for RecertificationService.
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
     * Dispatch review notifications to role-owners for a campaign.
     *
     * @param array $campaign The AccessRecertification campaign object
     *
     * @return void
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function dispatchReviewNotifications(array $campaign): void
    {
        try {
            $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

            $users = $objectService->findObjects(
                filters: ['isActive' => true],
                register: Application::APP_ID,
                schema: 'user',
            );

            foreach ($users as $user) {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setSubject('recertification_review', ['campaign' => $campaign['name']])
                    ->setUser($user['username'] ?? '')
                    ->setDateTime(new \DateTime())
                    ->setObject('accessRecertification', ($campaign['id'] ?? ''));
                $this->notificationManager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Shillinq: failed to dispatch recertification notifications',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end dispatchReviewNotifications()

    /**
     * Process review decisions from a recertification campaign.
     *
     * @param string $campaignId The campaign object ID
     * @param array  $decisions  Array of decisions: [{userId, action: 'confirm'|'revoke'}]
     *
     * @return array Summary of processed decisions
     *
     * @spec openspec/changes/access-control-authorisation/tasks.md#task-3
     */
    public function processReviewDecisions(string $campaignId, array $decisions): array
    {
        $objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');

        $confirmed = 0;
        $revoked   = 0;

        foreach ($decisions as $decision) {
            if ($decision['action'] === 'revoke') {
                // Deactivate the user.
                $users = $objectService->findObjects(
                    filters: ['id' => $decision['userId']],
                    register: Application::APP_ID,
                    schema: 'user',
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
                        resourceId: $decision['userId'],
                        result: 'success',
                        details: [
                            'recertificationCampaignId' => $campaignId,
                            'action'                    => 'revoked',
                        ],
                    );

                    $revoked++;
                }//end if
            } else {
                $confirmed++;
            }//end if
        }//end foreach

        return [
            'confirmed' => $confirmed,
            'revoked'   => $revoked,
        ];

    }//end processReviewDecisions()
}//end class
