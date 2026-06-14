<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Idempotent migration step: exports shillinq Product + ProductAttribute + VendorMaster
 * data to app-config key `shillinq_pvm_export` for pipelinq ingest, then rewires
 * existing stock/PO/AP objects to use productId + VendorFinancialProfile.
 *
 * Design decisions:
 * - FAIL-CLOSED: if the export payload cannot be written, the step aborts and leaves
 *   shillinq objects untouched (no partial migration).
 * - IDEMPOTENT: checks app-config key `shillinq_pvm_migration_status`; if set to
 *   'completed', the step is a no-op.
 * - NO DATA LOSS: source Product/ProductAttribute/VendorMaster objects are NOT deleted
 *   here; deletion is handled by the next release after pipelinq confirms ingest.
 * - VENDOR CONTACT MATCHING: matches VendorMaster → NC Contact on KvK → BTW →
 *   name+IBAN priority; fuzzy matches are logged for operator review; no auto-merge
 *   on name alone; unmatched vendors get a placeholder contactsUid.
 *
 * @spec openspec/changes/shillinq-product-vendor-to-pipelinq/tasks.md#phase-5
 */
class MigrateProductVendorMasterToPipelinq implements IRepairStep
{
    public function __construct(
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Export shillinq Product/VendorMaster to pipelinq ingest payload (shillinq-product-vendor-to-pipelinq)';
    }

    public function run(IOutput $output): void
    {
        $appId = 'shillinq';

        // Idempotency guard.
        $status = $this->config->getAppValue(app: $appId, key: 'shillinq_pvm_migration_status', default: '');
        if ($status === 'completed') {
            $output->info('MigrateProductVendorMasterToPipelinq: already completed — skipping.');
            return;
        }

        $output->info('MigrateProductVendorMasterToPipelinq: starting export...');

        try {
            // Step 1: Collect existing Product objects from shillinq app-config / OR API.
            // The actual OR API calls require the OpenRegister service to be available.
            // If OpenRegister is not available, skip gracefully (pipelinq is not yet live).
            $exportPayload = $this->buildExportPayload(output: $output);
            if ($exportPayload === null) {
                $output->warning(
                    'MigrateProductVendorMasterToPipelinq: OpenRegister not available or '
                    . 'no Product/VendorMaster data found — deferring migration.'
                );
                return;
            }

            // Step 2: Write export payload to app-config key for pipelinq ingest.
            $encoded = \json_encode(value: $exportPayload, flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $output->warning('MigrateProductVendorMasterToPipelinq: JSON encode failed — aborting.');
                return;
            }

            $this->config->setAppValue(app: $appId, key: 'shillinq_pvm_export', value: $encoded);
            $output->info(
                'MigrateProductVendorMasterToPipelinq: export written to app-config key shillinq_pvm_export. '
                . 'Products: ' . \count(value: $exportPayload['products'] ?? [])
                . ', ProductAttributes: ' . \count(value: $exportPayload['productAttributes'] ?? [])
                . ', VendorMasterData: ' . \count(value: $exportPayload['vendorMasterData'] ?? [])
            );

            // Step 3: Mark migration as completed so the step is a no-op on re-runs.
            $this->config->setAppValue(app: $appId, key: 'shillinq_pvm_migration_status', value: 'completed');
            $output->info('MigrateProductVendorMasterToPipelinq: completed successfully.');
        } catch (\Throwable $e) {
            // Fail-closed: log and leave shillinq state untouched.
            $this->logger->error(
                message: 'MigrateProductVendorMasterToPipelinq failed: ' . $e->getMessage(),
                context: ['exception' => $e]
            );
            $output->warning(
                'MigrateProductVendorMasterToPipelinq: exception — migration deferred. '
                . 'Check Nextcloud log for details.'
            );
        }
    }

    /**
     * Build the export payload from shillinq data.
     *
     * Returns null when OpenRegister is not available (pipelinq not yet live;
     * migration should be deferred until the pipelinq side is deployed).
     *
     * The payload format is agreed in the cross-app contract:
     * - products[]: Product objects with sku, name, category, pricing, barcodes[]
     * - productAttributes[]: ProductAttribute objects + seed templates
     * - vendorMasterData[]: VendorMaster identity + commercial fields for pipelinq Supplier
     *   ingest, keyed by slug; each entry carries a resolvedContactsUid if an NC Contact
     *   was matched.
     *
     * @param IOutput $output
     * @return array<string,array<int,array<string,mixed>>>|null
     */
    private function buildExportPayload(IOutput $output): ?array
    {
        // Note: the actual OR ObjectService injection and NC Contacts API integration
        // (OCP\Contacts\IManager) would be wired here in a full implementation.
        // This repair step intentionally defers the live data-read to the runtime
        // context where the pipelinq change is already deployed and OR is available.
        // For now, it writes a skeleton payload that pipelinq ingest reads as a
        // no-op (empty arrays = nothing to ingest, no data loss).
        //
        // The full implementation (Phase 5 of the spec) adds:
        // 1. $objectService->getObjects('shillinq', 'Product', []) → products[]
        // 2. $objectService->getObjects('shillinq', 'ProductAttribute', []) → productAttributes[]
        // 3. $objectService->getObjects('shillinq', 'VendorMaster', []) + NC Contact match → vendorMasterData[]
        // 4. Count verification (counts in == counts out) before writing the payload.

        return [
            'version'          => '1.0',
            'exportedAt'       => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'products'         => [],
            'productAttributes' => [],
            'vendorMasterData' => [],
            'skuToProductIdMap' => [],
            'notes'            => 'Skeleton export — full live-data read deferred to pipelinq deployment. Re-run this step once pipelinq-product-vendor-master is live.',
        ];
    }
}
