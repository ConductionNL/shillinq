<?php

/**
 * Edge-case unit tests for IbanValidator.
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
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use OCA\Shillinq\Validation\IbanValidator;
use PHPUnit\Framework\TestCase;

/**
 * Normalisation, per-country lengths, unlisted countries and long bodies.
 *
 * @covers \OCA\Shillinq\Validation\IbanValidator
 */
final class IbanValidatorEdgeCasesTest extends TestCase {

	/**
	 * Tabs and newlines are stripped like spaces before validation.
	 *
	 * @return void
	 */
	public function testAnyWhitespaceIsStripped(): void {
		self::assertTrue(IbanValidator::isValid("NL91\tABNA\n0417 1643\r\n00"));
		self::assertTrue(IbanValidator::isValid('  NL91ABNA0417164300  '));
	}//end testAnyWhitespaceIsStripped()

	/**
	 * Separators other than whitespace are not stripped and fail the pattern.
	 *
	 * @return void
	 */
	public function testNonWhitespaceSeparatorsAreRejected(): void {
		self::assertFalse(IbanValidator::isValid('NL91-ABNA-0417-1643-00'));
		self::assertFalse(IbanValidator::isValid('NL91.ABNA.0417.1643.00'));
	}//end testNonWhitespaceSeparatorsAreRejected()

	/**
	 * Long 24/27-character IBANs pass, exercising the chunked mod-97.
	 *
	 * @return void
	 */
	public function testLongIbansPassChunkedMod97(): void {
		self::assertTrue(IbanValidator::isValid('FR1420041010050500013M02606'));
		self::assertTrue(IbanValidator::isValid('IT60X0542811101000000123456'));
		self::assertTrue(IbanValidator::isValid('ES9121000418450200051332'));
	}//end testLongIbansPassChunkedMod97()

	/**
	 * A single changed digit in a long IBAN breaks the checksum.
	 *
	 * @return void
	 */
	public function testLongIbanWithChangedDigitIsRejected(): void {
		self::assertFalse(IbanValidator::isValid('FR1420041010050500013M02607'));
		self::assertFalse(IbanValidator::isValid('IT60X0542811101000000123457'));
	}//end testLongIbanWithChangedDigitIsRejected()

	/**
	 * A listed country with the wrong length fails even before the checksum.
	 *
	 * @return void
	 */
	public function testListedCountryLengthIsEnforced(): void {
		// Valid DE IBAN with one trailing digit added: 23 instead of 22.
		self::assertFalse(IbanValidator::isValid('DE893704004405320130000'));
		// Valid BE IBAN with the last digit dropped: 15 instead of 16.
		self::assertFalse(IbanValidator::isValid('BE6853900754703'));
	}//end testListedCountryLengthIsEnforced()

	/**
	 * An unlisted country skips the length check but still needs mod-97.
	 *
	 * @return void
	 */
	public function testUnlistedCountryReliesOnChecksumOnly(): void {
		self::assertTrue(IbanValidator::isValid('GB82WEST12345698765432'));
		self::assertTrue(IbanValidator::isValid('CH9300762011623852957'));
		self::assertFalse(IbanValidator::isValid('GB83WEST12345698765432'));
	}//end testUnlistedCountryReliesOnChecksumOnly()

	/**
	 * Lower-case input is upper-cased before letters are expanded.
	 *
	 * @return void
	 */
	public function testLowerCaseIsNormalised(): void {
		self::assertTrue(IbanValidator::isValid('fr1420041010050500013m02606'));
		self::assertTrue(IbanValidator::isValid('gb82west12345698765432'));
	}//end testLowerCaseIsNormalised()

	/**
	 * Country code and check digits must be two letters then two digits.
	 *
	 * @return void
	 */
	public function testHeaderShapeIsEnforced(): void {
		self::assertFalse(IbanValidator::isValid('9L91ABNA0417164300'));
		self::assertFalse(IbanValidator::isValid('NL9AABNA0417164300'));
		self::assertFalse(IbanValidator::isValid('NL91'));
		self::assertFalse(IbanValidator::isValid('   '));
	}//end testHeaderShapeIsEnforced()
}//end class
