<?php

/**
 * Unit tests for SubmissionWindowGuard.
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
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\SubmissionWindowGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SubmissionWindowGuard covering the REQ-SDD-004 business-day
 * windows (D-5 FRST/OOFF CORE, D-2 RCUR/FNAL CORE, D-1 B2B).
 */
class SubmissionWindowGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var SubmissionWindowGuard
	 */
	private SubmissionWindowGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		$this->guard = new SubmissionWindowGuard($this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * FRST CORE generated only 3 business days before due is too late (REQ-SDD-004).
	 *
	 * Due Wed 2026-07-15; generate Fri 2026-07-10. Business days strictly
	 * between: Mon 13, Tue 14 = 2 (less than the required 5).
	 *
	 * @return void
	 */
	public function testFrstCoreTooLate(): void {
		self::assertFalse(
			// phpcs:ignore CustomSniffs.Functions.NamedParameters
			$this->guard->isWithinWindow(scheme: 'CORE', sequenceType: 'FRST', collectionDate: '2026-07-15', generationDate: '2026-07-10')
		);
	}//end testFrstCoreTooLate()

	/**
	 * FRST CORE generated 8 business days early satisfies the D-5 window (REQ-SDD-004).
	 *
	 * Due Wed 2026-07-15; generate Fri 2026-07-03.
	 *
	 * @return void
	 */
	public function testFrstCoreWithinWindow(): void {
		self::assertTrue(
			// phpcs:ignore CustomSniffs.Functions.NamedParameters
			$this->guard->isWithinWindow(scheme: 'CORE', sequenceType: 'FRST', collectionDate: '2026-07-15', generationDate: '2026-07-03')
		);
	}//end testFrstCoreWithinWindow()

	/**
	 * RCUR CORE at the D-2 boundary is accepted (REQ-SDD-004).
	 *
	 * Due Wed 2026-07-15; generate Fri 2026-07-10. Business days strictly
	 * between: Mon 13, Tue 14 = 2 (meets the required 2).
	 *
	 * @return void
	 */
	public function testRcurCoreAtBoundary(): void {
		self::assertTrue(
			// phpcs:ignore CustomSniffs.Functions.NamedParameters
			$this->guard->isWithinWindow(scheme: 'CORE', sequenceType: 'RCUR', collectionDate: '2026-07-15', generationDate: '2026-07-10')
		);
	}//end testRcurCoreAtBoundary()

	/**
	 * B2B at D-1 (one business day prior) is accepted (REQ-SDD-004).
	 *
	 * Due Wed 2026-07-15; generate Tue 2026-07-14. Business days strictly
	 * between: none — fails. Generate Mon 2026-07-13 -> Tue 14 = 1 day, ok.
	 *
	 * @return void
	 */
	public function testB2bOneBusinessDay(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$this->guard->isWithinWindow(scheme: 'B2B', sequenceType: 'RCUR', collectionDate: '2026-07-15', generationDate: '2026-07-13')
		);
		self::assertFalse(
			$this->guard->isWithinWindow(scheme: 'B2B', sequenceType: 'RCUR', collectionDate: '2026-07-15', generationDate: '2026-07-14')
		);
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testB2bOneBusinessDay()

	/**
	 * Dutch holidays do not count as business days (REQ-SDD-004).
	 *
	 * Due Mon 2026-04-13; generate Tue 2026-04-07. The interval contains
	 * Good Friday 2026-04-03 (before range) and Easter Monday 2026-04-06
	 * (before range); within Wed 8..Fri 10 + Thu... Use a window that
	 * straddles King's Day 2026-04-27 to assert a holiday is skipped.
	 *
	 * @return void
	 */
	public function testHolidayIsNotABusinessDay(): void {
		// Due Thu 2026-04-30; generate Wed 2026-04-22. Calendar weekdays in
		// (22, 30): 23,24,27,28,29 = 5; but 27 Apr (King's Day) is a holiday,
		// so business days = 4. RCUR CORE needs 2 -> ok; FRST CORE needs 5 ->
		// would be 5 without the holiday, but only 4 with it -> rejected.
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->isWithinWindow(scheme: 'CORE', sequenceType: 'FRST', collectionDate: '2026-04-30', generationDate: '2026-04-22')
		);
		self::assertTrue(
			$this->guard->isWithinWindow(scheme: 'CORE', sequenceType: 'RCUR', collectionDate: '2026-04-30', generationDate: '2026-04-22')
		);
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testHolidayIsNotABusinessDay()

	/**
	 * A generation date on/after the due date is rejected, as is an unknown pair.
	 *
	 * @return void
	 */
	public function testInvalidInputsFailClosed(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$this->guard->isWithinWindow(scheme: 'CORE', sequenceType: 'FRST', collectionDate: '2026-07-15', generationDate: '2026-07-15')
		);
		self::assertFalse(
			$this->guard->isWithinWindow(scheme: 'XXX', sequenceType: 'FRST', collectionDate: '2026-07-15', generationDate: '2026-07-01')
		);
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testInvalidInputsFailClosed()
}//end class
