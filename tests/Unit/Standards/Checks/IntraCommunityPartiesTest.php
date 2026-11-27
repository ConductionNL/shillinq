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
 * EN 16931 BR-IC-02: an intra-community supply carries the seller and the buyer VAT id.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoicingTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class IntraCommunityPartiesTest extends TestCase {

	/**
	 * Invoices and whether BR-IC-02 holds for them.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function invoices(): array {
		$intraCommunity = ['invoiceLines' => [['vatCategory' => 'S'], ['vatCategory' => 'K']]];

		return [
			'no intra-community line'               => [['invoiceLines' => [['vatCategory' => 'S']]], true],
			'seller VAT id, no buyer VAT id'        => [$intraCommunity + ['sellerVatId' => 'NL123456789B01'], false],
			'seller VAT id, blank buyer VAT id'     => [$intraCommunity + ['sellerVatId' => 'NL123456789B01', 'buyerVatId' => ''], false],
			'seller VAT id, buyer legal reg id only' => [$intraCommunity + ['sellerVatId' => 'NL123456789B01', 'buyerLegalRegId' => 'HRB 1'], false],
			'seller VAT id and buyer VAT id'        => [$intraCommunity + ['sellerVatId' => 'NL123456789B01', 'buyerVatId' => 'DE123456789'], true],
			'seller tax rep VAT id and buyer VAT id' => [$intraCommunity + ['sellerTaxRepVatId' => 'NL999', 'buyerVatId' => 'DE123456789'], true],
			'seller tax reg id and buyer VAT id'    => [$intraCommunity + ['sellerTaxRegId' => 'KVK 1', 'buyerVatId' => 'DE123456789'], false],
			'buyer VAT id, no seller VAT id'        => [$intraCommunity + ['buyerVatId' => 'DE123456789'], false],
		];

	}//end invoices()

	/**
	 * BR-IC-02 holds exactly when both VAT ids are present on an intra-community supply.
	 *
	 * @param array<string, mixed> $invoice  The ARInvoice.
	 * @param bool                 $expected Whether the rule is satisfied.
	 *
	 * @return void
	 */
	#[DataProvider('invoices')]
	public function testIntraCommunitySupplyNeedsBothVatIds(array $invoice, bool $expected): void {
		$check = InvoicingTailChecks::checks()['ARInvoice']['en16931-br-ic-02'];

		$this->assertSame($expected, $check($invoice, []));

	}//end testIntraCommunitySupplyNeedsBothVatIds()
}//end class
