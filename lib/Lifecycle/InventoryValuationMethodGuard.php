<?php

/**
 * Inventory Valuation Method Guard
 *
 * ADR-031 lifecycle precondition guard preventing valuation method change
 * (FIFO ↔ average) when on-hand quantity is non-zero.
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

use Psr\Log\LoggerInterface;

/**
 * Lifecycle precondition guard for InventoryValuation method-change transitions.
 *
 * Referenced from the InventoryValuation schema x-openregister-lifecycle
 * transitions.changeMethod.requires as
 * OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard::checkZeroStock.
 *
 * The guard denies a FIFO ↔ average switch whenever on-hand quantity is
 * non-zero. Fail-closed: any exception returns false (deny).
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 */
class InventoryValuationMethodGuard
{
    /**
     * Construct the guard with a logger for fail-closed diagnostics.
     *
     * @param LoggerInterface $logger Logger for non-zero stock warnings and exceptions.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns true when on-hand quantity is zero (method change is allowed).
     *
     * Reads quantity from the supplied object array. Returns false when
     * quantity is non-zero, the object is missing, or any exception occurs
     * (fail-closed per CWE-863).
     *
     * @param string                   $valuationId The InventoryValuation.id (present for lifecycle call-signature parity).
     * @param array<string,mixed>|null $object      The InventoryValuation object being transitioned.
     *
     * @return bool True when quantity === 0 and the method change may proceed.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
     */
    public function checkZeroStock(string $valuationId, ?array $object=null): bool
    {
        try {
            $quantity = (float) ($object['quantity'] ?? 0);

            if ($quantity !== 0.0) {
                $this->logger->warning(
                    'InventoryValuationMethodGuard: denying method change — on-hand quantity is non-zero (fail-closed)',
                    ['valuationId' => $valuationId, 'quantity' => $quantity]
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(
                'InventoryValuationMethodGuard: checkZeroStock failed — denying method change (fail-closed)',
                ['valuationId' => $valuationId, 'exception' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end checkZeroStock()
}//end class
