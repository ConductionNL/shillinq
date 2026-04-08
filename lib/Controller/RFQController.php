<?php

/**
 * Shillinq RFQ Controller
 *
 * Controller for Request for Quotation lifecycle operations
 * including publishing to suppliers and awarding quotes.
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
 * Controller for Request for Quotation (RFQ) lifecycle management.
 *
 * Handles publishing RFQs to invited suppliers (with notifications)
 * and awarding a selected supplier quote, which rejects competing
 * quotes and creates a purchase order draft from the awarded quote.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.5
 */
class RFQController extends Controller
{
    /**
     * Constructor for the RFQController.
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
     * Publish an RFQ to invited suppliers.
     *
     * Reads supplierProfileIds from the request body, updates the RFQ
     * status to "published" with a publishedAt timestamp, stores the
     * invited supplier profile IDs, and sends a notification to each
     * invited supplier.
     *
     * @param string $id The RFQ ID
     *
     * @return JSONResponse The updated RFQ
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.5
     */
    public function publish(string $id): JSONResponse
    {
        try {
            $objectService      = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $supplierProfileIds = $this->request->getParam('supplierProfileIds', []);

            $rfq = $objectService->findOne(objectType: 'rfq', id: $id);
            if (is_array($rfq) === true) {
                $rfqData = $rfq;
            } else {
                $rfqData = $rfq->jsonSerialize();
            }

            // Update RFQ fields.
            $rfqData['status']      = 'published';
            $rfqData['publishedAt'] = (new \DateTime())->format('c');
            $rfqData['invitedSupplierProfileIds'] = $supplierProfileIds;

            $updated = $objectService->update(
                objectType: 'rfq',
                id: $id,
                object: $rfqData,
            );

            // Send notifications to each invited supplier.
            foreach ($supplierProfileIds as $supplierProfileId) {
                try {
                    $supplierProfile = $objectService->findOne(
                        objectType: 'supplierProfile',
                        id: $supplierProfileId,
                    );
                    if (is_array($supplierProfile) === true) {
                        $profileData = $supplierProfile;
                    } else {
                        $profileData = $supplierProfile->jsonSerialize();
                    }

                    $contactUserId = $profileData['contactUserId'] ?? null;

                    if ($contactUserId !== null) {
                        $notification = $this->notificationManager->createNotification();
                        $notification->setApp(Application::APP_ID)
                            ->setUser($contactUserId)
                            ->setDateTime(new \DateTime())
                            ->setObject('rfq', 'rfq-published-'.$id.'-'.$supplierProfileId)
                            ->setSubject(
                                'rfq_published',
                                [
                                    'rfqId'    => $id,
                                    'rfqTitle' => ($rfqData['title'] ?? $id),
                                ]
                            );

                        $this->notificationManager->notify($notification);
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                            'RFQController::publish: failed to notify supplier',
                            [
                                'supplierProfileId' => $supplierProfileId,
                                'exception'         => $e,
                            ]
                            );
                }//end try
            }//end foreach

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
                    'RFQController::publish failed',
                    [
                        'rfqId'     => $id,
                        'exception' => $e,
                    ]
                    );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end publish()

    /**
     * Award an RFQ to a selected supplier quote.
     *
     * Reads the supplierQuoteId from the request body, updates the RFQ
     * status to "awarded", accepts the winning quote, rejects all other
     * quotes for this RFQ, and creates a purchase order draft from the
     * awarded quote data.
     *
     * @param string $id The RFQ ID
     *
     * @return JSONResponse The award result including the created PO draft
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.5
     */
    public function award(string $id): JSONResponse
    {
        try {
            $objectService   = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $user            = $this->userSession->getUser();
            $supplierQuoteId = $this->request->getParam('supplierQuoteId');

            if (empty($supplierQuoteId) === true) {
                return new JSONResponse(
                    data: ['error' => 'supplierQuoteId is required'],
                    statusCode: 400,
                );
            }

            // Update RFQ status to awarded.
            $rfq = $objectService->findOne(objectType: 'rfq', id: $id);
            if (is_array($rfq) === true) {
                $rfqData = $rfq;
            } else {
                $rfqData = $rfq->jsonSerialize();
            }

            $rfqData['status']         = 'awarded';
            $rfqData['awardedQuoteId'] = $supplierQuoteId;
            $rfqData['awardedAt']      = (new \DateTime())->format('c');

            $objectService->update(
                objectType: 'rfq',
                id: $id,
                object: $rfqData,
            );

            // Accept the winning quote.
            $winningQuote = $objectService->findOne(objectType: 'supplierQuote', id: $supplierQuoteId);
            if (is_array($winningQuote) === true) {
                $winningQuoteData = $winningQuote;
            } else {
                $winningQuoteData = $winningQuote->jsonSerialize();
            }

            $winningQuoteData['status'] = 'accepted';
            $objectService->update(
                objectType: 'supplierQuote',
                id: $supplierQuoteId,
                object: $winningQuoteData,
            );

            // Reject all other quotes for this RFQ.
            $allQuotes = $objectService->findAll(
                objectType: 'supplierQuote',
                filters: ['rfqId' => $id],
            );

            foreach ($allQuotes as $quote) {
                if (is_array($quote) === true) {
                    $quoteData = $quote;
                } else {
                    $quoteData = $quote->jsonSerialize();
                }

                $quoteId = $quoteData['id'] ?? $quoteData['uuid'] ?? null;

                if ($quoteId !== null && $quoteId !== $supplierQuoteId) {
                    $quoteData['status'] = 'rejected';
                    $objectService->update(
                        objectType: 'supplierQuote',
                        id: $quoteId,
                        object: $quoteData,
                    );
                }
            }

            // Create PurchaseOrder draft from the awarded quote.
            if ($user !== null) {
                $createdBy = $user->getUID();
            } else {
                $createdBy = null;
            }

            $purchaseOrder = $objectService->create(
                objectType: 'purchaseOrder',
                object: [
                    'rfqId'           => $id,
                    'supplierQuoteId' => $supplierQuoteId,
                    'supplierId'      => ($winningQuoteData['supplierId'] ?? null),
                    'status'          => 'draft',
                    'createdBy'       => $createdBy,
                    'createdAt'       => (new \DateTime())->format('c'),
                    'items'           => ($winningQuoteData['items'] ?? []),
                    'totalAmount'     => ($winningQuoteData['totalAmount'] ?? 0),
                ],
            );

            if (is_array($purchaseOrder) === true) {
                $purchaseOrderData = $purchaseOrder;
            } else {
                $purchaseOrderData = $purchaseOrder->jsonSerialize();
            }

            return new JSONResponse(
                    data: [
                        'rfqId'         => $id,
                        'status'        => 'awarded',
                        'awardedQuote'  => $winningQuoteData,
                        'purchaseOrder' => $purchaseOrderData,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'RFQController::award failed',
                    [
                        'rfqId'     => $id,
                        'exception' => $e,
                    ]
                    );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end award()
}//end class
