<?php

/**
 * Unit tests for IbanValidator.
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
 * @spec openspec/changes/bookkeeping-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Validation;

use OCA\Shillinq\Validation\IbanValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ISO 13616 mod-97 IBAN validator (REQ-SDD-005).
 */
class IbanValidatorTest extends TestCase {
	/**
	 * Known-valid IBANs (incl. spaced/lower-case forms) pass (REQ-SDD-005).
	 *
	 * @return void
	 */
	public function testValidIbans(): void {
		self::assertTrue(IbanValidator::isValid('NL91ABNA0417164300'));
		self::assertTrue(IbanValidator::isValid('nl91 abna 0417 1643 00'));
		self::assertTrue(IbanValidator::isValid('DE89370400440532013000'));
		self::assertTrue(IbanValidator::isValid('BE68539007547034'));
	}//end testValidIbans()

	/**
	 * A bad checksum is rejected (REQ-SDD-005).
	 *
	 * @return void
	 */
	public function testBadChecksumRejected(): void {
		self::assertFalse(IbanValidator::isValid('NL92ABNA0417164300'));
	}//end testBadChecksumRejected()

	/**
	 * A wrong length for the country is rejected (REQ-SDD-005).
	 *
	 * @return void
	 */
	public function testWrongLengthRejected(): void {
		self::assertFalse(IbanValidator::isValid('NL91ABNA041716430'));
	}//end testWrongLengthRejected()

	/**
	 * Structurally malformed values are rejected (REQ-SDD-005).
	 *
	 * @return void
	 */
	public function testMalformedRejected(): void {
		self::assertFalse(IbanValidator::isValid(''));
		self::assertFalse(IbanValidator::isValid('1234'));
		self::assertFalse(IbanValidator::isValid('NLAB91ABNA0417164300'));
	}//end testMalformedRejected()
}//end class
