<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Shillinq Three-Way Matching Service
 *
 * Performs three-way matching between purchase order lines, goods receipt lines,
 * and invoice lines to detect quantity discrepancies.
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
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for three-way matching of purchase orders, goods receipts, and invoices.
 *
 * For each purchase order line, compares the ordered quantity against received
 * (from goods receipt lines) and invoiced (from invoice lines) quantities.
 * Updates match status on each line and returns a discrepancy report.
 * This operation is idempotent.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
 */
class ThreeWayMatchingService
{

    /**
     * Constructor for the ThreeWayMatchingService.
     *
     * @param ContainerInterface $container The service container
     * @param LoggerInterface    $logger    The logger
     *
     * @return void
     */
    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Perform three-way matching for all lines of a purchase order.
     *
     * For each PurchaseOrderLine belonging to the given purchaseOrderId:
     * - Sums receivedQuantity from all matching GoodsReceiptLine objects
     * - Sums invoicedQuantity from all matching invoiceLine objects
     * - Sets matchStatus to 'matched' when ordered == received == invoiced
     * - Sets matchStatus to 'discrepancy' with a reason when values differ
     * - Updates the PurchaseOrderLine with matchStatus, receivedQuantity, invoicedQuantity
     *
     * This method is idempotent: calling it multiple times yields the same result.
     *
     * @param string $purchaseOrderId The ID of the purchase order to match
     *
     * @return array<int,array<string,mixed>> Array of discrepancy report entries
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.3
     */
    public function match(string $purchaseOrderId): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('ThreeWayMatchingService: unable to get ObjectService', ['exception' => $e->getMessage()]);
            return [];
        }

        // Fetch all purchase order lines for this order.
        try {
            $orderLines = $objectService->getObjects(
                objectType: 'purchaseOrderLine',
                filters: ['purchaseOrderId' => $purchaseOrderId],
            );
        } catch (\Throwable $e) {
            $this->logger->error('ThreeWayMatchingService: failed to fetch order lines', [
                'purchaseOrderId' => $purchaseOrderId,
                'exception'       => $e->getMessage(),
            ]);
            return [];
        }

        $discrepancies = [];

        foreach ($orderLines as $orderLine) {
            $lineId         = ($orderLine['id'] ?? null);
            $orderedQuantity = (float) ($orderLine['quantity'] ?? 0);

            if ($lineId === null) {
                continue;
            }

            // Sum received quantities from goods receipt lines.
            $receivedQuantity = $this->sumGoodsReceiptQuantity(
                objectService: $objectService,
                purchaseOrderLineId: $lineId,
            );

            // Sum invoiced quantities from invoice lines.
            $invoicedQuantity = $this->sumInvoiceLineQuantity(
                objectService: $objectService,
                purchaseOrderLineId: $lineId,
            );

            // Determine match status.
            $matchStatus = 'matched';
            $reason      = '';

            if ($orderedQuantity !== $receivedQuantity || $orderedQuantity !== $invoicedQuantity) {
                $matchStatus = 'discrepancy';
                $reasons     = [];

                if ($orderedQuantity !== $receivedQuantity) {
                    $reasons[] = "ordered={$orderedQuantity} received={$receivedQuantity}";
                }

                if ($orderedQuantity !== $invoicedQuantity) {
                    $reasons[] = "ordered={$orderedQuantity} invoiced={$invoicedQuantity}";
                }

                if ($receivedQuantity !== $invoicedQuantity) {
                    $reasons[] = "received={$receivedQuantity} invoiced={$invoicedQuantity}";
                }

                $reason = implode(separator: '; ', array: $reasons);
            }

            // Update the purchase order line with match results.
            try {
                $objectService->updateObject(
                    objectType: 'purchaseOrderLine',
                    id: $lineId,
                    data: [
                        'matchStatus'      => $matchStatus,
                        'receivedQuantity'  => $receivedQuantity,
                        'invoicedQuantity'  => $invoicedQuantity,
                    ],
                );
            } catch (\Throwable $e) {
                $this->logger->error('ThreeWayMatchingService: failed to update order line', [
                    'lineId'    => $lineId,
                    'exception' => $e->getMessage(),
                ]);
            }

            // Add to discrepancy report if not matched.
            if ($matchStatus === 'discrepancy') {
                $discrepancies[] = [
                    'purchaseOrderLineId' => $lineId,
                    'orderedQuantity'     => $orderedQuantity,
                    'receivedQuantity'    => $receivedQuantity,
                    'invoicedQuantity'    => $invoicedQuantity,
                    'matchStatus'         => $matchStatus,
                    'reason'              => $reason,
                ];
            }
        }//end foreach

        $this->logger->info('ThreeWayMatchingService: matching completed', [
            'purchaseOrderId' => $purchaseOrderId,
            'totalLines'      => count($orderLines),
            'discrepancies'   => count($discrepancies),
        ]);

        return $discrepancies;
    }//end match()

    /**
     * Sum received quantities from goods receipt lines for a purchase order line.
     *
     * @param object $objectService       The OpenRegister object service
     * @param string $purchaseOrderLineId The purchase order line ID
     *
     * @return float The total received quantity
     */
    private function sumGoodsReceiptQuantity(object $objectService, string $purchaseOrderLineId): float
    {
        $total = 0.0;

        try {
            $receiptLines = $objectService->getObjects(
                objectType: 'goodsReceiptLine',
                filters: ['purchaseOrderLineId' => $purchaseOrderLineId],
            );

            foreach ($receiptLines as $receiptLine) {
                $total += (float) ($receiptLine['receivedQuantity'] ?? 0);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('ThreeWayMatchingService: failed to fetch goods receipt lines', [
                'purchaseOrderLineId' => $purchaseOrderLineId,
                'exception'           => $e->getMessage(),
            ]);
        }

        return $total;
    }//end sumGoodsReceiptQuantity()

    /**
     * Sum invoiced quantities from invoice lines for a purchase order line.
     *
     * Fetches linked invoice lines via OpenRegister relation using the
     * 'invoiceLine' schema filtered by purchaseOrderLineId.
     *
     * @param object $objectService       The OpenRegister object service
     * @param string $purchaseOrderLineId The purchase order line ID
     *
     * @return float The total invoiced quantity
     */
    private function sumInvoiceLineQuantity(object $objectService, string $purchaseOrderLineId): float
    {
        $total = 0.0;

        try {
            $invoiceLines = $objectService->getObjects(
                objectType: 'invoiceLine',
                filters: ['purchaseOrderLineId' => $purchaseOrderLineId],
            );

            foreach ($invoiceLines as $invoiceLine) {
                $total += (float) ($invoiceLine['quantity'] ?? 0);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('ThreeWayMatchingService: no invoice lines found or fetch failed', [
                'purchaseOrderLineId' => $purchaseOrderLineId,
                'exception'           => $e->getMessage(),
            ]);
            // If none found, invoiced quantity is 0.
        }

        return $total;
    }//end sumInvoiceLineQuantity()
}//end class
