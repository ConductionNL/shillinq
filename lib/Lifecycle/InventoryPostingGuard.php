<?php

/**
 * Inventory Posting Guard
 *
 * ADR-031 exception-path lifecycle guard for count-variance posting direction
 * on InventoryValuation. Determines whether the Inventory Asset account is on
 * the debit or credit side depending on the sign of the stock delta.
 *
 * ADR-031 exception reason: the x-openregister-lifecycle engine cannot yet
 * express inline sign-conditional GL-line direction (positive vs negative
 * variance → opposite debit/credit orientation). A single-method guard is
 * declared in the schema register and called by the lifecycle engine on the
 * countVariance transition. When the engine gains inline conditional direction
 * support, replace this reference with a declarative condition and delete
 * this file.
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
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception-path guard: count-variance GL-line direction.
 *
 * Referenced from shillinq_register.json InventoryValuation
 * x-openregister-lifecycle transitions.countVariance as
 * OCA\Shillinq\Lifecycle\InventoryPostingGuard::direction.
 *
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
 */
class InventoryPostingGuard
{
    /**
     * Construct the guard.
     *
     * @param LoggerInterface $logger Logger for diagnostic output.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Returns the GL-line side for the Inventory Asset account given the stock delta.
     *
     * Positive delta (actual > book — stock increase):
     *   Dr Inventory Asset / Cr Inventory Adjustment.
     *   Returns "debit" (Inventory Asset is on the debit side).
     *
     * Negative delta (actual < book — stock decrease):
     *   Dr Inventory Adjustment / Cr Inventory Asset.
     *   Returns "credit" (Inventory Asset is on the credit side).
     *
     * Zero delta: no posting should be made; returns "none" as a sentinel
     * so the lifecycle engine can skip action execution.
     *
     * @param int $delta Signed quantity variance: actual_quantity − book_quantity.
     *
     * @return string "debit", "credit", or "none" (zero-delta skip sentinel).
     *
     * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
     */
    public function direction(int $delta): string
    {
        if ($delta === 0) {
            $this->logger->info(
                'InventoryPostingGuard: zero delta — no GL posting required.',
                ['delta' => $delta]
            );
            return 'none';
        }

        if ($delta > 0) {
            return 'debit';
        }

        return 'credit';

    }//end direction()
}//end class
