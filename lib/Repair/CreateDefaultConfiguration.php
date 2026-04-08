<?php

/**
 * Shillinq Create Default Configuration Repair Step
 *
 * Repair step that seeds default catalog-purchase-management data on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
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

namespace OCA\Shillinq\Repair;

use OCA\Shillinq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Repair step that seeds default catalog and purchase management data.
 *
 * Seeds ProductCategory, Product, Catalog, CatalogItem, PurchaseOrder,
 * PurchaseOrderLine, RFQ, and SupplierQuote objects idempotently via
 * OpenRegister's ObjectService.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-2.1
 */
class CreateDefaultConfiguration implements IRepairStep
{
    /**
     * Constructor for CreateDefaultConfiguration.
     *
     * @param SettingsService    $settingsService The settings service
     * @param ContainerInterface $container       The service container
     * @param LoggerInterface    $logger          The logger interface
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the name of this repair step.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Seed default catalog and purchase management data for Shillinq';
    }//end getName()

    /**
     * Run the repair step to seed default configuration data.
     *
     * Seeds ProductCategory, Product, Catalog, CatalogItem, PurchaseOrder,
     * PurchaseOrderLine, RFQ, and SupplierQuote objects idempotently.
     *
     * @param IOutput $output The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-2.1
     */
    public function run(IOutput $output): void
    {
        $output->info('Seeding default catalog-purchase-management data...');

        if ($this->settingsService->isOpenRegisterAvailable() === false) {
            $output->warning(
                'OpenRegister is not installed or enabled. Skipping default data seeding.'
            );
            $this->logger->warning(
                'Shillinq: OpenRegister not available, skipping default data seeding'
            );
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $output->warning('Could not obtain ObjectService: '.$e->getMessage());
            $this->logger->error(
                'Shillinq: ObjectService not available for seeding',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        try {
            $this->seedAllData(objectService: $objectService, output: $output);
            $output->info('Default catalog-purchase-management data seeded successfully.');
        } catch (\Throwable $e) {
            $output->warning('Error seeding default data: '.$e->getMessage());
            $this->logger->error(
                'Shillinq: default data seeding failed',
                ['exception' => $e->getMessage()]
            );
        }//end try
    }//end run()

    /**
     * Seed all default data in the correct order.
     *
     * Handles cross-entity reference resolution by seeding parent entities
     * first and passing their IDs to child entities.
     *
     * @param object  $objectService The OpenRegister ObjectService
     * @param IOutput $output        The output interface for progress reporting
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-2.1
     */
    private function seedAllData(object $objectService, IOutput $output): void
    {
        // Seed root product categories.
        $officeId = $this->seedObject(
            objectService: $objectService,
            schema: 'productCategory',
            uniqueField: 'code',
            uniqueValue: 'OFFICE',
            data: [
                'name' => 'Office & Facilities',
                'code' => 'OFFICE',
            ],
            output: $output,
        );

        $itId = $this->seedObject(
            objectService: $objectService,
            schema: 'productCategory',
            uniqueField: 'code',
            uniqueValue: 'IT',
            data: [
                'name' => 'IT & Electronics',
                'code' => 'IT',
            ],
            output: $output,
        );

        // Seed child product categories with parent references.
        $officeSupId = $this->seedObject(
            objectService: $objectService,
            schema: 'productCategory',
            uniqueField: 'code',
            uniqueValue: 'OFFICE-SUP',
            data: [
                'name'             => 'Office Supplies',
                'code'             => 'OFFICE-SUP',
                'parentCategoryId' => $officeId,
            ],
            output: $output,
        );

        $itHwId = $this->seedObject(
            objectService: $objectService,
            schema: 'productCategory',
            uniqueField: 'code',
            uniqueValue: 'IT-HW',
            data: [
                'name'             => 'Computer Hardware',
                'code'             => 'IT-HW',
                'parentCategoryId' => $itId,
            ],
            output: $output,
        );

        // Seed products.
        $paperA4Id = $this->seedObject(
            objectService: $objectService,
            schema: 'product',
            uniqueField: 'sku',
            uniqueValue: 'PAPER-A4-80',
            data: [
                'sku'           => 'PAPER-A4-80',
                'name'          => 'A4 Copy Paper 80gsm (500 sheets)',
                'unit'          => 'ream',
                'purchasePrice' => 4.50,
                'taxRate'       => 21,
                'categoryId'    => $officeSupId,
            ],
            output: $output,
        );

        $penBlkId = $this->seedObject(
            objectService: $objectService,
            schema: 'product',
            uniqueField: 'sku',
            uniqueValue: 'PEN-BLK-10',
            data: [
                'sku'           => 'PEN-BLK-10',
                'name'          => 'Black Ballpoint Pen (box of 10)',
                'unit'          => 'box',
                'purchasePrice' => 3.20,
                'taxRate'       => 21,
                'categoryId'    => $officeSupId,
            ],
            output: $output,
        );

        $laptopId = $this->seedObject(
            objectService: $objectService,
            schema: 'product',
            uniqueField: 'sku',
            uniqueValue: 'LAPTOP-15-STD',
            data: [
                'sku'           => 'LAPTOP-15-STD',
                'name'          => 'Standard 15" Business Laptop',
                'unit'          => 'piece',
                'purchasePrice' => 850.00,
                'taxRate'       => 21,
                'categoryId'    => $itHwId,
            ],
            output: $output,
        );

        $mouseId = $this->seedObject(
            objectService: $objectService,
            schema: 'product',
            uniqueField: 'sku',
            uniqueValue: 'MOUSE-USB-STD',
            data: [
                'sku'           => 'MOUSE-USB-STD',
                'name'          => 'USB Optical Mouse',
                'unit'          => 'piece',
                'purchasePrice' => 15.00,
                'taxRate'       => 21,
                'categoryId'    => $itHwId,
            ],
            output: $output,
        );

        // Seed catalog.
        $catalogId = $this->seedObject(
            objectService: $objectService,
            schema: 'catalog',
            uniqueField: 'name',
            uniqueValue: 'Office Essentials 2026',
            data: [
                'name'              => 'Office Essentials 2026',
                'status'            => 'active',
                'effectiveFrom'     => '2026-01-01',
                'effectiveTo'       => '2026-12-31',
                'contractReference' => 'FW-2026-OFFICE',
            ],
            output: $output,
        );

        // Resolve supplier profile ID for Acme BV.
        $acmeId = $this->resolveObjectId(
            objectService: $objectService,
            schema: 'supplierProfile',
            field: 'companyName',
            value: 'Acme BV',
        );

        // Resolve supplier profile ID for Beta Supplies BV.
        $betaId = $this->resolveObjectId(
            objectService: $objectService,
            schema: 'supplierProfile',
            field: 'companyName',
            value: 'Beta Supplies BV',
        );

        // Seed catalog items (one per product, linked to catalog + Acme BV).
        $productIds = [
            $paperA4Id,
            $penBlkId,
            $laptopId,
            $mouseId,
        ];

        foreach ($productIds as $productId) {
            if ($productId === null || $catalogId === null) {
                continue;
            }

            $this->seedCatalogItem(
                objectService: $objectService,
                catalogId: $catalogId,
                productId: $productId,
                supplierId: $acmeId,
                output: $output,
            );
        }

        // Seed purchase order.
        $poId = $this->seedObject(
            objectService: $objectService,
            schema: 'purchaseOrder',
            uniqueField: 'poNumber',
            uniqueValue: 'PO-2026-00001',
            data: [
                'poNumber'             => 'PO-2026-00001',
                'status'               => 'acknowledged',
                'expectedDeliveryDate' => '2026-04-30',
                'totalAmount'          => 50.00,
                'currency'             => 'EUR',
            ],
            output: $output,
        );

        // Seed purchase order lines.
        if ($poId !== null && $paperA4Id !== null) {
            $this->seedObject(
                objectService: $objectService,
                schema: 'purchaseOrderLine',
                uniqueField: 'purchaseOrderId',
                uniqueValue: $poId,
                data: [
                    'purchaseOrderId' => $poId,
                    'productId'       => $paperA4Id,
                    'quantity'        => 5,
                    'unit'            => 'ream',
                    'unitPrice'       => 4.50,
                    'lineTotal'       => 22.50,
                ],
                output: $output,
            );
        }

        if ($poId !== null && $penBlkId !== null) {
            $this->seedObject(
                objectService: $objectService,
                schema: 'purchaseOrderLine',
                uniqueField: 'purchaseOrderId',
                uniqueValue: ($poId.'-'.($penBlkId ?? '')),
                data: [
                    'purchaseOrderId' => $poId,
                    'productId'       => $penBlkId,
                    'quantity'        => 5,
                    'unit'            => 'box',
                    'unitPrice'       => 3.20,
                    'lineTotal'       => 16.00,
                ],
                output: $output,
            );
        }

        // Seed RFQ.
        $rfqId = $this->seedObject(
            objectService: $objectService,
            schema: 'rfq',
            uniqueField: 'number',
            uniqueValue: 'RFQ-2026-00001',
            data: [
                'number'  => 'RFQ-2026-00001',
                'title'   => 'RFQ — IT Hardware Q3 2026',
                'type'    => 'RFQ',
                'status'  => 'evaluating',
                'budget'  => 5000.00,
                'dueDate' => '2026-05-15',
            ],
            output: $output,
        );

        // Seed supplier quotes.
        if ($rfqId !== null && $acmeId !== null) {
            $this->seedObject(
                objectService: $objectService,
                schema: 'supplierQuote',
                uniqueField: 'rfqId',
                uniqueValue: ($rfqId.'-'.$acmeId),
                data: [
                    'rfqId'             => $rfqId,
                    'supplierProfileId' => $acmeId,
                    'totalAmount'       => 4200.00,
                    'status'            => 'submitted',
                ],
                output: $output,
            );
        }

        if ($rfqId !== null && $betaId !== null) {
            $this->seedObject(
                objectService: $objectService,
                schema: 'supplierQuote',
                uniqueField: 'rfqId',
                uniqueValue: ($rfqId.'-'.$betaId),
                data: [
                    'rfqId'             => $rfqId,
                    'supplierProfileId' => $betaId,
                    'totalAmount'       => 4650.00,
                    'status'            => 'submitted',
                ],
                output: $output,
            );
        }
    }//end seedAllData()

    /**
     * Seed a single object idempotently.
     *
     * Checks for an existing record by unique field before creating.
     * Returns the object ID of the created or existing record.
     *
     * @param object  $objectService The OpenRegister ObjectService
     * @param string  $schema        The schema name to seed into
     * @param string  $uniqueField   The field to check for uniqueness
     * @param string  $uniqueValue   The value that must be unique
     * @param array   $data          The object data to seed
     * @param IOutput $output        The output interface for progress reporting
     *
     * @return string|null The object ID or null on failure
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-2.1
     */
    private function seedObject(
        object $objectService,
        string $schema,
        string $uniqueField,
        string $uniqueValue,
        array $data,
        IOutput $output,
    ): ?string {
        try {
            // Check for existing object by unique field.
            $existing = $objectService->getObjects(
                schema: $schema,
                filters: [$uniqueField => $uniqueValue],
                limit: 1,
            );

            if (empty($existing) === false) {
                $existingObject = reset($existing);
                $id = ($existingObject['id'] ?? null);
                $output->info(
                    "Skipped existing {$schema} ({$uniqueField}={$uniqueValue}), id={$id}"
                );
                return $id;
            }

            // Create the new object.
            $result = $objectService->saveObject(
                schema: $schema,
                data: $data,
            );

            $id = ($result['id'] ?? null);
            $output->info("Created {$schema} ({$uniqueField}={$uniqueValue}), id={$id}");

            return $id;
        } catch (\Throwable $e) {
            $output->warning(
                "Failed to seed {$schema} ({$uniqueField}={$uniqueValue}): ".$e->getMessage()
            );
            $this->logger->error(
                "Shillinq: failed to seed {$schema}",
                [
                    'uniqueField' => $uniqueField,
                    'uniqueValue' => $uniqueValue,
                    'exception'   => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end seedObject()

    /**
     * Seed a catalog item using composite key (catalogId + productId) for idempotency.
     *
     * @param object      $objectService The OpenRegister ObjectService
     * @param string      $catalogId     The catalog object ID
     * @param string      $productId     The product object ID
     * @param string|null $supplierId    The supplier profile object ID
     * @param IOutput     $output        The output interface for progress reporting
     *
     * @return string|null The catalog item object ID or null on failure
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-2.2
     */
    private function seedCatalogItem(
        object $objectService,
        string $catalogId,
        string $productId,
        ?string $supplierId,
        IOutput $output,
    ): ?string {
        try {
            // Check for existing catalog item by composite key.
            $existing = $objectService->getObjects(
                schema: 'catalogItem',
                filters: [
                    'catalogId' => $catalogId,
                    'productId' => $productId,
                ],
                limit: 1,
            );

            if (empty($existing) === false) {
                $existingObject = reset($existing);
                $id = ($existingObject['id'] ?? null);
                $output->info(
                    "Skipped existing catalogItem (catalogId={$catalogId}, productId={$productId}), id={$id}"
                );
                return $id;
            }

            $data = [
                'catalogId' => $catalogId,
                'productId' => $productId,
            ];

            if ($supplierId !== null) {
                $data['supplierProfileId'] = $supplierId;
            }

            $result = $objectService->saveObject(
                schema: 'catalogItem',
                data: $data,
            );

            $id = ($result['id'] ?? null);
            $output->info(
                "Created catalogItem (catalogId={$catalogId}, productId={$productId}), id={$id}"
            );

            return $id;
        } catch (\Throwable $e) {
            $output->warning(
                'Failed to seed catalogItem: '.$e->getMessage()
            );
            $this->logger->error(
                'Shillinq: failed to seed catalogItem',
                [
                    'catalogId' => $catalogId,
                    'productId' => $productId,
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end seedCatalogItem()

    /**
     * Resolve an object ID by searching for it with a field filter.
     *
     * @param object $objectService The OpenRegister ObjectService
     * @param string $schema        The schema to search
     * @param string $field         The field to filter on
     * @param string $value         The value to match
     *
     * @return string|null The object ID or null if not found
     */
    private function resolveObjectId(
        object $objectService,
        string $schema,
        string $field,
        string $value,
    ): ?string {
        try {
            $results = $objectService->getObjects(
                schema: $schema,
                filters: [$field => $value],
                limit: 1,
            );

            if (empty($results) === false) {
                $object = reset($results);
                return ($object['id'] ?? null);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                "Shillinq: could not resolve {$schema} by {$field}={$value}",
                ['exception' => $e->getMessage()]
            );
        }//end try

        return null;
    }//end resolveObjectId()
}//end class
