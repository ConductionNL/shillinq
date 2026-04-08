<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Shillinq Catalog Import Service
 *
 * Imports catalog items from CSV data, resolving products by SKU and
 * upserting catalog item entries with pricing and availability metadata.
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
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for importing catalog items from CSV streams.
 *
 * Validates the CSV header, resolves products by SKU, and upserts catalog items
 * (creates new or updates existing entries). Returns a summary of imported rows
 * and per-row errors. If fewer than 1 valid row exists, no partial transaction
 * is committed.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
 */
class CatalogImportService
{

    /**
     * Required CSV columns.
     *
     * @var array<string>
     */
    private const REQUIRED_COLUMNS = [
        'sku',
        'unit_price',
    ];

    /**
     * Constructor for the CatalogImportService.
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
     * Import catalog items from a CSV stream.
     *
     * Validates the header row for required columns (sku, unit_price).
     * For each data row, resolves the product by SKU. If found, upserts a
     * CatalogItem (creates if catalogId+productId combination does not exist;
     * updates unitPrice, minimumOrderQuantity, leadTimeDays if it does).
     * Optional columns: moq (minimumOrderQuantity), lead_time_days.
     *
     * If fewer than 1 valid row is found, no partial transaction is committed.
     *
     * @param string   $catalogId The catalog ID to import items into
     * @param resource $csvStream A readable stream resource containing CSV data
     *
     * @return array{imported: int, errors: array<int,array{row: int, sku: string, message: string}>}
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
     */
    public function import(string $catalogId, $csvStream): array
    {
        $errors   = [];
        $imported = 0;

        // Read and validate header row.
        $headerRow = fgetcsv(stream: $csvStream);
        if ($headerRow === false || $headerRow === null) {
            return [
                'imported' => 0,
                'errors'   => [
                    [
                        'row'     => 0,
                        'sku'     => '',
                        'message' => 'Missing required columns: sku, unit_price',
                    ],
                ],
            ];
        }

        $headers = array_map(callback: 'trim', array: $headerRow);
        $headers = array_map(callback: 'strtolower', array: $headers);

        $missingColumns = array_diff(self::REQUIRED_COLUMNS, $headers);
        if (empty($missingColumns) === false) {
            return [
                'imported' => 0,
                'errors'   => [
                    [
                        'row'     => 0,
                        'sku'     => '',
                        'message' => 'Missing required columns: ' . implode(separator: ', ', array: $missingColumns),
                    ],
                ],
            ];
        }

        // Build column index map.
        $columnMap = array_flip($headers);

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('CatalogImportService: unable to get ObjectService', ['exception' => $e->getMessage()]);
            return [
                'imported' => 0,
                'errors'   => [
                    [
                        'row'     => 0,
                        'sku'     => '',
                        'message' => 'Internal error: ObjectService unavailable',
                    ],
                ],
            ];
        }

        // Parse all data rows first to validate before committing.
        $validRows = [];
        $rowNumber = 0;

        while (($row = fgetcsv(stream: $csvStream)) !== false) {
            $rowNumber++;

            if ($row === null || (count($row) === 1 && ($row[0] === null || trim($row[0]) === ''))) {
                continue;
            }

            $sku = trim((string) ($row[$columnMap['sku']] ?? ''));
            $unitPrice = ($row[$columnMap['unit_price']] ?? '');

            if ($sku === '') {
                $errors[] = [
                    'row'     => $rowNumber,
                    'sku'     => $sku,
                    'message' => 'Empty SKU',
                ];
                continue;
            }

            // Resolve product by SKU.
            try {
                $products = $objectService->getObjects(
                    objectType: 'product',
                    filters: ['sku' => $sku],
                );
            } catch (\Throwable $e) {
                $errors[] = [
                    'row'     => $rowNumber,
                    'sku'     => $sku,
                    'message' => 'Failed to look up product: ' . $e->getMessage(),
                ];
                continue;
            }

            if (empty($products) === true) {
                $errors[] = [
                    'row'     => $rowNumber,
                    'sku'     => $sku,
                    'message' => 'Product not found for SKU: ' . $sku,
                ];
                continue;
            }

            $product   = reset($products);
            $productId = ($product['id'] ?? null);

            if ($productId === null) {
                $errors[] = [
                    'row'     => $rowNumber,
                    'sku'     => $sku,
                    'message' => 'Product has no ID for SKU: ' . $sku,
                ];
                continue;
            }

            $itemData = [
                'catalogId' => $catalogId,
                'productId' => $productId,
                'unitPrice' => (float) $unitPrice,
            ];

            // Optional columns.
            if (isset($columnMap['moq']) === true && isset($row[$columnMap['moq']]) === true) {
                $moqValue = trim((string) $row[$columnMap['moq']]);
                if ($moqValue !== '') {
                    $itemData['minimumOrderQuantity'] = (int) $moqValue;
                }
            }

            if (isset($columnMap['lead_time_days']) === true && isset($row[$columnMap['lead_time_days']]) === true) {
                $leadTimeValue = trim((string) $row[$columnMap['lead_time_days']]);
                if ($leadTimeValue !== '') {
                    $itemData['leadTimeDays'] = (int) $leadTimeValue;
                }
            }

            $validRows[] = [
                'rowNumber' => $rowNumber,
                'sku'       => $sku,
                'productId' => $productId,
                'data'      => $itemData,
            ];
        }//end while

        // If fewer than 1 valid row, do not commit anything.
        if (count($validRows) < 1) {
            $this->logger->info('CatalogImportService: no valid rows to import', [
                'catalogId' => $catalogId,
                'errors'    => count($errors),
            ]);

            return [
                'imported' => 0,
                'errors'   => $errors,
            ];
        }

        // Upsert valid rows.
        foreach ($validRows as $validRow) {
            try {
                // Check if catalog item already exists for this catalog + product.
                $existingItems = $objectService->getObjects(
                    objectType: 'catalogItem',
                    filters: [
                        'catalogId' => $catalogId,
                        'productId' => $validRow['productId'],
                    ],
                );

                if (empty($existingItems) === false) {
                    // Update existing catalog item.
                    $existingItem = reset($existingItems);
                    $objectService->updateObject(
                        objectType: 'catalogItem',
                        id: $existingItem['id'],
                        data: $validRow['data'],
                    );
                } else {
                    // Create new catalog item.
                    $objectService->createObject(
                        objectType: 'catalogItem',
                        data: $validRow['data'],
                    );
                }

                $imported++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row'     => $validRow['rowNumber'],
                    'sku'     => $validRow['sku'],
                    'message' => 'Failed to upsert catalog item: ' . $e->getMessage(),
                ];
            }
        }//end foreach

        $this->logger->info('CatalogImportService: import completed', [
            'catalogId' => $catalogId,
            'imported'  => $imported,
            'errors'    => count($errors),
        ]);

        return [
            'imported' => $imported,
            'errors'   => $errors,
        ];
    }//end import()
}//end class
