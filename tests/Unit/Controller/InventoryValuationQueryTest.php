<?php

/**
 * Unit tests for InventoryValuationQuery.
 *
 * The request value object for an inventory-valuation query: the required administration and as-of date, the identifier shapes, the optional sku/warehouse filters (null when empty) and the ageing flag that only applies when both a sku and a warehouse are given.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-valuation/spec.md
 *
 * @covers \OCA\Shillinq\Controller\InventoryValuationQuery
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\InventoryValuationQuery;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class InventoryValuationQueryTest extends TestCase {
	private function query(array $params): InventoryValuationQuery {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);
		return new InventoryValuationQuery($request);
	}//end query()

	public function testCompletenessNeedsAdministrationAndAsOf(): void {
		$this->assertTrue($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31'])->isComplete());
		$this->assertFalse($this->query(['administration_id' => 'a1'])->isComplete());
		$this->assertFalse($this->query(['as_of' => '2026-01-31'])->isComplete());
	}//end testCompletenessNeedsAdministrationAndAsOf()

	public function testWellFormedChecksIdsDateAndOptionalFilters(): void {
		$this->assertTrue($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31', 'sku' => 'SKU-1', 'warehouse' => 'WH 1'])->isWellFormed());
		$this->assertTrue($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31T00:00:00Z'])->isWellFormed());
		$this->assertFalse($this->query(['administration_id' => 'a1', 'as_of' => '31-01-2026'])->isWellFormed());
		$this->assertFalse($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31', 'sku' => "bad\tsku"])->isWellFormed());
	}//end testWellFormedChecksIdsDateAndOptionalFilters()

	public function testFiltersAreNullWhenEmpty(): void {
		$empty = $this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31']);
		$this->assertNull($empty->skuFilter());
		$this->assertNull($empty->warehouseFilter());

		$set = $this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31', 'sku' => 'SKU-1', 'warehouse' => 'WH1']);
		$this->assertSame('SKU-1', $set->skuFilter());
		$this->assertSame('WH1', $set->warehouseFilter());
	}//end testFiltersAreNullWhenEmpty()

	public function testAgeingNeedsTheFlagAndBothFilters(): void {
		$this->assertTrue($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31', 'sku' => 'SKU-1', 'warehouse' => 'WH1', 'ageing' => '1'])->wantsAgeing());
		$this->assertFalse($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31', 'sku' => 'SKU-1', 'warehouse' => 'WH1'])->wantsAgeing());
		$this->assertFalse($this->query(['administration_id' => 'a1', 'as_of' => '2026-01-31', 'sku' => 'SKU-1', 'ageing' => '1'])->wantsAgeing());
	}//end testAgeingNeedsTheFlagAndBothFilters()
}//end class
