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
 * Covers the fail-closed precondition guard that prevents a FIFO ↔ average
 * method change while on-hand quantity is non-zero.
 *
 * @covers \OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
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

        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->logger = $this->createMock(LoggerInterface::class);
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->guard = new InventoryValuationMethodGuard(
            logger: $this->logger,
        );

    }//end setUp()

    /**
     * Returns true when on-hand quantity is exactly zero — method change allowed.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsTrueWhenQuantityIsZero(): void
    {
        $object = [
            'quantity'        => 0,
            'valuationMethod' => 'FIFO',
        ];

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->checkZeroStock(valuationId: 'val-001', object: $object));

    }//end testCheckZeroStockReturnsTrueWhenQuantityIsZero()

    /**
     * Returns false when on-hand quantity is non-zero — method change denied.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsFalseWhenQuantityIsNonZero(): void
    {
        $object = [
            'quantity'        => 50,
            'valuationMethod' => 'FIFO',
        ];

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->checkZeroStock(valuationId: 'val-002', object: $object));

    }//end testCheckZeroStockReturnsFalseWhenQuantityIsNonZero()

    /**
     * Returns true when object is null — quantity defaults to 0 (zero stock).
     *
     * @return void
     */
    public function testCheckZeroStockReturnsTrueWhenObjectIsNull(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->checkZeroStock(valuationId: 'val-003', object: null));

    }//end testCheckZeroStockReturnsTrueWhenObjectIsNull()

    /**
     * Returns true when quantity key is present but null — defaults to 0 (zero stock).
     *
     * @return void
     */
    public function testCheckZeroStockReturnsTrueWhenQuantityIsNullInObject(): void
    {
        $object = [
            'quantity'        => null,
            'valuationMethod' => 'average',
        ];

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->checkZeroStock(valuationId: 'val-004', object: $object));

    }//end testCheckZeroStockReturnsTrueWhenQuantityIsNullInObject()

    /**
     * Logs a warning exactly once when on-hand quantity is non-zero.
     *
     * @return void
     */
    public function testCheckZeroStockLogsWarningOnNonZeroStock(): void
    {
        $object = ['quantity' => 25.5];

        $this->logger->expects($this->once())->method('warning');

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        $this->guard->checkZeroStock(valuationId: 'val-005', object: $object);

    }//end testCheckZeroStockLogsWarningOnNonZeroStock()

    /**
     * Returns true when quantity is a float zero (0.0) — method change allowed.
     *
     * @return void
     */
    public function testCheckZeroStockReturnsTrueWhenQuantityIsFloatZero(): void
    {
        $object = ['quantity' => 0.0];

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->guard->checkZeroStock(valuationId: 'val-006', object: $object));

    }//end testCheckZeroStockReturnsTrueWhenQuantityIsFloatZero()

    /**
     * Returns false when quantity is a negative float — treated as non-zero (fail-closed).
     *
     * @return void
     */
    public function testCheckZeroStockReturnsFalseWhenQuantityIsNegative(): void
    {
        $object = ['quantity' => -1.5];

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertFalse($this->guard->checkZeroStock(valuationId: 'val-007', object: $object));

    }//end testCheckZeroStockReturnsFalseWhenQuantityIsNegative()
}//end class
