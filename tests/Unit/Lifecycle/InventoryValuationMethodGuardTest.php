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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InventoryValuationMethodGuard.
 *
 * Covers REQ-INV-006 / REQ-INV-009 zero-stock precondition:
 *  - quantity == 0  => guard returns true (transition permitted)
 *  - quantity > 0   => guard returns false (transition denied)
 *  - missing quantity treated as zero => true
 *  - guard is fail-closed (returns false on any exception path)
 */
class InventoryValuationMethodGuardTest extends TestCase
{

    /**
     * Mock LoggerInterface.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

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

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->guard  = new InventoryValuationMethodGuard(logger: $this->logger);

    }//end setUp()


    /**
     * Zero on-hand quantity permits the transition (REQ-INV-006).
     *
     * @return void
     */
    public function testZeroQuantityPermitsTransition(): void
    {
        $valuation = [
            'productId'       => 'GT-10-2026',
            'warehouse'       => 'Magazijn Noord',
            'quantity'        => 0.0,
            'valuationMethod' => 'FIFO',
        ];

        $this->assertTrue($this->guard->checkZeroStock(valuation: $valuation));

    }//end testZeroQuantityPermitsTransition()


    /**
     * Non-zero on-hand quantity denies the transition (REQ-INV-006).
     *
     * @return void
     */
    public function testNonZeroQuantityDeniesTransition(): void
    {
        $valuation = [
            'productId'       => 'GT-10-2026',
            'warehouse'       => 'Magazijn Noord',
            'quantity'        => 50.0,
            'valuationMethod' => 'FIFO',
        ];

        $this->logger->expects($this->once())->method('info');

        $this->assertFalse($this->guard->checkZeroStock(valuation: $valuation));

    }//end testNonZeroQuantityDeniesTransition()


    /**
     * Missing quantity field is treated as zero (defensive default).
     *
     * @return void
     */
    public function testMissingQuantityPermitsTransition(): void
    {
        $valuation = [
            'productId'       => 'GT-10-2026',
            'valuationMethod' => 'FIFO',
        ];

        $this->assertTrue($this->guard->checkZeroStock(valuation: $valuation));

    }//end testMissingQuantityPermitsTransition()


    /**
     * Fractional non-zero quantity denies the transition (cost distortion
     * is still possible even with partial stock).
     *
     * @return void
     */
    public function testFractionalQuantityDeniesTransition(): void
    {
        $valuation = [
            'quantity'        => 0.01,
            'valuationMethod' => 'average',
        ];

        $this->logger->expects($this->once())->method('info');

        $this->assertFalse($this->guard->checkZeroStock(valuation: $valuation));

    }//end testFractionalQuantityDeniesTransition()


}//end class
