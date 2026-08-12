<?php

/**
 * Unit tests for OssPaymentReconciliation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\OssPaymentReconciliation;
use PHPUnit\Framework\TestCase;

/**
 * Covers REQ-OSS-008 (payment matching + per-country distribution reconciliation).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class OssPaymentReconciliationTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var OssPaymentReconciliation
	 */
	private OssPaymentReconciliation $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new OssPaymentReconciliation();

	}//end setUp()

	/**
	 * A transaction matches when amount + IBAN line up to the cent (REQ-OSS-008).
	 *
	 * @return void
	 */
	public function testMatches(): void {
		$return = ['totalVatAmount' => 4732.18];
		$tx = ['amount' => 4732.18, 'ibanTo' => 'NL86 INGB 0002 4455 88'];
		self::assertTrue($this->service->matches($return, $tx, 'NL86INGB0002445588'));

		// Off by a cent -> no match.
		self::assertFalse($this->service->matches($return, ['amount' => 4732.17, 'ibanTo' => 'NL86INGB0002445588'], 'NL86INGB0002445588'));
		// Wrong IBAN -> no match.
		self::assertFalse($this->service->matches($return, ['amount' => 4732.18, 'ibanTo' => 'NL00OTHER'], 'NL86INGB0002445588'));

	}//end testMatches()

	/**
	 * Marking paid requires a linked bank transaction and an equal amount (REQ-OSS-008).
	 *
	 * @return void
	 */
	public function testCanMarkPaid(): void {
		$return = ['totalVatAmount' => 4732.18];
		self::assertTrue($this->service->canMarkPaid(['bankTransactionId' => 'tx-1', 'amount' => 4732.18], $return));
		self::assertFalse($this->service->canMarkPaid(['amount' => 4732.18], $return));
		self::assertFalse($this->service->canMarkPaid(['bankTransactionId' => 'tx-1', 'amount' => 4000.0], $return));

	}//end testCanMarkPaid()

	/**
	 * A matching distribution reconciles; a divergence surfaces the difference (REQ-OSS-008).
	 *
	 * @return void
	 */
	public function testReconcileDistribution(): void {
		$return = [
			'lineItems' => [
				['countryCode' => 'DE', 'vatAmount' => 1802.0],
				['countryCode' => 'FR', 'vatAmount' => 1440.0],
				['countryCode' => 'IT', 'vatAmount' => 1490.18],
			],
		];

		$ok = $this->service->reconcileDistribution($return, ['DE' => 1802.0, 'FR' => 1440.0, 'IT' => 1490.18]);
		self::assertSame('reconciled', $ok['status']);
		self::assertEmpty($ok['differences']);

		$bad = $this->service->reconcileDistribution($return, ['DE' => 1800.0, 'FR' => 1440.0, 'IT' => 1490.18]);
		self::assertSame('discrepancy', $bad['status']);
		self::assertEqualsWithDelta(-2.0, $bad['differences']['DE'], 0.001);

	}//end testReconcileDistribution()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
