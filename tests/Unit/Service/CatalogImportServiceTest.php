<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 */

/**
 * Unit tests for CatalogImportService.
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
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CatalogImportService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for CatalogImportService.
 *
 * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
 */
class CatalogImportServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var CatalogImportService
     */
    private CatalogImportService $service;

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
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->service = new CatalogImportService(
            container: $this->container,
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Helper: create a CSV stream resource from a string.
     *
     * @param string $csvContent The CSV content as a string
     *
     * @return resource A readable stream resource
     */
    private function createCsvStream(string $csvContent)
    {
        $stream = fopen(filename: 'php://memory', mode: 'r+');
        fwrite(stream: $stream, data: $csvContent);
        rewind(stream: $stream);

        return $stream;

    }//end createCsvStream()

    /**
     * Helper: create an anonymous ObjectService mock with configurable return data.
     *
     * @param array<string,mixed> $objectMap Map of filter key => return data for getObjects
     * @param array<string,mixed> $creates   Collects createObject calls by reference
     * @param array<string,mixed> $updates   Collects updateObject calls by reference
     *
     * @return object
     */
    private function createObjectService(array $objectMap = [], array &$creates = [], array &$updates = []): object
    {
        return new class ($objectMap, $creates, $updates) {

            /**
             * @param array<string,mixed> $objectMap Map of filter key => return data
             * @param array<string,mixed> $creates   Collects create calls by reference
             * @param array<string,mixed> $updates   Collects update calls by reference
             */
            public function __construct(
                private array $objectMap,
                private array &$creates,
                private array &$updates,
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
                foreach ($filters as $key => $value) {
                    $filterKey = $objectType . '::' . $key . '=' . $value;
                    if (isset($this->objectMap[$filterKey]) === true) {
                        return $this->objectMap[$filterKey];
                    }
                }

                return ($this->objectMap[$objectType] ?? []);
            }//end getObjects()


            /**
             * @param string              $objectType The object type
             * @param array<string,mixed> $data       The data to create
             *
             * @return array<string,mixed>
             */
            public function createObject(string $objectType, array $data): array
            {
                $this->creates[] = [
                    'objectType' => $objectType,
                    'data'       => $data,
                ];

                return array_merge($data, ['id' => 'new-' . count($this->creates)]);
            }//end createObject()


            /**
             * @param string              $objectType The object type
             * @param string              $id         The object ID
             * @param array<string,mixed> $data       The data to update
             *
             * @return array<string,mixed>
             */
            public function updateObject(string $objectType, string $id, array $data): array
            {
                $this->updates[] = [
                    'objectType' => $objectType,
                    'id'         => $id,
                    'data'       => $data,
                ];

                return array_merge($data, ['id' => $id]);
            }//end updateObject()
        };

    }//end createObjectService()

    /**
     * Test that import rejects CSV with missing required headers.
     *
     * When the CSV header row does not contain the 'sku' column, the import
     * must return imported=0 with a top-level error about missing columns.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
     */
    public function testImportRejectsMissingHeaders(): void
    {
        $csvStream = $this->createCsvStream("name,unit_price\nWidget,10.00\n");

        $result = $this->service->import(catalogId: 'cat-1', csvStream: $csvStream);

        self::assertSame(0, $result['imported']);
        self::assertCount(1, $result['errors']);
        self::assertSame(0, $result['errors'][0]['row']);
        self::assertStringContainsString('sku', $result['errors'][0]['message']);

        fclose($csvStream);

    }//end testImportRejectsMissingHeaders()

    /**
     * Test that import records per-row error for unknown SKU.
     *
     * When a CSV row contains a SKU that does not match any product,
     * the import must record a per-row error with the SKU value.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
     */
    public function testImportRecordsUnknownSkuError(): void
    {
        $creates       = [];
        $updates       = [];
        $objectService = $this->createObjectService(
            objectMap: [
                'product::sku=UNKNOWN-SKU' => [],
            ],
            creates: $creates,
            updates: $updates,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $csvStream = $this->createCsvStream("sku,unit_price\nUNKNOWN-SKU,10.00\n");

        $result = $this->service->import(catalogId: 'cat-1', csvStream: $csvStream);

        self::assertSame(0, $result['imported']);
        self::assertCount(1, $result['errors']);
        self::assertSame('UNKNOWN-SKU', $result['errors'][0]['sku']);
        self::assertStringContainsString('Product not found', $result['errors'][0]['message']);

        fclose($csvStream);

    }//end testImportRecordsUnknownSkuError()

    /**
     * Test that import successfully upserts items from valid CSV data.
     *
     * When the CSV contains valid rows with known SKUs, the import must
     * create new catalog items and return the correct imported count.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
     */
    public function testImportSuccessfullyUpsertsItems(): void
    {
        $creates       = [];
        $updates       = [];
        $objectService = $this->createObjectService(
            objectMap: [
                'product::sku=SKU-001' => [
                    ['id' => 'prod-1', 'sku' => 'SKU-001'],
                ],
                'product::sku=SKU-002' => [
                    ['id' => 'prod-2', 'sku' => 'SKU-002'],
                ],
                // No existing catalog items for these products.
                'catalogItem::catalogId=cat-1' => [],
                'catalogItem'                  => [],
            ],
            creates: $creates,
            updates: $updates,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $csvContent = "sku,unit_price\nSKU-001,25.50\nSKU-002,30.00\n";
        $csvStream  = $this->createCsvStream($csvContent);

        $result = $this->service->import(catalogId: 'cat-1', csvStream: $csvStream);

        self::assertSame(2, $result['imported']);
        self::assertCount(0, $result['errors']);
        self::assertCount(2, $creates);

        fclose($csvStream);

    }//end testImportSuccessfullyUpsertsItems()

    /**
     * Test that import returns zero when all rows are invalid.
     *
     * When every data row in the CSV has an unresolvable SKU, the import
     * must return imported=0 with per-row errors for each row.
     *
     * @return void
     *
     * @spec openspec/changes/catalog-purchase-management/tasks.md#task-3.4
     */
    public function testImportReturnsZeroWhenAllRowsInvalid(): void
    {
        $creates       = [];
        $updates       = [];
        $objectService = $this->createObjectService(
            objectMap: [
                'product::sku=BAD-1' => [],
                'product::sku=BAD-2' => [],
                'product::sku=BAD-3' => [],
            ],
            creates: $creates,
            updates: $updates,
        );

        $this->container->expects($this->once())
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($objectService);

        $csvContent = "sku,unit_price\nBAD-1,10.00\nBAD-2,20.00\nBAD-3,30.00\n";
        $csvStream  = $this->createCsvStream($csvContent);

        $result = $this->service->import(catalogId: 'cat-1', csvStream: $csvStream);

        self::assertSame(0, $result['imported']);
        self::assertCount(3, $result['errors']);

        fclose($csvStream);

    }//end testImportReturnsZeroWhenAllRowsInvalid()
}//end class
