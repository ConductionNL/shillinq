<?php

/**
 * Shillinq Overdue Delivery Background Job
 *
 * Timed background job that checks for purchase orders with overdue
 * expected delivery dates and sends notifications to the creating user.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
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

namespace OCA\Shillinq\BackgroundJob;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Timed background job that detects overdue purchase orders and notifies the creator.
 *
 * Runs once every 24 hours (86 400 seconds). For each purchase order whose
 * expectedDeliveryDate is in the past and whose status is still submitted,
 * acknowledged, or partially_received, a Nextcloud notification is created
 * for the user identified by the createdBy field.
 *
 * Deduplication prevents the same notification from being sent more than once
 * per calendar day per purchase order.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-4.1
 */
class OverdueDeliveryJob extends TimedJob
{

    /**
     * Statuses that qualify a purchase order as potentially overdue.
     *
     * @var array<string>
     */
    private const OVERDUE_STATUSES = [
        'submitted',
        'acknowledged',
        'partially_received',
    ];

    /**
     * Constructor for the OverdueDeliveryJob.
     *
     * @param ITimeFactory       $time                The time factory
     * @param ContainerInterface $container           The DI container
     * @param IManager           $notificationManager The notification manager
     * @param LoggerInterface    $logger              The logger
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private ContainerInterface $container,
        private IManager $notificationManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(interval: 86400);
    }//end __construct()

    /**
     * Execute the overdue delivery check.
     *
     * Queries all purchase orders with an expected delivery date in the past
     * and a qualifying status, then creates a notification for each overdue
     * order if one has not already been sent today.
     *
     * @param mixed $argument The job argument (unused)
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-4.1
     */
    protected function run($argument): void
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error('OverdueDeliveryJob: unable to get ObjectService', ['exception' => $e]);
            return;
        }

        $now   = new \DateTime();
        $today = $now->format('Y-m-d');

        try {
            $purchaseOrders = $objectService->findAll(
                objectType: 'purchaseOrder',
                filters: [
                    'status' => self::OVERDUE_STATUSES,
                ],
            );
        } catch (\Exception $e) {
            $this->logger->error('OverdueDeliveryJob: failed to query purchase orders', ['exception' => $e]);
            return;
        }

        foreach ($purchaseOrders as $po) {
            if (is_array($po) === true) {
                $poData = $po;
            } else {
                $poData = $po->jsonSerialize();
            }

            $expectedDate = $poData['expectedDeliveryDate'] ?? null;
            if ($expectedDate === null) {
                continue;
            }

            try {
                $expectedDateTime = new \DateTime($expectedDate);
            } catch (\Exception $e) {
                $this->logger->warning(
                        'OverdueDeliveryJob: invalid date for PO',
                        [
                            'poNumber' => ($poData['poNumber'] ?? 'unknown'),
                            'date'     => $expectedDate,
                        ]
                        );
                continue;
            }

            if ($expectedDateTime >= $now) {
                continue;
            }

            $poNumber         = $poData['poNumber'] ?? 'unknown';
            $createdBy        = $poData['createdBy'] ?? null;
            $deduplicationKey = 'po-overdue-'.$poNumber.'-'.$today;

            if ($createdBy === null) {
                $this->logger->warning(
                        'OverdueDeliveryJob: no createdBy for PO',
                        [
                            'poNumber' => $poNumber,
                        ]
                        );
                continue;
            }

            // Check if notification already exists for today.
            $existingNotification = $this->notificationManager->createNotification();
            $existingNotification->setApp(Application::APP_ID)
                ->setUser($createdBy)
                ->setObject('purchaseOrder', $deduplicationKey);

            if ($this->notificationManager->getCount($existingNotification) > 0) {
                continue;
            }

            // Create notification.
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($createdBy)
                ->setDateTime($now)
                ->setObject('purchaseOrder', $deduplicationKey)
                ->setSubject(
                    'overdue_delivery',
                    [
                        'poNumber' => $poNumber,
                        'date'     => $expectedDate,
                    ]
                )
                ->setMessage(
                    'overdue_delivery',
                    [
                        'message' => "Purchase Order {$poNumber} is overdue — expected delivery was {$expectedDate}",
                    ]
                );

            $this->notificationManager->notify($notification);

            $this->logger->info(
                    'OverdueDeliveryJob: notification sent',
                    [
                        'poNumber' => $poNumber,
                        'user'     => $createdBy,
                    ]
                    );
        }//end foreach
    }//end run()
}//end class
