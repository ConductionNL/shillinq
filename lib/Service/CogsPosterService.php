<?php

/**
 * COGS Poster Service
 *
 * ADR-031 exception (documented in design.md D4): builds and persists a
 * JournalEntry for Cost-of-Goods-Sold (COGS) when an inventory item is
 * consumed by an outbound StockMovement. Invoked by FifoValuationService
 * and MovingAverageValuationService after on-hand quantity is reduced.
 *
 * GL account numbers are read from app config (keys: cogs_account,
 * inventory_account). If either key is not configured, the service
 * marks the valuation's pendingCogs flag, logs a WARNING, and skips
 * posting — it MUST NOT silently skip without marking the record
 * (per design.md Risk: R-COGS-001).
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
 * Builds and persists a COGS JournalEntry via ObjectService.
 *
 * Debit: COGS expense account (default '7000').
 * Credit: Inventory asset account (default '3000').
 *
 * GL account number configuration is mandatory. When not configured the
 * service sets valuation.pendingCogs = true, logs a WARNING, and returns
 * without creating the JournalEntry, ensuring no silent data loss.
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
 */
class CogsPosterService
{
    /**
     * Construct the service with lazy DI dependencies.
     *
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for GL account numbers and register slug.
     * @param LoggerInterface    $logger    Logger for warnings and fail-closed error diagnostics.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Build and post a COGS JournalEntry for the given outbound movement.
     *
     * Reads GL account numbers from app config. If either account is not
     * configured (empty raw value), marks valuation.pendingCogs = true,
     * logs a WARNING, and returns without posting — the record is marked so
     * that a subsequent reconciliation job can retry. If cogsAmount <= 0,
     * logs a WARNING, sets valuation.status = 'adjusted', and returns.
     *
     * @param array<string,mixed> $movement   The outbound StockMovement object.
     * @param array<string,mixed> $valuation  The InventoryValuation object (already updated for quantity).
     * @param float               $cogsAmount The COGS monetary amount in base currency.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    public function postCogs(array $movement, array $valuation, float $cogsAmount): void
    {
        try {
            // Read raw configured values (empty default = "not configured").
            $rawCogsAccount      = $this->appConfig->getValueString(Application::APP_ID, 'cogs_account', '');
            $rawInventoryAccount = $this->appConfig->getValueString(Application::APP_ID, 'inventory_account', '');

            $glNotConfigured = ($rawCogsAccount === '' || $rawInventoryAccount === '');

            // Resolve effective account numbers: use config if set, fall back to defaults.
            $cogsAccount = '7000';
            if ($rawCogsAccount !== '') {
                $cogsAccount = $rawCogsAccount;
            }

            $inventoryAccount = '3000';
            if ($rawInventoryAccount !== '') {
                $inventoryAccount = $rawInventoryAccount;
            }

            // Guard: GL accounts not configured — mark pending, log, skip posting.
            if ($glNotConfigured === true) {
                $this->logger->warning(
                    'CogsPosterService: GL accounts not configured — marking valuation pendingCogs=true and skipping COGS post',
                    [
                        'movementUuid'        => ($movement['uuid'] ?? ''),
                        'cogsAccountSet'      => ($rawCogsAccount !== ''),
                        'inventoryAccountSet' => ($rawInventoryAccount !== ''),
                    ]
                );
                $this->markPendingCogs(valuation: $valuation);
                return;
            }

            // Guard: non-positive COGS amount is a data anomaly — mark adjusted.
            if ($cogsAmount <= 0.0) {
                $this->logger->warning(
                    'CogsPosterService: cogsAmount is non-positive — marking valuation status=adjusted and skipping COGS post',
                    ['movementUuid' => ($movement['uuid'] ?? ''), 'cogsAmount' => $cogsAmount]
                );
                $this->markAdjusted(valuation: $valuation);
                return;
            }

            $qty         = (float) ($movement['quantity'] ?? 0);
            $productName = (string) ($movement['itemId'] ?? ($movement['productId'] ?? 'unknown'));
            $unitCost    = round(num: $cogsAmount / max($qty, 1.0), precision: 4);
            $description = sprintf(
                'COGS — %s — %d × EUR %.4f',
                $productName,
                (int) $qty,
                $unitCost
            );

            $journalEntry = [
                'journalCode'      => 'COGS',
                'reference'        => (string) ($movement['uuid'] ?? ''),
                'description'      => $description,
                'entryDate'        => date(format: 'Y-m-d'),
                'state'            => 'posted',
                'administrationId' => (string) ($valuation['administrationId'] ?? 'default'),
                'journalType'      => 'manual',
                'approvalState'    => 'not-required',
                'journalNumber'    => 'COGS-'.((string) ($movement['uuid'] ?? uniqid(prefix: 'cogs', more_entropy: true))),
                'lines'            => [
                    [
                        'accountNumber' => $cogsAccount,
                        'side'          => 'debit',
                        'amount'        => round(num: $cogsAmount, precision: 2),
                    ],
                    [
                        'accountNumber' => $inventoryAccount,
                        'side'          => 'credit',
                        'amount'        => round(num: $cogsAmount, precision: 2),
                    ],
                ],
            ];

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->resolveRegister();

            $objectService
                ->setRegister($register)
                ->setSchema('JournalEntry')
                ->saveObject(object: $journalEntry);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CogsPosterService: postCogs failed (fail-closed)',
                ['movementUuid' => ($movement['uuid'] ?? ''), 'exception' => $e->getMessage()]
            );
        }//end try
    }//end postCogs()

    /**
     * Mark the valuation as having a pending (unposted) COGS entry and persist the flag.
     *
     * Called when GL accounts are not configured. Sets valuation.pendingCogs = true
     * so a subsequent reconciliation job can detect and retry the posting.
     *
     * @param array<string,mixed> $valuation The InventoryValuation object array.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    private function markPendingCogs(array $valuation): void
    {
        try {
            $valuation['pendingCogs'] = true;

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->resolveRegister();

            $objectService
                ->setRegister($register)
                ->setSchema('InventoryValuation')
                ->saveObject(object: $valuation);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CogsPosterService: markPendingCogs failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end markPendingCogs()

    /**
     * Mark the valuation as adjusted (data anomaly) and persist the status.
     *
     * Called when cogsAmount is non-positive, indicating a data anomaly that
     * requires manual review. Sets valuation.status = 'adjusted'.
     *
     * @param array<string,mixed> $valuation The InventoryValuation object array.
     *
     * @return void
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    private function markAdjusted(array $valuation): void
    {
        try {
            $valuation['status'] = 'adjusted';

            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $register      = $this->resolveRegister();

            $objectService
                ->setRegister($register)
                ->setSchema('InventoryValuation')
                ->saveObject(object: $valuation);
        } catch (\Throwable $e) {
            $this->logger->error(
                'CogsPosterService: markAdjusted failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end markAdjusted()

    /**
     * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
     *
     * @return string The register slug.
     *
     * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
     */
    private function resolveRegister(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
        if ($register === '') {
            return 'shillinq';
        }

        return $register;
    }//end resolveRegister()
}//end class
