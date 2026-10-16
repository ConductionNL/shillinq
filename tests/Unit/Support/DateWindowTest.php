<?php

/**
 * DateWindowTest — the overlap predicate both schedule rules now share.
 *
 * The two callers used to each carry their own copy and spelled "no end date"
 * differently, so the cases below assert BOTH spellings, null and '', mean the
 * same thing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Support;

use OCA\Shillinq\Support\DateWindow;
use PHPUnit\Framework\TestCase;

/**
 * Covers OCA\Shillinq\Support\DateWindow.
 */
final class DateWindowTest extends TestCase {

	/**
	 * Two closed windows that share days overlap; two that do not, do not.
	 *
	 * @return void
	 */
	public function testClosedWindows(): void {
		self::assertTrue(DateWindow::overlaps('2026-01-01', '2026-06-30', '2026-06-01', '2026-12-31'));
		self::assertFalse(DateWindow::overlaps('2026-01-01', '2026-05-31', '2026-06-01', '2026-12-31'));
	}//end testClosedWindows()

	/**
	 * Touching on a single day is an overlap: a fee cannot be charged twice for
	 * the same day.
	 *
	 * @return void
	 */
	public function testASharedSingleDayOverlaps(): void {
		self::assertTrue(DateWindow::overlaps('2026-01-01', '2026-06-01', '2026-06-01', '2026-12-31'));
	}//end testASharedSingleDayOverlaps()

	/**
	 * Null and '' both mean the window is still open, so both reach forward
	 * into any later window.
	 *
	 * @return void
	 */
	public function testBothSpellingsOfAnOpenEndAreTheSame(): void {
		self::assertTrue(DateWindow::overlaps('2026-01-01', null, '2027-01-01', '2027-12-31'));
		self::assertTrue(DateWindow::overlaps('2026-01-01', '', '2027-01-01', '2027-12-31'));
		self::assertTrue(DateWindow::overlaps('2027-01-01', '2027-12-31', '2026-01-01', null));
		self::assertTrue(DateWindow::overlaps('2027-01-01', '2027-12-31', '2026-01-01', ''));
	}//end testBothSpellingsOfAnOpenEndAreTheSame()

	/**
	 * An open end does not reach BACKWARDS: a window opened later cannot
	 * collide with one that already closed.
	 *
	 * @return void
	 */
	public function testAnOpenEndDoesNotReachBackwards(): void {
		self::assertFalse(DateWindow::overlaps('2027-01-01', null, '2026-01-01', '2026-12-31'));
	}//end testAnOpenEndDoesNotReachBackwards()

	/**
	 * A window with no start is not a window, and nothing overlaps it. Both
	 * callers refuse an empty start before they get here; this pins what the
	 * predicate answers if one ever stops.
	 *
	 * @return void
	 */
	public function testAnAbsentStartOverlapsNothing(): void {
		self::assertFalse(DateWindow::overlaps('', null, '2026-01-01', '2026-12-31'));
		self::assertFalse(DateWindow::overlaps('2026-01-01', null, '', '2026-12-31'));
	}//end testAnAbsentStartOverlapsNothing()
}//end class
