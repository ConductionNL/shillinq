<?php

/**
 * Unit tests for OssLedgerGuard.
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
 * @spec openspec/changes/bookkeeping-btw-oss-eu/specs/bookkeeping-btw-oss-eu/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\OssLedgerGuard;
use PHPUnit\Framework\TestCase;

/**
 * Covers REQ-OSS-003 (per-country ledger segregation + BTW-aangifte exclusion).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssLedgerGuardTest extends TestCase {

	/**
	 * The guard under test.
	 *
	 * @var OssLedgerGuard
	 */
	private OssLedgerGuard $guard;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new OssLedgerGuard();

	}//end setUp()

	/**
	 * Each country resolves to a dedicated 8xxx revenue + 1525 OSS-VAT account pair (REQ-OSS-003).
	 *
	 * @return void
	 */
	public function testAccountsForCountry(): void {
		$it = $this->guard->accountsForCountry('IT');
		self::assertStringStartsWith('8', $it['revenue']['accountNumber']);
		self::assertSame('Omzet OSS IT', $it['revenue']['name']);
		self::assertSame('1525', $it['payable']['accountNumber']);
		self::assertSame('BTW af te dragen OSS IT', $it['payable']['name']);

		// Different countries get distinct revenue accounts.
		$de = $this->guard->accountsForCountry('DE');
		self::assertNotSame($it['revenue']['accountNumber'], $de['revenue']['accountNumber']);
		// Stable across calls.
		self::assertSame($it['revenue']['accountNumber'], $this->guard->accountsForCountry('IT')['revenue']['accountNumber']);

	}//end testAccountsForCountry()

	/**
	 * OSS VAT accounts are recognised by name (REQ-OSS-003).
	 *
	 * @return void
	 */
	public function testIsOssVatAccount(): void {
		self::assertTrue($this->guard->isOssVatAccount(['accountNumber' => '1525', 'name' => 'BTW af te dragen OSS IT']));
		self::assertFalse($this->guard->isOssVatAccount(['accountNumber' => '1500', 'name' => 'Te betalen BTW']));
		self::assertFalse($this->guard->isOssVatAccount(['accountNumber' => '8210', 'name' => 'Omzet OSS IT']));

	}//end testIsOssVatAccount()

	/**
	 * A clean BTW-aangifte passes the assertion (REQ-OSS-003).
	 *
	 * @return void
	 */
	public function testAssertNoOssAccountsPassesWhenClean(): void {
		$clean = [
			['accountNumber' => '1500', 'name' => 'Te betalen BTW'],
			['accountNumber' => '8100', 'name' => 'Omzet'],
		];
		self::assertTrue($this->guard->assertNoOssAccountsOnBtwReturn($clean));

	}//end testAssertNoOssAccountsPassesWhenClean()

	/**
	 * An OSS VAT account on a regular BTW return throws (REQ-OSS-003 second scenario).
	 *
	 * @return void
	 */
	public function testAssertNoOssAccountsThrowsOnContamination(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('REQ-OSS-003');
		$contaminated = [
			['accountNumber' => '1500', 'name' => 'Te betalen BTW'],
			['accountNumber' => '1525', 'name' => 'BTW af te dragen OSS DE'],
		];
		$this->guard->assertNoOssAccountsOnBtwReturn($contaminated);

	}//end testAssertNoOssAccountsThrowsOnContamination()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
