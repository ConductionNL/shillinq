<?php

/**
 * Supplemental unit tests for OssLedgerGuard's edge branches.
 *
 * OssLedgerGuardTest pins the main two-letter resolution, the VAT-account
 * discriminator and the contamination assertion. This file covers the
 * branches it does not reach: the short/blank country code that falls back to
 * a zero offset (so the revenue account is the base number), the code
 * normalisation (trim + uppercase) feeding both the number and the name, and
 * the VAT-account check on an account whose name key is absent.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * @covers \OCA\Shillinq\Guard\OssLedgerGuard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\OssLedgerGuard;
use PHPUnit\Framework\TestCase;

final class OssLedgerGuardShortCodeTest extends TestCase {
	private OssLedgerGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new OssLedgerGuard();
	}//end setUp()

	/**
	 * A code shorter than two letters takes the zero-offset fallback, so the
	 * revenue account is the base 8200 and the payable account is the family head.
	 *
	 * @return void
	 */
	public function testShortCodeFallsBackToTheBaseAccount(): void {
		foreach (['', 'X'] as $code) {
			$pair = $this->guard->accountsForCountry($code);
			$this->assertSame('8200', $pair['revenue']['accountNumber']);
			$this->assertSame('1525', $pair['payable']['accountNumber']);
		}
	}//end testShortCodeFallsBackToTheBaseAccount()

	/**
	 * The country code is trimmed and upper-cased for both the account name
	 * and the offset, so a padded lower-case code resolves like its canonical form.
	 *
	 * @return void
	 */
	public function testNormalisesTheCountryCode(): void {
		$canonical = $this->guard->accountsForCountry('IT');
		$messy = $this->guard->accountsForCountry('  it  ');

		$this->assertSame($canonical['revenue']['accountNumber'], $messy['revenue']['accountNumber']);
		$this->assertSame('Omzet OSS IT', $messy['revenue']['name']);
		$this->assertSame('BTW af te dragen OSS IT', $messy['payable']['name']);
	}//end testNormalisesTheCountryCode()

	/**
	 * An account with no name key is not an OSS VAT account.
	 *
	 * @return void
	 */
	public function testAccountWithoutNameIsNotOssVat(): void {
		$this->assertFalse($this->guard->isOssVatAccount(['accountNumber' => '8200']));
	}//end testAccountWithoutNameIsNotOssVat()
}//end class
