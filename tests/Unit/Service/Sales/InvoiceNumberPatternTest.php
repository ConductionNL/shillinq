<?php

/**
 * Unit tests for InvoiceNumberPattern.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-issue-controls/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales;

use InvalidArgumentException;
use OCA\Shillinq\Service\Sales\InvoiceNumberPattern;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SIIC-001: the pattern of a series formats and reads its numbers.
 *
 * @covers \OCA\Shillinq\Service\Sales\InvoiceNumberPattern
 */
class InvoiceNumberPatternTest extends TestCase {
	/**
	 * The default pattern pads the counter to four digits after the year.
	 *
	 * @return void
	 */
	public function testTheDefaultPatternGivesYearDashFourDigits(): void {
		$this->assertSame('2026-0042', (new InvoiceNumberPattern())->format(year: 2026, sequence: 42));
	}//end testTheDefaultPatternGivesYearDashFourDigits()

	/**
	 * Literal text stays, and a counter wider than the padding is not cut.
	 *
	 * @return void
	 */
	public function testLiteralTextStaysAndAWideCounterIsNotCut(): void {
		$pattern = new InvoiceNumberPattern(pattern: 'VD/{year}/{seq:3}');

		$this->assertSame('VD/2026/007', $pattern->format(year: 2026, sequence: 7));
		$this->assertSame('VD/2026/1234', $pattern->format(year: 2026, sequence: 1234));
	}//end testLiteralTextStaysAndAWideCounterIsNotCut()

	/**
	 * A number of the pattern reads back as its counter, of that year only.
	 *
	 * @return void
	 */
	public function testANumberReadsBackAsItsCounterOfThatYearOnly(): void {
		$pattern = new InvoiceNumberPattern(pattern: 'BIL-{year}-{seq:4}');

		$this->assertSame(41, $pattern->sequenceOf(number: 'BIL-2026-0041', year: 2026));
		$this->assertSame(41, $pattern->sequenceOf(number: ' BIL-2026-0041 ', year: 2026));
		$this->assertNull($pattern->sequenceOf(number: 'BIL-2025-0041', year: 2026));
		$this->assertNull($pattern->sequenceOf(number: 'DRAFT-20260301-ab12cd34', year: 2026));
		$this->assertNull($pattern->sequenceOf(number: 'BIL-2026-41', year: 2026));
	}//end testANumberReadsBackAsItsCounterOfThatYearOnly()

	/**
	 * A pattern without exactly one counter is refused.
	 *
	 * @return void
	 */
	public function testAPatternWithoutExactlyOneCounterIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('needs exactly one {seq:N}');

		new InvoiceNumberPattern(pattern: '{year}-{seq:2}-{seq:2}');
	}//end testAPatternWithoutExactlyOneCounterIsRefused()
}//end class
