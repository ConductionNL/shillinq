<?php

/**
 * Unit tests for DashboardRange.
 *
 * The request value object that reads and validates the from/to bounds of a
 * financial-dashboard graph: the both-or-neither rule, the ISO-8601 day shape
 * (optionally with a time suffix), and the null-or-value bounds handed to the
 * service.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * @covers \OCA\Shillinq\Controller\DashboardRange
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\DashboardRange;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class DashboardRangeTest extends TestCase {
	/**
	 * A DashboardRange reading the given from/to parameters.
	 *
	 * @param string|null $from The from param, or null when unset.
	 * @param string|null $to   The to param, or null when unset.
	 *
	 * @return DashboardRange
	 */
	private function range(?string $from, ?string $to): DashboardRange {
		$params = [];
		if ($from !== null) {
			$params['from'] = $from;
		}

		if ($to !== null) {
			$params['to'] = $to;
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		return new DashboardRange($request);
	}//end range()

	/**
	 * No bounds at all is valid and yields two null bounds.
	 *
	 * @return void
	 */
	public function testEmptyRangeIsValidAndNull(): void {
		$range = $this->range(null, null);
		$this->assertNull($range->error());
		$this->assertNull($range->fromBound());
		$this->assertNull($range->toBound());
	}//end testEmptyRangeIsValidAndNull()

	/**
	 * One bound without the other is refused.
	 *
	 * @return void
	 */
	public function testOneBoundWithoutTheOtherIsRefused(): void {
		$this->assertSame('from and to must be provided together', $this->range('2026-01-01', '')->error());
		$this->assertSame('from and to must be provided together', $this->range('', '2026-01-31')->error());
	}//end testOneBoundWithoutTheOtherIsRefused()

	/**
	 * A well-formed pair passes and the bounds come back as given.
	 *
	 * @return void
	 */
	public function testValidPairPasses(): void {
		$range = $this->range('2026-01-01', '2026-03-31T23:59:59Z');
		$this->assertNull($range->error());
		$this->assertSame('2026-01-01', $range->fromBound());
		$this->assertSame('2026-03-31T23:59:59Z', $range->toBound());
	}//end testValidPairPasses()

	/**
	 * A malformed from is reported before to; a malformed to is reported next.
	 *
	 * @return void
	 */
	public function testMalformedBoundsAreReportedInOrder(): void {
		$this->assertSame('from must be an ISO-8601 date', $this->range('nope', '2026-01-31')->error());
		$this->assertSame('to must be an ISO-8601 date', $this->range('2026-01-01', '2026-13')->error());
	}//end testMalformedBoundsAreReportedInOrder()

	/**
	 * The bounds are trimmed before they are read.
	 *
	 * @return void
	 */
	public function testBoundsAreTrimmed(): void {
		$range = $this->range('  2026-01-01  ', '  2026-02-01  ');
		$this->assertNull($range->error());
		$this->assertSame('2026-01-01', $range->fromBound());
	}//end testBoundsAreTrimmed()
}//end class
