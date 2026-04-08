<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Unit tests for CatalogSearchService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CatalogSearchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CatalogSearchService.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
 */
class CatalogSearchServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var CatalogSearchService
     */
    private CatalogSearchService $service;

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock ObjectService (anonymous class instance).
     *
     * @var object
     */
    private object $objectService;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new CatalogSearchService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Helper: create an anonymous ObjectService mock with configurable return data.
     *
     * @param array<string,mixed> $objectMap  Map of objectType => return data for getObjects
     * @param array<string,mixed> $singleMap  Map of objectType:id => return data for getObject
     *
     * @return object
     */
    private function createObjectService(array $objectMap = [], array $singleMap = []): object
    {
        return new class ($objectMap, $singleMap) {

            /**
             * @param array<string,mixed> $objectMap Map of objectType => return data
             * @param array<string,mixed> $singleMap Map of objectType:id => return data
             */
            public function __construct(
                private array $objectMap,
                private array $singleMap,
            ) {
            }//end __construct()


            /**
             * @param string              $objectType The object type
             * @param array<string,mixed> $filters    The filters to apply
             *
             * @return array<int,array<string,mixed>>
             */
            public function getObjects(string $objectType, array $filters = []): array
            {
                // Support filter-specific returns via objectType::filterKey=filterValue.
                foreach ($filters as $key => $value) {
                    $filterKey = $objectType . '::' . $key . '=' . $value;
                    if (isset($this->objectMap[$filterKey]) === true) {
                        return $this->objectMap[$filterKey];
                    }
                }

                return ($this->objectMap[$objectType] ?? []);
            }//end getObjects()


            /**
             * @param string $objectType The object type
             * @param string $id         The object ID
             *
             * @return array<string,mixed>
             *
             * @throws \RuntimeException When object is not found.
             */
            public function getObject(string $objectType, string $id): array
            {
                $key = $objectType . ':' . $id;
                if (isset($this->singleMap[$key]) === true) {
                    return $this->singleMap[$key];
                }

                throw new \RuntimeException("Object not found: {$key}");
            }//end getObject()
        };

    }//end createObjectService()

    /**
     * Test that search returns active items from active catalogs.
     *
     * Verifies that catalog items linked to active products and active catalogs
     * within their validity period are returned in search results.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
     */
    public function testSearchReturnsActiveItems(): void
    {
        $today = (new \DateTime())->format('Y-m-d');

        $objectService = $this->createObjectService(
            objectMap: [
                'catalogItem' => [
                    [
                        'id'        => 'ci-1',
                        'productId' => 'prod-1',
                        'catalogId' => 'cat-1',
                        'unitPrice' => 25.00,
                        'currency'  => 'EUR',
                    ],
                ],
            ],
            singleMap: [
                'product:prod-1' => [
                    'id'     => 'prod-1',
                    'name'   => 'Office Chair',
                    'active' => true,
                ],
                'catalog:cat-1' => [
                    'id'            => 'cat-1',
                    'name'          => 'Furniture Catalog',
                    'status'        => 'active',
                    'supplierName'  => 'ACME Furniture',
                    'effectiveFrom' => '2025-01-01',
                    'effectiveTo'   => '2027-12-31',
                ],
            ],
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $results = $this->service->search(term: 'Chair');

        self::assertCount(1, $results);
        self::assertSame('Office Chair', $results[0]['productName']);
        self::assertSame('ACME Furniture', $results[0]['supplierName']);
        self::assertSame(25.00, $results[0]['unitPrice']);

    }//end testSearchReturnsActiveItems()

    /**
     * Test that search excludes inactive products.
     *
     * Verifies that catalog items linked to products with active=false
     * are filtered out of search results.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
     */
    public function testSearchExcludesInactiveProducts(): void
    {
        $objectService = $this->createObjectService(
            objectMap: [
                'catalogItem' => [
                    [
                        'id'        => 'ci-1',
                        'productId' => 'prod-1',
                        'catalogId' => 'cat-1',
                        'unitPrice' => 10.00,
                        'currency'  => 'EUR',
                    ],
                    [
                        'id'        => 'ci-2',
                        'productId' => 'prod-2',
                        'catalogId' => 'cat-1',
                        'unitPrice' => 20.00,
                        'currency'  => 'EUR',
                    ],
                ],
            ],
            singleMap: [
                'product:prod-1' => [
                    'id'     => 'prod-1',
                    'name'   => 'Active Widget',
                    'active' => true,
                ],
                'product:prod-2' => [
                    'id'     => 'prod-2',
                    'name'   => 'Inactive Widget',
                    'active' => false,
                ],
                'catalog:cat-1' => [
                    'id'            => 'cat-1',
                    'name'          => 'Widget Catalog',
                    'status'        => 'active',
                    'supplierName'  => 'Widget Co',
                    'effectiveFrom' => '2025-01-01',
                    'effectiveTo'   => '2027-12-31',
                ],
            ],
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $results = $this->service->search(term: 'Widget');

        self::assertCount(1, $results);
        self::assertSame('Active Widget', $results[0]['productName']);

    }//end testSearchExcludesInactiveProducts()

    /**
     * Test that search filters by category including descendant categories.
     *
     * Verifies that when a categoryId is provided, the search includes
     * products belonging to the specified category and all its descendants.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
     */
    public function testSearchFiltersByCategory(): void
    {
        $objectService = $this->createObjectService(
            objectMap: [
                'catalogItem' => [
                    [
                        'id'        => 'ci-1',
                        'productId' => 'prod-1',
                        'catalogId' => 'cat-1',
                        'unitPrice' => 50.00,
                        'currency'  => 'EUR',
                    ],
                    [
                        'id'        => 'ci-2',
                        'productId' => 'prod-2',
                        'catalogId' => 'cat-1',
                        'unitPrice' => 75.00,
                        'currency'  => 'EUR',
                    ],
                ],
                // Category tree: parent-cat -> child-cat.
                'category::parentCategoryId=parent-cat' => [
                    ['id' => 'child-cat'],
                ],
                'category::parentCategoryId=child-cat' => [],
            ],
            singleMap: [
                'product:prod-1' => [
                    'id'         => 'prod-1',
                    'name'       => 'Desk Lamp',
                    'active'     => true,
                    'categoryId' => 'child-cat',
                ],
                'product:prod-2' => [
                    'id'         => 'prod-2',
                    'name'       => 'Floor Lamp',
                    'active'     => true,
                    'categoryId' => 'other-cat',
                ],
                'catalog:cat-1' => [
                    'id'            => 'cat-1',
                    'name'          => 'Lighting Catalog',
                    'status'        => 'active',
                    'supplierName'  => 'Lights Inc',
                    'effectiveFrom' => '2025-01-01',
                    'effectiveTo'   => '2027-12-31',
                ],
            ],
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $results = $this->service->search(term: 'Lamp', categoryId: 'parent-cat');

        self::assertCount(1, $results);
        self::assertSame('Desk Lamp', $results[0]['productName']);

    }//end testSearchFiltersByCategory()

    /**
     * Test that search returns empty results when no items match the term.
     *
     * Verifies that a search term with no matching product names returns
     * an empty result array.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.1
     */
    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $objectService = $this->createObjectService(
            objectMap: [
                'catalogItem' => [
                    [
                        'id'        => 'ci-1',
                        'productId' => 'prod-1',
                        'catalogId' => 'cat-1',
                        'unitPrice' => 10.00,
                        'currency'  => 'EUR',
                    ],
                ],
            ],
            singleMap: [
                'product:prod-1' => [
                    'id'     => 'prod-1',
                    'name'   => 'Office Chair',
                    'active' => true,
                ],
                'catalog:cat-1' => [
                    'id'            => 'cat-1',
                    'name'          => 'Furniture Catalog',
                    'status'        => 'active',
                    'supplierName'  => 'ACME',
                    'effectiveFrom' => '2025-01-01',
                    'effectiveTo'   => '2027-12-31',
                ],
            ],
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $results = $this->service->search(term: 'Nonexistent');

        self::assertCount(0, $results);

    }//end testSearchReturnsEmptyForNoMatch()
}//end class
