<?php

/**
 * Inventory Valuation Method Guard
 *
 * Thin ADR-031 lifecycle guard for the InventoryValuation schema. One
 * predicate — `checkZeroStock()` — referenced by:
 *
 *   - the `methodChange` self-loop transition (FIFO <-> average) per
 *     REQ-INV-006: switching method while stock is on hand would
 *     re-value existing cost layers and distort COGS, so it is blocked
 *     until quantity = 0;
 *   - the `obsoleteFromActive` / `obsoleteFromAdjusted` transitions per
 *     REQ-INV-009: marking a snapshot obsolete is only meaningful when
 *     no stock remains;
 *   - the `validations.onUpdate.methodChangeRequiresZeroStock` rule
 *     (defence-in-depth so a generic OR CRUD patch of
 *     `valuationMethod` cannot bypass the transition guard).
 *
 * Fail-closed: any exception denies the transition. ADR-031
 * §"PHP guards remain a legitimate seam" — the declarative DSL cannot
 * compose the cross-state-machine predicate with the configurable
 * shillinq error vocabulary that the operator-facing UI surfaces.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * Zero-stock precondition guard for InventoryValuation transitions
 * (REQ-INV-006 + REQ-INV-009).
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 */
class InventoryValuationMethodGuard
{


    /**
     * Construct the guard with logger DI.
     *
     * @param LoggerInterface $logger Logger for fail-closed diagnostics.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Predicate: the InventoryValuation's on-hand quantity is zero.
     *
     * Returns true (transition permitted) when quantity <= 0. Returns
     * false (transition denied) when quantity > 0 OR on any exception
     * (fail-closed).
     *
     * @param array<string,mixed> $valuation The InventoryValuation record.
     *
     * @return bool True when on-hand quantity is zero.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
     */
    public function checkZeroStock(array $valuation): bool
    {
        try {
            $quantity = (float) ($valuation['quantity'] ?? 0);
            if ($quantity > 0) {
                $this->logger->info(
                    'InventoryValuationMethodGuard: transition denied — non-zero stock',
                    [
                        'productId'       => ($valuation['productId'] ?? null),
                        'warehouse'       => ($valuation['warehouse'] ?? null),
                        'quantity'        => $quantity,
                        'valuationMethod' => ($valuation['valuationMethod'] ?? null),
                    ]
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'InventoryValuationMethodGuard: checkZeroStock failed — denying transition (fail-closed)',
                ['exception' => $e->getMessage()]
            );
            return false;
        }//end try

    }//end checkZeroStock()


}//end class
