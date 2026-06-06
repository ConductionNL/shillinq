<?php

/**
 * COGS Poster Service
 *
 * Posts one balanced JournalEntry per outbound StockMovement to the shillinq GL.
 * Debit: COGS account (default 7000 Kostprijs van de omzet, configurable per
 * administration). Credit: Inventory asset account (default 3000 Voorraden).
 *
 * ADR-031 exception (documented in design.md D4): cross-schema write with a
 * runtime-computed monetary amount is outside OpenRegister's declarative
 * x-openregister-notifications expression range. This thin service (≤50 LOC)
 * is the integration adapter between the inventory sub-ledger and the GL.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Posts a balanced COGS JournalEntry on each outbound StockMovement.
 *
 * Called by FifoValuationService and MovingAverageValuationService after they
 * have computed the COGS amount for an outbound movement.
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */
class CogsPosterService
{
    /**
     * Construct the service with DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for register slug and GL account numbers.
     * @param LoggerInterface    $logger    Logger.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Post a balanced COGS JournalEntry for an outbound stock movement.
     *
     * When GL account numbers are not configured, sets InventoryValuation.status
     * to 'adjusted', logs a WARNING, and does NOT silently skip (REQ-INV-007).
     * The reference field carries StockMovement.uuid for cross-ledger traceability.
     *
     * @param array<string,mixed> $movement   The outbound StockMovement object.
     * @param array<string,mixed> $valuation  The current InventoryValuation snapshot.
     * @param float               $cogsAmount The computed COGS amount for this movement.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    public function postCogs(array $movement, array $valuation, float $cogsAmount): void
    {
        $cogsAccount      = $this->appConfig->getValueString(Application::APP_ID, 'cogs_account', '7000');
        $inventoryAccount = $this->appConfig->getValueString(Application::APP_ID, 'inventory_account', '3000');

        if ($cogsAccount === '' || $inventoryAccount === '') {
            $this->logger->warning(
                'CogsPosterService: GL account numbers not configured — COGS posting skipped, marking valuation as adjusted',
                [
                    'itemId'    => ($movement['itemId'] ?? ''),
                    'warehouse' => ($movement['warehouse'] ?? ''),
                    'uuid'      => ($movement['uuid'] ?? ''),
                ]
            );
            $this->markPendingCogs(valuation: $valuation);
            return;
        }

        $movementUuid = ($movement['uuid'] ?? '');
        $productName  = ($movement['itemId'] ?? 'unknown');
        $qty          = ($movement['quantity'] ?? 0);
        $unitCost     = ($valuation['unitCost'] ?? 0);

        $journalEntry = [
            'journalCode'         => 'COGS',
            'reference'           => $movementUuid,
            'description'         => sprintf('COGS — %s — %s × EUR %s', $productName, $qty, $unitCost),
            'debitAccountNumber'  => $cogsAccount,
            'creditAccountNumber' => $inventoryAccount,
            'debitAmount'         => $cogsAmount,
            'creditAmount'        => $cogsAmount,
            'entryDate'           => ($movement['date'] ?? date('c')),
        ];

        try {
            $register      = $this->resolveRegister();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $objectService->saveObject(
                object: $journalEntry,
                register: $register,
                schema: 'JournalEntry',
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CogsPosterService: JournalEntry save failed',
                ['movementUuid' => $movementUuid, 'exception' => $e->getMessage()]
            );
            $this->markPendingCogs(valuation: $valuation);
        }//end try

    }//end postCogs()

    /**
     * Mark the InventoryValuation as adjusted with pendingCogs = true.
     *
     * Called when the COGS posting cannot complete due to missing GL config
     * or a save failure. Prevents silent skips per REQ-INV-007.
     *
     * @param array<string,mixed> $valuation The InventoryValuation to mark.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    private function markPendingCogs(array $valuation): void
    {
        try {
            $register      = $this->resolveRegister();
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');

            $valuation['pendingCogs'] = true;
            $valuation['status']      = 'adjusted';

            $objectService->saveObject(
                object: $valuation,
                register: $register,
                schema: 'InventoryValuation',
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'CogsPosterService: failed to mark pendingCogs on valuation',
                ['exception' => $e->getMessage()]
            );
        }//end try

    }//end markPendingCogs()

    /**
     * Resolve the configured register slug, defaulting to 'shillinq'.
     *
     * @return string The register slug.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    private function resolveRegister(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($slug === '') {
            return 'shillinq';
        }

        return $slug;
    }//end resolveRegister()
}//end class
