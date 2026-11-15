<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoicingTailChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EN 16931 BR-AE-02: a reverse-charged invoice identifies the seller and the buyer.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoicingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class ReverseChargePartiesTest extends TestCase {

	/**
	 * Invoices and whether BR-AE-02 holds for them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function invoices(): array {
		$reverseCharged = ['invoiceLines' => [['vatCategory' => 'S'], ['vatCategory' => 'AE']]];

		return [
			'no reverse-charged line'               => [['invoiceLines' => [['vatCategory' => 'S']]], true],
			'seller VAT id, no buyer id'            => [$reverseCharged + ['sellerVatId' => 'NL123456789B01'], false],
			'seller VAT id, blank buyer VAT id'     => [$reverseCharged + ['sellerVatId' => 'NL123456789B01', 'buyerVatId' => ''], false],
			'seller VAT id and buyer VAT id'        => [$reverseCharged + ['sellerVatId' => 'NL123456789B01', 'buyerVatId' => 'DE123456789'], true],
			'seller VAT id and buyer legal reg id'  => [$reverseCharged + ['sellerVatId' => 'NL123456789B01', 'buyerLegalRegId' => 'HRB 1'], true],
			'seller tax reg id and buyer VAT id'    => [$reverseCharged + ['sellerTaxRegId' => 'KVK 1', 'buyerVatId' => 'DE123456789'], true],
			'seller tax rep VAT id and buyer VAT id' => [$reverseCharged + ['sellerTaxRepVatId' => 'NL999', 'buyerVatId' => 'DE123456789'], true],
			'buyer VAT id, no seller id'            => [$reverseCharged + ['buyerVatId' => 'DE123456789'], false],
			'neither party identified'              => [$reverseCharged, false],
		];

	}//end invoices()

	/**
	 * BR-AE-02 holds exactly when both parties are identified on a reverse-charged invoice.
	 *
	 * @param array<string, mixed> $invoice  The ARInvoice.
	 * @param bool                 $expected Whether the rule is satisfied.
	 *
	 * @return void
	 */
	#[DataProvider('invoices')]
	public function testReverseChargeNeedsBothParties(array $invoice, bool $expected): void {
		$check = InvoicingTailChecks::checks()['ARInvoice']['en16931-br-ae-02'];

		$this->assertSame($expected, $check($invoice, []));

	}//end testReverseChargeNeedsBothParties()
}//end class
