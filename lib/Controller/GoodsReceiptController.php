<?php

/**
 * Shillinq Goods Receipt Controller
 *
 * Controller for goods receipt creation and three-way matching.
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
use OCA\Shillinq\Service\ThreeWayMatchingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for creating goods receipts and triggering three-way matching.
 *
 * Handles the creation of goods receipt records with their line items,
 * validates that the associated purchase order is not closed, triggers
 * three-way matching, and updates the purchase order status accordingly.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.4
 */
class GoodsReceiptController extends Controller
{
    /**
     * Constructor for the GoodsReceiptController.
     *
     * @param IRequest                $request         The request object
     * @param ThreeWayMatchingService $matchingService The three-way matching service
     * @param ContainerInterface      $container       The DI container
     * @param IUserSession            $userSession     The user session
     * @param LoggerInterface         $logger          The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private ThreeWayMatchingService $matchingService,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Create a goods receipt and trigger three-way matching.
     *
     * Reads the receipt and line items from the request body, validates
     * the associated purchase order is not closed (returns 422 if it is),
     * creates the GoodsReceipt and GoodsReceiptLine objects, runs
     * three-way matching, and updates the purchase order status to
     * partially_received or received.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The created goods receipt with match results
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.4
     */
    public function create(): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $user          = $this->userSession->getUser();

            $receipt = $this->request->getParam('receipt', []);
            $lines   = $this->request->getParam('lines', []);

            $purchaseOrderId = $receipt['purchaseOrderId'] ?? null;
            if ($purchaseOrderId === null) {
                return new JSONResponse(
                    data: ['error' => 'purchaseOrderId is required'],
                    statusCode: 400,
                );
            }

            // Validate PO is not closed.
            $po = $objectService->findOne(objectType: 'purchaseOrder', id: $purchaseOrderId);
            if (is_array($po) === true) {
                $poData = $po;
            } else {
                $poData = $po->jsonSerialize();
            }

            if (($poData['status'] ?? '') === 'closed') {
                return new JSONResponse(
                    data: ['error' => 'Cannot create goods receipt for a closed purchase order'],
                    statusCode: 422,
                );
            }

            // Create the GoodsReceipt object.
            if ($user !== null) {
                $receivedBy = $user->getUID();
            } else {
                $receivedBy = null;
            }

            $goodsReceipt = $objectService->create(
                objectType: 'goodsReceipt',
                object: array_merge(
                        $receipt,
                        [
                            'receivedBy' => $receivedBy,
                            'receivedAt' => (new \DateTime())->format('c'),
                            'status'     => 'received',
                        ]
                        ),
            );

            if (is_array($goodsReceipt) === true) {
                $receiptData = $goodsReceipt;
            } else {
                $receiptData = $goodsReceipt->jsonSerialize();
            }

            $receiptId = $receiptData['id'] ?? $receiptData['uuid'] ?? null;

            // Create GoodsReceiptLine objects.
            $createdLines = [];
            foreach ($lines as $line) {
                $createdLine = $objectService->create(
                    objectType: 'goodsReceiptLine',
                    object: array_merge(
                            $line,
                            [
                                'goodsReceiptId' => $receiptId,
                            ]
                            ),
                );
                if (is_array($createdLine) === true) {
                    $createdLines[] = $createdLine;
                } else {
                    $createdLines[] = $createdLine->jsonSerialize();
                }
            }

            // Run three-way matching.
            $matchResults = $this->matchingService->match(
                purchaseOrderId: $purchaseOrderId,
            );

            // Determine and update PO status based on match results.
            if (($matchResults['fullyReceived'] ?? false) === true) {
                $newStatus = 'received';
            } else {
                $newStatus = 'partially_received';
            }

            $poData['status'] = $newStatus;
            $objectService->update(
                objectType: 'purchaseOrder',
                id: $purchaseOrderId,
                object: $poData,
            );

            return new JSONResponse(
                    data: [
                        'goodsReceipt'        => $receiptData,
                        'lines'               => $createdLines,
                        'matchResults'        => $matchResults,
                        'purchaseOrderStatus' => $newStatus,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error('GoodsReceiptController::create failed', ['exception' => $e]);
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end create()
}//end class
