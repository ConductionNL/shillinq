<?php

/**
 * Unit tests for DunningGuard.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/tasks.md#task-6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\DunningGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DunningGuard covering the dunning cadence per REQ-AR-005.
 */
class DunningGuardTest extends TestCase
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
     * @var DunningGuard
     */
    private DunningGuard $guard;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        $this->logger = $this->createMock(LoggerInterface::class);

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        $this->guard = new DunningGuard(logger: $this->logger);

    }//end setUp()

    /**
     * Not yet overdue returns null (no dunning due).
     *
     * @return void
     */
    public function testNotOverdueReturnsNull(): void
    {
        // Due in the future relative to the reference date.
        self::assertNull(
            // phpcs:ignore CustomSniffs.Functions.NamedParameters
            $this->guard->dueLevel(dueDate: '2026-02-01', alreadyIssued: [], referenceDate: '2026-01-20')
        );

    }//end testNotOverdueReturnsNull()

    /**
     * 15 days overdue triggers reminder1 (threshold 14).
     *
     * @return void
     */
    public function testReminder1AtFifteenDays(): void
    {
        self::assertSame(
            'reminder1',
            // phpcs:ignore CustomSniffs.Functions.NamedParameters
            $this->guard->dueLevel(dueDate: '2026-01-01', alreadyIssued: [], referenceDate: '2026-01-16')
        );

    }//end testReminder1AtFifteenDays()

    /**
     * 50 days overdue with reminders already issued escalates to formal-notice.
     *
     * @return void
     */
    public function testFormalNoticeAtFiftyDaysAfterReminders(): void
    {
        self::assertSame(
            'formal-notice',
            $this->guard->dueLevel(
                // phpcs:ignore CustomSniffs.Functions.NamedParameters
                dueDate: '2026-01-01',
                alreadyIssued: ['reminder1', 'reminder2'],
                referenceDate: '2026-02-20'
            )
        );

    }//end testFormalNoticeAtFiftyDaysAfterReminders()

    /**
     * When the highest-due level is already issued, returns null (nothing new due).
     *
     * @return void
     */
    public function testAlreadyIssuedHighestReturnsNull(): void
    {
        self::assertNull(
            $this->guard->dueLevel(
                // phpcs:ignore CustomSniffs.Functions.NamedParameters
                dueDate: '2026-01-01',
                alreadyIssued: ['reminder1'],
                referenceDate: '2026-01-16'
            )
        );

    }//end testAlreadyIssuedHighestReturnsNull()

    /**
     * Invalid date input fails safe (returns null, logs error).
     *
     * @return void
     */
    public function testInvalidDateFailsSafe(): void
    {
        $this->logger->expects($this->once())->method('error');

        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertNull($this->guard->dueLevel(dueDate: 'not-a-date'));

    }//end testInvalidDateFailsSafe()
}//end class
