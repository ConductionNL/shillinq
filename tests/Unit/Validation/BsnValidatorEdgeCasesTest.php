<?php

/**
 * Edge-case unit tests for BsnValidator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Validation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-detachering-payroll-administratie/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use OCA\Shillinq\Validation\BsnValidator;
use PHPUnit\Framework\TestCase;

/**
 * Whitespace handling, the zero-sum rule, the -1 weight and mask lengths.
 *
 * @covers \OCA\Shillinq\Validation\BsnValidator
 */
final class BsnValidatorEdgeCasesTest extends TestCase {

	/**
	 * Surrounding whitespace is trimmed before the 11-proef runs.
	 *
	 * @return void
	 */
	public function testSurroundingWhitespaceIsTrimmed(): void {
		self::assertTrue(BsnValidator::isValid(' 123456782 '));
		self::assertTrue(BsnValidator::isValid("\t123456782\n"));
		self::assertTrue(BsnValidator::isValid(null));
	}//end testSurroundingWhitespaceIsTrimmed()

	/**
	 * Inner whitespace, signs and separators make the value malformed.
	 *
	 * @return void
	 */
	public function testInnerSeparatorsAreRejected(): void {
		self::assertFalse(BsnValidator::isValid('1234 56782'));
		self::assertFalse(BsnValidator::isValid('1234.56.782'));
		self::assertFalse(BsnValidator::isValid('-12345678'));
		self::assertFalse(BsnValidator::isValid('+12345678'));
	}//end testInnerSeparatorsAreRejected()

	/**
	 * A weighted sum of exactly zero is rejected even though 0 % 11 === 0.
	 *
	 * @return void
	 */
	public function testZeroWeightedSumIsRejected(): void {
		// 1*9 + 9*-1 = 0.
		self::assertFalse(BsnValidator::isValid('100000009'));
	}//end testZeroWeightedSumIsRejected()

	/**
	 * The ninth digit counts with weight -1, not +1.
	 *
	 * @return void
	 */
	public function testNinthDigitHasNegativeWeight(): void {
		// 9..2 weights give 156 for 12345678; +9 = 165 (11*15) but -9 = 147.
		self::assertFalse(BsnValidator::isValid('123456789'));
		// 9 * (9+8+7+6+5+4+3+2) - 0 = 396 = 11 * 36.
		self::assertTrue(BsnValidator::isValid('999999990'));
		// 1*9 - 2 = 7; with a +1 weight it would be 11.
		self::assertFalse(BsnValidator::isValid('100000002'));
	}//end testNinthDigitHasNegativeWeight()

	/**
	 * The leading digits carry descending weights 9..2.
	 *
	 * @return void
	 */
	public function testLeadingDigitsUseDescendingWeights(): void {
		// 1*2 (eighth digit) - 2 = 0: zero sum, rejected.
		self::assertFalse(BsnValidator::isValid('000000012'));
		// 1*2 (eighth) - 9 = -7.
		self::assertFalse(BsnValidator::isValid('000000019'));
		// 1*8 (second) - 8 = 0: zero sum, rejected.
		self::assertFalse(BsnValidator::isValid('010000008'));
		// 1*9 (first) + 1*2 (eighth) - 0 = 11: valid.
		self::assertTrue(BsnValidator::isValid('100000010'));
	}//end testLeadingDigitsUseDescendingWeights()

	/**
	 * Non-ASCII digits never match the nine-digit pattern.
	 *
	 * @return void
	 */
	public function testNonAsciiDigitsAreRejected(): void {
		self::assertFalse(BsnValidator::isValid('１２３４５６７８２'));
	}//end testNonAsciiDigitsAreRejected()

	/**
	 * Masking trims first, then shows exactly the last four characters.
	 *
	 * @return void
	 */
	public function testMaskTrimsAndKeepsLastFour(): void {
		self::assertSame('*****6782', BsnValidator::mask('  123456782  '));
		self::assertSame('*2345', BsnValidator::mask('12345'));
		self::assertSame('', BsnValidator::mask("  \t "));
		self::assertSame('', BsnValidator::mask(null));
	}//end testMaskTrimsAndKeepsLastFour()

	/**
	 * Values of four characters or fewer are masked entirely.
	 *
	 * @return void
	 */
	public function testMaskOfShortValuesHidesEverything(): void {
		self::assertSame('*', BsnValidator::mask('9'));
		self::assertSame('***', BsnValidator::mask(' 123 '));
		self::assertSame('****', BsnValidator::mask('abcd'));
	}//end testMaskOfShortValuesHidesEverything()

	/**
	 * The masked form keeps the input length and never shows a leading digit.
	 *
	 * @return void
	 */
	public function testMaskPreservesLengthAndHidesPrefix(): void {
		$masked = BsnValidator::mask('987654321');
		self::assertSame(9, strlen($masked));
		self::assertSame('*****4321', $masked);
		self::assertStringNotContainsString('98765', $masked);
	}//end testMaskPreservesLengthAndHidesPrefix()
}//end class
