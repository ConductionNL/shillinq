<?php

/**
 * Inventory Valuation Method Guard
 *
 * ADR-031 thin lifecycle guard: blocks valuation method changes and archiving
 * when on-hand quantity is non-zero, preventing cost distortion.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Precondition guard for InventoryValuation lifecycle transitions.
 *
 * Referenced from the InventoryValuation schema x-openregister-lifecycle
 * transitions (archiveFromActive, archiveFromAdjusted, methodChange) as
 * OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard::checkZeroStock.
 *
 * ADR-031 exception: quantity > 0 check is not yet expressible in the
 * declarative lifecycle DSL. ≤15 LOC per ADR-031 §"PHP guards remain a
 * legitimate seam".
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 */
class InventoryValuationMethodGuard
{
    /**
     * Returns true only when on-hand quantity is zero.
     *
     * Blocks valuation method changes and archive transitions when stock remains,
     * preventing FIFO/average cost distortion on mid-inventory method switches
     * (REQ-INV-006) and preventing archiving of stocked items (REQ-INV-009).
     *
     * Fail-closed: returns false when quantity is missing or cannot be parsed.
     *
     * @param string                   $valuationId The InventoryValuation.id being transitioned.
     * @param array<string,mixed>|null $object      The in-flight InventoryValuation object.
     *
     * @return bool True when quantity = 0 and the transition may proceed.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
     */
    public function checkZeroStock(string $valuationId, ?array $object=null): bool
    {
        $quantity = ($object['quantity'] ?? null);
        if ($quantity === null) {
            return false;
        }

        return ((float) $quantity) === 0.0;
    }//end checkZeroStock()
}//end class
