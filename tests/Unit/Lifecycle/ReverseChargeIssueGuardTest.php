<?php

/**
 * Unit tests for ReverseChargeIssueGuard: a reverse-charged invoice without a
 * buyer VAT number is not issued, and the guard runs before the booking.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\ReverseChargeIssueGuard;
use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The issue guard of a reverse-charged invoice (REQ-ICR-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ReverseChargeIssueGuard::class)]
#[UsesClass(ReverseChargeNotice::class)]
final class ReverseChargeIssueGuardTest extends TestCase {

	/**
	 * An invoice with one line in a VAT category, to a buyer with this number.
	 *
	 * @param string $category The line's VAT category.
	 * @param string $buyerVat The buyer VAT number.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(string $category, string $buyerVat): array {
		return [
			'invoiceNumber' => '2026-0101',
			'lifecycleState' => 'issued',
			'buyerVatId' => $buyerVat,
			'invoiceLines' => [['lineId' => '1', 'vatCategory' => $category, 'netAmount' => 12000.0]],
		];
	}//end invoice()

	/**
	 * A reverse-charged invoice without the buyer's number is refused, saying what is missing.
	 *
	 * @return void
	 */
	public function testAMissingBuyerNumberIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("The buyer's VAT number is required for reverse charge.");

		(new ReverseChargeIssueGuard())->execute(self::invoice('K', ''), [], [], 'issue');
	}//end testAMissingBuyerNumberIsRefused()

	/**
	 * A domestic reverse charge needs the number too.
	 *
	 * @return void
	 */
	public function testADomesticReverseChargeNeedsTheNumberToo(): void {
		$this->expectException(RuntimeException::class);
		(new ReverseChargeIssueGuard())->execute(self::invoice('AE', '  '), [], [], 'issue');
	}//end testADomesticReverseChargeNeedsTheNumberToo()

	/**
	 * With the number, or without reverse charge, the invoice passes unchanged.
	 *
	 * @return void
	 */
	public function testAnInvoiceWithTheNumberOrWithoutReverseChargePasses(): void {
		$guard = new ReverseChargeIssueGuard();
		$withNumber = self::invoice('K', 'BE0123456749');
		$standard = self::invoice('S', '');

		$this->assertSame($withNumber, $guard->execute($withNumber, [], [], 'issue'));
		$this->assertSame($standard, $guard->execute($standard, [], [], 'issue'));
	}//end testAnInvoiceWithTheNumberOrWithoutReverseChargePasses()

	/**
	 * The issue transition runs the guard before anything is booked.
	 *
	 * @return void
	 */
	public function testTheIssueTransitionRunsTheGuardBeforeTheBooking(): void {
		$path = __DIR__ . '/../../../lib/Settings/register.d/add-shillinq-bookkeeping-compliance.json';
		$fragment = json_decode((string)file_get_contents($path), true);
		$actions = array_map(
			static fn (array $action): string => (string)$action['action'],
			$fragment['components']['schemas']['ARInvoice']['x-openregister-lifecycle']['transitions']['issue']['actions']
		);

		$guard = array_search(ReverseChargeIssueGuard::class, $actions, true);
		$booking = array_search('OCA\\Shillinq\\Lifecycle\\Action\\MaterialiseGlTransactionAction', $actions, true);
		$this->assertIsInt($guard, 'the issue transition declares the guard');
		$this->assertIsInt($booking);
		$this->assertLessThan($booking, $guard, 'the guard runs before the invoice is booked');
	}//end testTheIssueTransitionRunsTheGuardBeforeTheBooking()
}//end class
