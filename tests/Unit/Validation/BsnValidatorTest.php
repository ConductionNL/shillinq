<?php

/**
 * Unit tests for BsnValidator.
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
 * Covers the Dutch BSN 11-proef and PII masking (REQ-PAY-001 / REQ-PAY-012).
 */
final class BsnValidatorTest extends TestCase {
	/**
	 * Valid BSNs (passing the 11-proef) are accepted.
	 *
	 * @return void
	 */
	public function testValidBsnPassesElevenProof(): void {
		// 111222333: 1*9+1*8+1*7+2*6+2*5+2*4+3*3+3*2+3*-1 = 9+8+7+12+10+8+9+6-3 = 66 (multiple of 11).
		self::assertTrue(BsnValidator::isValid('111222333'));
		// 123456782 is a well-known valid test BSN.
		self::assertTrue(BsnValidator::isValid('123456782'));
	}//end testValidBsnPassesElevenProof()

	/**
	 * A BSN failing the 11-proef is rejected.
	 *
	 * @return void
	 */
	public function testInvalidChecksumRejected(): void {
		// 123456789 sums to 9+16+21+24+25+24+21+16-9 = 147, not a multiple of 11.
		self::assertFalse(BsnValidator::isValid('123456789'));
	}//end testInvalidChecksumRejected()

	/**
	 * Non-9-digit or non-numeric values are rejected.
	 *
	 * @return void
	 */
	public function testMalformedBsnRejected(): void {
		self::assertFalse(BsnValidator::isValid('12345678'));
		self::assertFalse(BsnValidator::isValid('1234567890'));
		self::assertFalse(BsnValidator::isValid('12345678a'));
		self::assertFalse(BsnValidator::isValid('000000000'));
	}//end testMalformedBsnRejected()

	/**
	 * Null / empty BSN is treated as "absent" and accepted (B2B contractors).
	 *
	 * @return void
	 */
	public function testAbsentBsnIsValid(): void {
		self::assertTrue(BsnValidator::isValid(null));
		self::assertTrue(BsnValidator::isValid(''));
		self::assertTrue(BsnValidator::isValid('   '));
	}//end testAbsentBsnIsValid()

	/**
	 * Masking reveals only the trailing four digits.
	 *
	 * @return void
	 */
	public function testMaskRevealsTrailingFour(): void {
		self::assertSame('*****6782', BsnValidator::mask('123456782'));
		self::assertSame('', BsnValidator::mask(null));
		self::assertSame('', BsnValidator::mask(''));
	}//end testMaskRevealsTrailingFour()

	/**
	 * Short values are fully masked so a malformed value cannot leak.
	 *
	 * @return void
	 */
	public function testShortValueFullyMasked(): void {
		self::assertSame('****', BsnValidator::mask('1234'));
		self::assertSame('**', BsnValidator::mask('12'));
	}//end testShortValueFullyMasked()
}//end class
