<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\CheckFields;
use PHPUnit\Framework\TestCase;

/**
 * Pins the field readers the rule checks share.
 *
 * @covers \OCA\Shillinq\Standards\Checks\CheckFields
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class CheckFieldsTest extends TestCase {

	/**
	 * A field is present when it is set and not blank.
	 *
	 * @return void
	 */
	public function testPresent(): void {
		$o = ['a' => 'x', 'b' => '  ', 'c' => null, 'd' => 0];
		self::assertTrue(CheckFields::present($o, 'a'));
		self::assertFalse(CheckFields::present($o, 'b'));
		self::assertFalse(CheckFields::present($o, 'c'));
		self::assertTrue(CheckFields::present($o, 'd'));
		self::assertFalse(CheckFields::present($o, 'missing'));

	}//end testPresent()

	/**
	 * The boolean readers over every boolean-ish value and a few that are not.
	 *
	 * @return void
	 */
	public function testBooleanReaders(): void {
		$cases = [
			'true' => [true, 'TB', 'F'],
			'false' => [false, 'FB', 'T'],
			'one' => [1, 'TB', 'F'],
			'zero' => [0, 'FB', 'T'],
			'string one' => ['1', 'TB', 'F'],
			'string zero' => ['0', 'FB', 'T'],
			'string true' => ['true', 'TB', 'F'],
			'string false' => ['false', 'FB', 'T'],
			'float one' => [1.0, 'F-', 'F'],
			'yes' => ['yes', 'F-', 'F'],
			'null' => [null, 'F-', 'F'],
		];
		foreach ($cases as $label => [$value, $trueBool, $false]) {
			$o = ['f' => $value];
			$actual = (CheckFields::isTrue($o, 'f') ? 'T' : 'F')
				. (CheckFields::isBool($o, 'f') ? 'B' : '-');
			self::assertSame($trueBool, $actual, $label);
			self::assertSame($false, CheckFields::isFalse($o, 'f') ? 'T' : 'F', $label);
		}

		self::assertFalse(CheckFields::isTrue([], 'f'));
		self::assertFalse(CheckFields::isBool([], 'f'));
		self::assertFalse(CheckFields::isFalse([], 'f'));

	}//end testBooleanReaders()

	/**
	 * A numeric value reads as a float, anything else as 0.0.
	 *
	 * @return void
	 */
	public function testNumericVal(): void {
		self::assertSame(12.5, CheckFields::numericVal(['n' => '12.5'], 'n'));
		self::assertSame(3.0, CheckFields::numericVal(['n' => 3], 'n'));
		self::assertSame(0.0, CheckFields::numericVal(['n' => 'abc'], 'n'));
		self::assertSame(0.0, CheckFields::numericVal([], 'n'));

	}//end testNumericVal()

	/**
	 * A field's date is read from its first ten characters; blank or bad is null.
	 *
	 * @return void
	 */
	public function testDate(): void {
		self::assertSame('2026-03-31', CheckFields::date(['d' => ' 2026-03-31T23:59:59Z'], 'd')?->format('Y-m-d'));
		self::assertNull(CheckFields::date(['d' => ' '], 'd'));
		self::assertNull(CheckFields::date(['d' => 'not a date'], 'd'));
		self::assertNull(CheckFields::date([], 'd'));

	}//end testDate()

	/**
	 * A date string parses whole; blank or bad is null.
	 *
	 * @return void
	 */
	public function testParseDate(): void {
		self::assertSame('2026-03-31 12:00', CheckFields::parseDate(' 2026-03-31 12:00 ')?->format('Y-m-d H:i'));
		self::assertNull(CheckFields::parseDate('   '));
		self::assertNull(CheckFields::parseDate('not a date'));

	}//end testParseDate()
}//end class
