<?php

/**
 * Unit tests for TokenValidator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Util
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Util;

use OCA\Shillinq\Util\TokenValidator;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the confirmation-token generator + verifier.
 */
final class TokenValidatorTest extends TestCase {

	/**
	 * Generated tokens are 32 URL-safe base62 characters by default.
	 */
	public function testGenerateDefaultLengthIs32(): void {
		$token = TokenValidator::generate();
		self::assertSame(32, strlen($token));
		self::assertMatchesRegularExpression('/^[0-9a-zA-Z]{32}$/', $token);

	}//end testGenerateDefaultLengthIs32()

	/**
	 * Generate honours a custom length.
	 */
	public function testGenerateRespectsLength(): void {
		$token = TokenValidator::generate(48);
		self::assertSame(48, strlen($token));

	}//end testGenerateRespectsLength()

	/**
	 * Two consecutive generations differ — entropy sanity-check.
	 */
	public function testGenerateProducesDistinctValues(): void {
		self::assertNotSame(TokenValidator::generate(), TokenValidator::generate());

	}//end testGenerateProducesDistinctValues()

	/**
	 * Hash + verify round-trip — the canonical happy path.
	 */
	public function testHashVerifyRoundTrip(): void {
		$plaintext = TokenValidator::generate();
		$hash = TokenValidator::hash($plaintext);

		self::assertNotSame($plaintext, $hash);
		self::assertTrue(TokenValidator::verify($plaintext, $hash));

	}//end testHashVerifyRoundTrip()

	/**
	 * Verify rejects the wrong plaintext.
	 */
	public function testVerifyRejectsWrongToken(): void {
		$plaintext = TokenValidator::generate();
		$hash = TokenValidator::hash($plaintext);
		self::assertFalse(TokenValidator::verify('wrong' . $plaintext, $hash));

	}//end testVerifyRejectsWrongToken()

	/**
	 * Verify rejects empty inputs (defence-in-depth).
	 */
	public function testVerifyRejectsEmptyInputs(): void {
		self::assertFalse(TokenValidator::verify('', 'anything'));
		self::assertFalse(TokenValidator::verify('plaintext', ''));

	}//end testVerifyRejectsEmptyInputs()

	/**
	 * `isExpired` returns FALSE when `expiresAt` is in the future.
	 */
	public function testIsExpiredFalseForFutureTimestamp(): void {
		self::assertFalse(
			TokenValidator::isExpired('2099-01-01T00:00:00Z', '2026-06-06T00:00:00Z')
		);

	}//end testIsExpiredFalseForFutureTimestamp()

	/**
	 * `isExpired` returns TRUE when `expiresAt` <= now.
	 */
	public function testIsExpiredTrueForPastOrNowTimestamp(): void {
		self::assertTrue(
			TokenValidator::isExpired('2020-01-01T00:00:00Z', '2026-06-06T00:00:00Z')
		);
		self::assertTrue(
			TokenValidator::isExpired('2026-06-06T00:00:00Z', '2026-06-06T00:00:00Z')
		);

	}//end testIsExpiredTrueForPastOrNowTimestamp()

	/**
	 * `isExpired` fails closed on unparseable timestamps (CWE-863).
	 */
	public function testIsExpiredFailsClosedOnGarbage(): void {
		self::assertTrue(TokenValidator::isExpired('not a date', '2026-06-06T00:00:00Z'));
		self::assertTrue(TokenValidator::isExpired('2026-06-06T00:00:00Z', 'also garbage'));

	}//end testIsExpiredFailsClosedOnGarbage()

	/**
	 * `expiresAtFor` adds the default TTL (7 days) and emits ISO 8601 UTC.
	 */
	public function testExpiresAtForDefaultsToSevenDays(): void {
		$expires = TokenValidator::expiresAtFor('2026-06-06T00:00:00Z');
		self::assertSame('2026-06-13T00:00:00Z', $expires);

	}//end testExpiresAtForDefaultsToSevenDays()

	/**
	 * `expiresAtFor` honours custom TTL seconds.
	 */
	public function testExpiresAtForCustomTtl(): void {
		$expires = TokenValidator::expiresAtFor('2026-06-06T12:00:00Z', 3600);
		self::assertSame('2026-06-06T13:00:00Z', $expires);

	}//end testExpiresAtForCustomTtl()

	/**
	 * `expiresAtFor` throws on unparseable input — caller decides recovery.
	 */
	public function testExpiresAtForThrowsOnGarbage(): void {
		$this->expectException(\InvalidArgumentException::class);
		TokenValidator::expiresAtFor('garbage');

	}//end testExpiresAtForThrowsOnGarbage()

}//end class
