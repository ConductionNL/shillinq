<?php

/**
 * Unit tests for InventoryPostingGuard.
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
 * @spec openspec/changes/inventory-cogs-posting/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InventoryPostingGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InventoryPostingGuard — covers REQ-CG-004 count-variance direction.
 *
 * - Positive delta returns "debit" (Dr Inventory Asset, Cr Inventory Adjustment).
 * - Negative delta returns "credit" (Dr Inventory Adjustment, Cr Inventory Asset).
 * - Zero delta returns "none" (no posting required per REQ-CG-004).
 */
class InventoryPostingGuardTest extends TestCase
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
     * @var InventoryPostingGuard
     */
    private InventoryPostingGuard $guard;

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
        $this->guard = new InventoryPostingGuard(logger: $this->logger);

    }//end setUp()

    /**
     * Positive delta: Dr Inventory Asset side returns "debit" per REQ-CG-004.
     *
     * GIVEN actual > book (e.g. variance = +5)
     * THEN Inventory Asset is debited → direction returns "debit".
     *
     * @return void
     */
    public function testPositiveDeltaReturnsDebit(): void
    {
        self::assertSame(expected: 'debit', actual: $this->guard->direction(delta: 5));

    }//end testPositiveDeltaReturnsDebit()

    /**
     * Negative delta: Cr Inventory Asset side returns "credit" per REQ-CG-004.
     *
     * GIVEN actual < book (e.g. variance = -10)
     * THEN Inventory Asset is credited → direction returns "credit".
     *
     * @return void
     */
    public function testNegativeDeltaReturnsCredit(): void
    {
        self::assertSame(expected: 'credit', actual: $this->guard->direction(delta: -10));

    }//end testNegativeDeltaReturnsCredit()

    /**
     * Zero delta: no GL posting required → direction returns "none" per REQ-CG-004.
     *
     * @return void
     */
    public function testZeroDeltaReturnsNone(): void
    {
        $this->logger->expects($this->once())->method('info');
        self::assertSame(expected: 'none', actual: $this->guard->direction(delta: 0));

    }//end testZeroDeltaReturnsNone()

    /**
     * Large positive delta still returns "debit".
     *
     * @return void
     */
    public function testLargePositiveDeltaReturnsDebit(): void
    {
        self::assertSame(expected: 'debit', actual: $this->guard->direction(delta: 1000));

    }//end testLargePositiveDeltaReturnsDebit()

    /**
     * Large negative delta still returns "credit".
     *
     * @return void
     */
    public function testLargeNegativeDeltaReturnsCredit(): void
    {
        self::assertSame(expected: 'credit', actual: $this->guard->direction(delta: -999));

    }//end testLargeNegativeDeltaReturnsCredit()
}//end class
