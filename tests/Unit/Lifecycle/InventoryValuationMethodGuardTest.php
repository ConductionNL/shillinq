<?php

/**
 * Unit tests for InventoryValuationMethodGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests for InventoryValuationMethodGuard::checkZeroStock.
 *
 * Covers REQ-INV-006 (method change blocked on non-zero stock) and
 * REQ-INV-009 (archive blocked on non-zero stock).
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 */
class InventoryValuationMethodGuardTest extends TestCase
{

    /**
     * The guard under test.
     *
     * @var InventoryValuationMethodGuard
     */
    private InventoryValuationMethodGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new InventoryValuationMethodGuard();
    }//end setUp()

    /**
     * REQ-INV-006 scenario: method change on zero-stock item succeeds.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsTrueWhenQuantityIsZero(): void
    {
        $valuation = ['quantity' => 0, 'valuationMethod' => 'FIFO'];
        $result    = $this->guard->checkZeroStock(valuationId: 'val-001', object: $valuation);
        self::assertTrue($result);
    }//end testCheckZeroStockReturnsTrueWhenQuantityIsZero()

    /**
     * REQ-INV-006 scenario: method change on stocked item is rejected.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsFalseWhenQuantityIsNonZero(): void
    {
        $valuation = ['quantity' => 50, 'valuationMethod' => 'FIFO'];
        $result    = $this->guard->checkZeroStock(valuationId: 'val-001', object: $valuation);
        self::assertFalse($result);
    }//end testCheckZeroStockReturnsFalseWhenQuantityIsNonZero()

    /**
     * REQ-INV-006 scenario: fractional non-zero quantity blocks the transition.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsFalseForFractionalNonZero(): void
    {
        $valuation = ['quantity' => 0.5, 'valuationMethod' => 'average'];
        $result    = $this->guard->checkZeroStock(valuationId: 'val-002', object: $valuation);
        self::assertFalse($result);
    }//end testCheckZeroStockReturnsFalseForFractionalNonZero()

    /**
     * Fail-closed: missing quantity field returns false (guards against nil dereference).
     *
     * @return void
     */
    public function testCheckZeroStockReturnsFalseWhenQuantityMissing(): void
    {
        $valuation = ['valuationMethod' => 'FIFO'];
        $result    = $this->guard->checkZeroStock(valuationId: 'val-003', object: $valuation);
        self::assertFalse($result);
    }//end testCheckZeroStockReturnsFalseWhenQuantityMissing()

    /**
     * Fail-closed: null object returns false.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsFalseWhenObjectIsNull(): void
    {
        $result = $this->guard->checkZeroStock(valuationId: 'val-004', object: null);
        self::assertFalse($result);
    }//end testCheckZeroStockReturnsFalseWhenObjectIsNull()
}//end class
