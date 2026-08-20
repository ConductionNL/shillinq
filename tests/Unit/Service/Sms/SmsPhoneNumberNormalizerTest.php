<?php

/**
 * Unit tests for SmsPhoneNumberNormalizer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sms;

use OCA\Shillinq\Service\Sms\SmsPhoneNumberNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies E.164/NL validation, normalization and PII-safe masking.
 */
final class SmsPhoneNumberNormalizerTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var SmsPhoneNumberNormalizer
	 */
	private SmsPhoneNumberNormalizer $normalizer;

	/**
	 * Set up the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->normalizer = new SmsPhoneNumberNormalizer();

	}//end setUp()

	/**
	 * Valid NL and E.164 numbers are accepted; junk is rejected.
	 *
	 * @return void
	 */
	public function testIsValid(): void {
		self::assertTrue($this->normalizer->isValid('+31612345678'));
		self::assertTrue($this->normalizer->isValid('0612345678'));
		self::assertTrue($this->normalizer->isValid('06 12 34 56 78'));
		self::assertTrue($this->normalizer->isValid('+44 7700 900123'));

		self::assertFalse($this->normalizer->isValid('12345'));
		self::assertFalse($this->normalizer->isValid(''));
		self::assertFalse($this->normalizer->isValid('not-a-number'));
		self::assertFalse($this->normalizer->isValid('+0123456789'));

	}//end testIsValid()

	/**
	 * Domestic NL numbers normalize to +31; E.164 passes through; junk → null.
	 *
	 * @return void
	 */
	public function testToE164(): void {
		self::assertSame('+31612345678', $this->normalizer->toE164('0612345678'));
		self::assertSame('+31612345678', $this->normalizer->toE164('06-12 34 56 78'));
		self::assertSame('+31612345678', $this->normalizer->toE164('+31612345678'));
		self::assertNull($this->normalizer->toE164('12345'));
		self::assertNull($this->normalizer->toE164(''));

	}//end testToE164()

	/**
	 * Masking keeps the country prefix and last two digits, hides the middle,
	 * and never returns the full number.
	 *
	 * @return void
	 */
	public function testMask(): void {
		$masked = $this->normalizer->mask('+31612345678');
		self::assertStringStartsWith('+31', $masked);
		self::assertStringEndsWith('78', $masked);
		self::assertStringContainsString('*', $masked);
		self::assertStringNotContainsString('612345', $masked);
		self::assertSame(strlen('+31612345678'), strlen($masked));

		self::assertSame('', $this->normalizer->mask(''));

	}//end testMask()

	/**
	 * Separator stripping removes spaces, dashes, dots, slashes, parentheses.
	 *
	 * @return void
	 */
	public function testStripSeparators(): void {
		self::assertSame('+31612345678', $this->normalizer->stripSeparators('+31 (6) 12-34.56/78'));

	}//end testStripSeparators()

}//end class
