<?php

/**
 * Shillinq Purchase Order Controller
 *
 * Controller for purchase order lifecycle operations including
 * submission, cancellation, and delivery reminders.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
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

namespace OCA\Shillinq\Controller;

use OCA\Shillinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Notification\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for purchase order lifecycle management.
 *
 * Handles submission (status change + transmission timestamp),
 * cancellation (with mandatory reason), and delivery reminder
 * notifications for purchase orders.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.3
 */
class PurchaseOrderController extends Controller
{
    /**
     * Constructor for the PurchaseOrderController.
     *
     * @param IRequest           $request             The request object
     * @param ContainerInterface $container           The DI container
     * @param IManager           $notificationManager The notification manager
     * @param IUserSession       $userSession         The user session
     * @param LoggerInterface    $logger              The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ContainerInterface $container,
        private IManager $notificationManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Submit a purchase order to the supplier.
     *
     * Changes the order status to "submitted", records the transmission
     * timestamp, and sends a notification to the supplier.
     *
     * @param string $id The purchase order ID
     *
     * @return JSONResponse The updated purchase order
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.3
     */
    public function submit(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $po = $objectService->findOne(objectType: 'purchaseOrder', id: $id);
            if (is_array($po) === true) {
                $poData = $po;
            } else {
                $poData = $po->jsonSerialize();
            }

            // Update status and transmission timestamp.
            $poData['status']        = 'submitted';
            $poData['transmittedAt'] = (new \DateTime())->format('c');

            $updated = $objectService->update(
                objectType: 'purchaseOrder',
                id: $id,
                object: $poData,
            );

            // Send notification to supplier contact.
            $supplierContact = $poData['supplierContactId'] ?? $poData['createdBy'] ?? null;
            if ($supplierContact !== null) {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($supplierContact)
                    ->setDateTime(new \DateTime())
                    ->setObject('purchaseOrder', 'po-submitted-'.$id)
                    ->setSubject(
                        'po_submitted',
                        [
                            'poNumber' => ($poData['poNumber'] ?? $id),
                        ]
                    );

                $this->notificationManager->notify($notification);
            }

            if (is_array($updated) === true) {
                $updatedData = $updated;
            } else {
                $updatedData = $updated->jsonSerialize();
            }

            return new JSONResponse(
                data: $updatedData,
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    'PurchaseOrderController::submit failed',
                    [
                        'purchaseOrderId' => $id,
                        'exception'       => $e,
                    ]
                    );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end submit()

    /**
     * Cancel a purchase order with a mandatory reason.
     *
     * Requires a non-empty `reason` field in the request body.
     * Returns 422 if the reason is missing or empty.
     * Notifies the supplier of the cancellation.
     *
     * @param string $id The purchase order ID
     *
     * @return JSONResponse The cancelled purchase order
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.3
     */
    public function cancel(string $id): JSONResponse
    {
        try {
            $reason = $this->request->getParam('reason', '');

            if (empty(trim($reason)) === true) {
                return new JSONResponse(
                    data: ['error' => 'A cancellation reason is required'],
                    statusCode: 422,
                );
            }

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $po = $objectService->findOne(objectType: 'purchaseOrder', id: $id);
            if (is_array($po) === true) {
                $poData = $po;
            } else {
                $poData = $po->jsonSerialize();
            }

            $poData['status'] = 'cancelled';
            $poData['cancellationReason'] = $reason;
            $poData['cancelledAt']        = (new \DateTime())->format('c');

            $updated = $objectService->update(
                objectType: 'purchaseOrder',
                id: $id,
                object: $poData,
            );

            // Notify supplier of cancellation.
            $supplierContact = $poData['supplierContactId'] ?? $poData['createdBy'] ?? null;
            if ($supplierContact !== null) {
                $notification = $this->notificationManager->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($supplierContact)
                    ->setDateTime(new \DateTime())
                    ->setObject('purchaseOrder', 'po-cancelled-'.$id)
                    ->setSubject(
                        'po_cancelled',
                        [
                            'poNumber' => ($poData['poNumber'] ?? $id),
                            'reason'   => $reason,
                        ]
                    );

                $this->notificationManager->notify($notification);
            }

            if (is_array($updated) === true) {
                $updatedData = $updated;
            } else {
                $updatedData = $updated->jsonSerialize();
            }

            return new JSONResponse(
                data: $updatedData,
            );
        } catch (\Exception $e) {
            $this->logger->error(
                    'PurchaseOrderController::cancel failed',
                    [
                        'purchaseOrderId' => $id,
                        'exception'       => $e,
                    ]
                    );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end cancel()

    /**
     * Send a delivery reminder notification to the supplier.
     *
     * Creates a Nextcloud notification reminding the supplier about
     * the expected delivery for the given purchase order.
     *
     * @param string $id The purchase order ID
     *
     * @return JSONResponse Confirmation of reminder sent
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.3
     */
    public function sendReminder(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $po = $objectService->findOne(objectType: 'purchaseOrder', id: $id);
            if (is_array($po) === true) {
                $poData = $po;
            } else {
                $poData = $po->jsonSerialize();
            }

            $supplierContact = $poData['supplierContactId'] ?? $poData['createdBy'] ?? null;
            if ($supplierContact === null) {
                return new JSONResponse(
                    data: ['error' => 'No supplier contact found for this purchase order'],
                    statusCode: 422,
                );
            }

            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($supplierContact)
                ->setDateTime(new \DateTime())
                ->setObject('purchaseOrder', 'po-reminder-'.$id.'-'.date('Y-m-d'))
                ->setSubject(
                    'delivery_reminder',
                    [
                        'poNumber'             => ($poData['poNumber'] ?? $id),
                        'expectedDeliveryDate' => ($poData['expectedDeliveryDate'] ?? 'unknown'),
                    ]
                );

            $this->notificationManager->notify($notification);

            return new JSONResponse(data: ['status' => 'reminder_sent']);
        } catch (\Exception $e) {
            $this->logger->error(
                    'PurchaseOrderController::sendReminder failed',
                    [
                        'purchaseOrderId' => $id,
                        'exception'       => $e,
                    ]
                    );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end sendReminder()
}//end class
