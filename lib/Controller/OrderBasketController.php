<?php

/**
 * Shillinq Order Basket Controller
 *
 * Controller for order basket submission and approval workflow.
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
use OCA\Shillinq\Service\OrderLimitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for submitting order baskets for approval or auto-approval.
 *
 * Handles the submission of order baskets, including requisitioner assignment,
 * order limit checks, budget validation, and routing through the approval
 * workflow or auto-approval for orders within limits.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.2
 */
class OrderBasketController extends Controller
{
    /**
     * Constructor for the OrderBasketController.
     *
     * @param IRequest           $request      The request object
     * @param OrderLimitService  $limitService The order limit service
     * @param ContainerInterface $container    The DI container
     * @param IUserSession       $userSession  The user session
     * @param LoggerInterface    $logger       The logger
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private OrderLimitService $limitService,
        private ContainerInterface $container,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Submit an order basket for approval or auto-approval.
     *
     * Loads the basket, assigns the authenticated user as requisitioner,
     * checks order limits, and either creates an ApprovalWorkflow object
     * or auto-approves the order by creating a PurchaseOrder draft.
     * Budget availability is also validated.
     *
     * @param string $id The order basket ID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The submission result
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-5.2
     */
    public function submit(string $id): JSONResponse
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $user          = $this->userSession->getUser();

            if ($user === null) {
                return new JSONResponse(
                    data: ['error' => 'Authentication required'],
                    statusCode: 401,
                );
            }

            // Load the basket.
            $basket = $objectService->findOne(objectType: 'orderBasket', id: $id);
            if (is_array($basket) === true) {
                $basketData = $basket;
            } else {
                $basketData = $basket->jsonSerialize();
            }

            // Set requisitionerId to authenticated user.
            $basketData['requisitionerId'] = $user->getUID();

            // Check order limits.
            $limitCheck = $this->limitService->check(
                userId: $user->getUID(),
                basket: $basketData,
            );

            if ($limitCheck['requiresApproval'] === true) {
                // Create an ApprovalWorkflow object.
                $approvalWorkflow = $objectService->create(
                    objectType: 'approvalWorkflow',
                    object: [
                        'orderBasketId'   => $id,
                        'requisitionerId' => $user->getUID(),
                        'status'          => 'pending',
                        'createdAt'       => (new \DateTime())->format('c'),
                        'totalAmount'     => ($basketData['totalAmount'] ?? 0),
                    ],
                );

                // Update basket status.
                $basketData['status'] = 'pending_approval';
                $objectService->update(
                    objectType: 'orderBasket',
                    id: $id,
                    object: $basketData,
                );

                if (is_array($approvalWorkflow) === true) {
                    $approvalWorkflowData = $approvalWorkflow;
                } else {
                    $approvalWorkflowData = $approvalWorkflow->jsonSerialize();
                }

                return new JSONResponse(
                        data: [
                            'status'           => 'pending_approval',
                            'approvalWorkflow' => $approvalWorkflowData,
                        ]
                        );
            }//end if

            // Auto-approve: create PurchaseOrder draft.
            $purchaseOrder = $objectService->create(
                objectType: 'purchaseOrder',
                object: [
                    'orderBasketId'   => $id,
                    'requisitionerId' => $user->getUID(),
                    'status'          => 'draft',
                    'createdBy'       => $user->getUID(),
                    'createdAt'       => (new \DateTime())->format('c'),
                    'items'           => ($basketData['items'] ?? []),
                    'totalAmount'     => ($basketData['totalAmount'] ?? 0),
                    'supplierId'      => ($basketData['supplierId'] ?? null),
                ],
            );

            // Update basket status.
            $basketData['status'] = 'approved';
            $objectService->update(
                objectType: 'orderBasket',
                id: $id,
                object: $basketData,
            );

            if (is_array($purchaseOrder) === true) {
                $purchaseOrderData = $purchaseOrder;
            } else {
                $purchaseOrderData = $purchaseOrder->jsonSerialize();
            }

            return new JSONResponse(
                    data: [
                        'status'        => 'auto_approved',
                        'purchaseOrder' => $purchaseOrderData,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    'OrderBasketController::submit failed',
                    [
                        'basketId'  => $id,
                        'exception' => $e,
                    ]
                    );
            return new JSONResponse(
                data: ['error' => $e->getMessage()],
                statusCode: 500,
            );
        }//end try
    }//end submit()
}//end class
