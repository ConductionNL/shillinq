<?php

/**
 * Shillinq Catalog Search Service
 *
 * Provides full-text and filtered search across active catalogs and products,
 * resolving category hierarchies for descendant matching.
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
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
 */

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for searching catalog items across active catalogs and products.
 *
 * Joins catalog item, product, and catalog metadata to return enriched search
 * results filtered by catalog validity period, product status, and optional
 * category hierarchy.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
 */
class CatalogSearchService
{
    /**
     * Constructor for the CatalogSearchService.
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
     * Search catalog items by term with optional category filtering.
     *
     * Queries catalogItem objects, joining product and catalog metadata.
     * Only returns results from active catalogs within their validity period
     * and with active products. When a categoryId is provided, matches the
     * category and all its descendants via parentCategoryId traversal.
     *
     * @param string      $term       The search term to match against product names
     * @param string|null $categoryId Optional category ID to filter by (includes descendants)
     *
     * @return array<int,array<string,mixed>> Array of result items with productName,
     *                                        supplierName, unitPrice, currency,
     *                                        catalogName, contractReference,
     *                                        catalogItemId, productId
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
     */
    public function search(string $term, ?string $categoryId=null): array
    {
        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->error('CatalogSearchService: unable to get ObjectService', ['exception' => $e->getMessage()]);
            return [];
        }

        $today = (new \DateTime())->format('Y-m-d');

        // Fetch all catalog items.
        try {
            $catalogItems = $objectService->getObjects(
                objectType: 'catalogItem',
                filters: [],
            );
        } catch (\Throwable $e) {
            $this->logger->error('CatalogSearchService: failed to fetch catalog items', ['exception' => $e->getMessage()]);
            return [];
        }

        // Resolve allowed category IDs if filtering by category.
        $allowedCategoryIds = null;
        if ($categoryId !== null) {
            $allowedCategoryIds   = $this->getDescendantCategoryIds(
                objectService: $objectService,
                categoryId: $categoryId,
            );
            $allowedCategoryIds[] = $categoryId;
        }

        $results = [];

        foreach ($catalogItems as $catalogItem) {
            $productId = ($catalogItem['productId'] ?? null);
            $catalogId = ($catalogItem['catalogId'] ?? null);

            if ($productId === null || $catalogId === null) {
                continue;
            }

            // Fetch the product.
            try {
                $product = $objectService->getObject(
                    objectType: 'product',
                    id: $productId,
                );
            } catch (\Throwable $e) {
                $this->logger->debug('CatalogSearchService: product not found', ['productId' => $productId]);
                continue;
            }

            // Filter: product must be active.
            if (empty($product['active']) === true || $product['active'] !== true) {
                continue;
            }

            // Filter: category matching (including descendants).
            if ($allowedCategoryIds !== null) {
                $productCategoryId = ($product['categoryId'] ?? null);
                if ($productCategoryId === null || in_array($productCategoryId, $allowedCategoryIds, true) === false) {
                    continue;
                }
            }

            // Filter: search term against product name.
            $productName = ($product['name'] ?? '');
            if ($term !== '' && stripos($productName, $term) === false) {
                continue;
            }

            // Fetch the catalog.
            try {
                $catalog = $objectService->getObject(
                    objectType: 'catalog',
                    id: $catalogId,
                );
            } catch (\Throwable $e) {
                $this->logger->debug('CatalogSearchService: catalog not found', ['catalogId' => $catalogId]);
                continue;
            }

            // Filter: catalog must be active.
            if (($catalog['status'] ?? '') !== 'active') {
                continue;
            }

            // Filter: catalog validity period.
            $effectiveFrom = ($catalog['effectiveFrom'] ?? null);
            $effectiveTo   = ($catalog['effectiveTo'] ?? null);
            if ($effectiveFrom !== null && $effectiveFrom > $today) {
                continue;
            }

            if ($effectiveTo !== null && $effectiveTo < $today) {
                continue;
            }

            $results[] = [
                'productName'       => $productName,
                'supplierName'      => ($catalog['supplierName'] ?? ''),
                'unitPrice'         => ($catalogItem['unitPrice'] ?? 0),
                'currency'          => ($catalogItem['currency'] ?? 'EUR'),
                'catalogName'       => ($catalog['name'] ?? ''),
                'contractReference' => ($catalog['contractReference'] ?? ''),
                'catalogItemId'     => ($catalogItem['id'] ?? ''),
                'productId'         => $productId,
            ];
        }//end foreach

        $this->logger->info(
                'CatalogSearchService: search completed',
                [
                    'term'    => $term,
                    'results' => count($results),
                ]
                );

        return $results;
    }//end search()

    /**
     * Recursively find all descendant category IDs for a given category.
     *
     * Traverses the category tree via parentCategoryId to collect all
     * child categories at every depth level.
     *
     * @param object $objectService The OpenRegister object service
     * @param string $categoryId    The parent category ID to find descendants for
     *
     * @return array<int,string> Array of descendant category IDs
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
     */
    private function getDescendantCategoryIds(object $objectService, string $categoryId): array
    {
        $descendants = [];

        try {
            $children = $objectService->getObjects(
                objectType: 'category',
                filters: ['parentCategoryId' => $categoryId],
            );
        } catch (\Throwable $e) {
            $this->logger->debug(
                    'CatalogSearchService: failed to fetch child categories',
                    [
                        'categoryId' => $categoryId,
                        'exception'  => $e->getMessage(),
                    ]
                    );
            return [];
        }

        foreach ($children as $child) {
            $childId = ($child['id'] ?? null);
            if ($childId === null) {
                continue;
            }

            $descendants[] = $childId;
            $descendants   = array_merge(
                $descendants,
                $this->getDescendantCategoryIds(
                    objectService: $objectService,
                    categoryId: $childId,
                )
            );
        }//end foreach

        return $descendants;
    }//end getDescendantCategoryIds()
}//end class
