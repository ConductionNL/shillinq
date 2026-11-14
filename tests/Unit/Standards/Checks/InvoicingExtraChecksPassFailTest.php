<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoiceParts;
use OCA\Shillinq\Standards\Checks\InvoicingExtraChecks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The EN 16931 invoicing rules on invoices crafted to pass and to fail each one:
 * period order, allowance and charge reasons, item attributes, payment means,
 * electronic-address schemes, tax representative and payee, the VAT
 * breakdown's category coverage, the "not subject to VAT" and intra-community
 * rules, and the buyer VAT identifier's country prefix.
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
#[CoversClass(InvoicingExtraChecks::class)]
#[UsesClass(InvoiceParts::class)]
final class InvoicingExtraChecksPassFailTest extends TestCase {

	/**
	 * Run one rule on an invoice.
	 *
	 * @param string $rule The catalogue rule id.
	 * @param array<string, mixed> $invoice The ARInvoice.
	 *
	 * @return bool The verdict.
	 */
	private static function holds(string $rule, array $invoice): bool {
		return (InvoicingExtraChecks::checks()['ARInvoice'][$rule])($invoice);
	}//end holds()

	/**
	 * Assert a rule passes on the first invoices and fails on the second.
	 *
	 * @param string $rule The rule id.
	 * @param array<int, array<string, mixed>> $passing Invoices that satisfy it.
	 * @param array<int, array<string, mixed>> $failing Invoices that break it.
	 *
	 * @return void
	 */
	private function assertRule(string $rule, array $passing, array $failing): void {
		foreach ($passing as $index => $invoice) {
			$this->assertTrue(self::holds($rule, $invoice), "{$rule} should pass invoice #{$index}");
		}

		foreach ($failing as $index => $invoice) {
			$this->assertFalse(self::holds($rule, $invoice), "{$rule} should fail invoice #{$index}");
		}
	}//end assertRule()

	/**
	 * Invoicing and line periods must not end before they start; unreadable dates are left alone.
	 *
	 * @return void
	 */
	public function testPeriodsMustNotEndBeforeTheyStart(): void {
		$this->assertRule(
			'en16931-br-29',
			[
				['invoicingPeriodStart' => '2026-01-01', 'invoicingPeriodEnd' => '2026-01-31'],
				['invoicingPeriodStart' => '2026-01-01'],
				['invoicingPeriodStart' => 'someday', 'invoicingPeriodEnd' => '2026-01-01'],
			],
			[['invoicingPeriodStart' => '2026-02-01', 'invoicingPeriodEnd' => '2026-01-31']]
		);
		$this->assertRule(
			'en16931-br-30',
			[['invoiceLines' => [['periodStart' => '2026-01-01', 'periodEnd' => '2026-01-01'], ['periodStart' => '2026-01-05']]]],
			[['invoiceLines' => [['periodStart' => '2026-01-01', 'periodEnd' => '2026-01-31'], ['periodStart' => '2026-03-01', 'periodEnd' => '2026-02-01']]]]
		);
	}//end testPeriodsMustNotEndBeforeTheyStart()

	/**
	 * Every allowance and charge, on the document and on a line, carries a reason text or code.
	 *
	 * @return void
	 */
	public function testAllowancesAndChargesCarryAReason(): void {
		$this->assertRule(
			'en16931-br-co-21',
			[['allowances' => [['reasonText' => 'Discount'], ['reasonCode' => '95']]], ['allowances' => 'not-a-list']],
			[['allowances' => [['reasonText' => 'Discount'], ['reasonText' => ' ', 'reasonCode' => '']]]]
		);
		$this->assertRule(
			'en16931-br-co-22',
			[['charges' => [['reasonCode' => 'FC']]]],
			[['charges' => [['amount' => 5]]]]
		);
		$this->assertRule(
			'en16931-br-co-23',
			[['invoiceLines' => [['lineAllowances' => [['reasonText' => 'Volume']]], []]]],
			[['invoiceLines' => [['lineAllowances' => [['reasonCode' => '95']]], ['lineAllowances' => [['amount' => 1]]]]]]
		);
		$this->assertRule(
			'en16931-br-co-24',
			[['invoiceLines' => [['lineCharges' => [['reasonCode' => 'ABL']]]]]],
			[['invoiceLines' => [['lineCharges' => [[]]]]]]
		);
	}//end testAllowancesAndChargesCarryAReason()

	/**
	 * Item attributes have both a name and a value; referenced documents have a reference.
	 *
	 * @return void
	 */
	public function testAttributesAndReferencesAreComplete(): void {
		$this->assertRule(
			'en16931-br-54',
			[['invoiceLines' => [['itemAttributes' => [['name' => 'Colour', 'value' => 'Red']]]]]],
			[
				['invoiceLines' => [['itemAttributes' => [['name' => 'Colour', 'value' => '']]]]],
				['invoiceLines' => [['itemAttributes' => [['value' => 'Red']]]]],
			]
		);
		$this->assertRule(
			'en16931-br-52',
			[['supportingDocuments' => [['reference' => 'DOC-1']]], []],
			[['supportingDocuments' => [['reference' => 'DOC-1'], ['description' => 'Timesheet']]]]
		);
		$this->assertRule(
			'en16931-br-55',
			[['precedingInvoiceReferences' => [['reference' => 'INV-1']]]],
			[['precedingInvoiceReferences' => [['issueDate' => '2026-01-01']]]]
		);
	}//end testAttributesAndReferencesAreComplete()

	/**
	 * A payment instruction names its means; a credit transfer names the account it pays into.
	 *
	 * @return void
	 */
	public function testPaymentInstructions(): void {
		$this->assertRule(
			'en16931-br-49',
			[[], ['paymentMeansTypeCode' => '30', 'paymentAccountId' => 'NL91ABNA0417164300']],
			[['paymentAccountId' => 'NL91ABNA0417164300'], ['paymentAccountName' => 'Gemeente']]
		);
		foreach (['en16931-br-50', 'en16931-br-61'] as $rule) {
			$this->assertRule(
				$rule,
				[['paymentMeansTypeCode' => '58', 'paymentAccountId' => 'NL91ABNA0417164300'], ['paymentMeansTypeCode' => '10']],
				[['paymentMeansTypeCode' => '30'], ['paymentMeansTypeCode' => '59', 'paymentAccountId' => ' ']]
			);
		}
	}//end testPaymentInstructions()

	/**
	 * An electronic address names its scheme, for the seller and for the buyer.
	 *
	 * @return void
	 */
	public function testElectronicAddressesNameTheirScheme(): void {
		$this->assertRule(
			'en16931-br-62',
			[[], ['sellerElectronicAddress' => '00000001234567890000', 'sellerElectronicAddressScheme' => '0190']],
			[['sellerElectronicAddress' => '00000001234567890000']]
		);
		$this->assertRule(
			'en16931-br-63',
			[['buyerElectronicAddress' => 'x@example.test', 'buyerElectronicAddressScheme' => 'EM']],
			[['buyerElectronicAddress' => 'x@example.test', 'buyerElectronicAddressScheme' => '']]
		);
	}//end testElectronicAddressesNameTheirScheme()

	/**
	 * A tax representative, once any of its fields is given, is given in full.
	 *
	 * @return void
	 */
	public function testATaxRepresentativeIsComplete(): void {
		$complete = [
			'sellerTaxRepName' => 'Rep BV',
			'sellerTaxRepAddress' => 'Straat 1',
			'sellerTaxRepCountryCode' => 'NL',
			'sellerTaxRepVatId' => 'NL001234567B01',
		];
		$rules = [
			'en16931-br-18' => 'sellerTaxRepName',
			'en16931-br-19' => 'sellerTaxRepAddress',
			'en16931-br-20' => 'sellerTaxRepCountryCode',
			'en16931-br-56' => 'sellerTaxRepVatId',
		];
		foreach ($rules as $rule => $field) {
			$missing = $complete;
			unset($missing[$field]);
			$this->assertRule($rule, [[], $complete], [$missing]);
		}
	}//end testATaxRepresentativeIsComplete()

	/**
	 * A payee other than the seller is named.
	 *
	 * @return void
	 */
	public function testAPayeeOtherThanTheSellerIsNamed(): void {
		$this->assertRule(
			'en16931-br-17',
			[
				[],
				['sellerName' => 'Gemeente', 'payeeName' => 'Factoring BV'],
				['sellerName' => 'Gemeente', 'payeeName' => 'Gemeente'],
			],
			[['sellerName' => 'Gemeente', 'payeeIdentifier' => 'PAYEE-1']]
		);
	}//end testAPayeeOtherThanTheSellerIsNamed()

	/**
	 * A delivery address names its country, and country codes are two capitals.
	 *
	 * @return void
	 */
	public function testDeliveryAndCountryCodes(): void {
		$this->assertRule(
			'en16931-br-57',
			[[], ['deliverToAddress' => 'Straat 1', 'deliverToCountryCode' => 'NL']],
			[['deliverToAddress' => 'Straat 1']]
		);
		$this->assertRule(
			'en16931-br-cl-14',
			[['sellerCountryCode' => 'NL', 'buyerCountryCode' => 'BE', 'deliverToCountryCode' => '']],
			[['sellerCountryCode' => 'NL', 'sellerTaxRepCountryCode' => 'nl'], ['deliverToCountryCode' => 'NLD']]
		);
	}//end testDeliveryAndCountryCodes()

	/**
	 * Every VAT category used on a line, an allowance or a charge appears in the breakdown.
	 *
	 * @return void
	 */
	public function testTheBreakdownCoversEveryUsedCategory(): void {
		$this->assertRule(
			'en16931-br-s-01',
			[
				[],
				['invoiceLines' => [['vatCategory' => 'S']], 'vatBreakdown' => [['category' => 'S']]],
			],
			[
				['invoiceLines' => [['vatCategory' => 'S']], 'vatBreakdown' => [['category' => 'Z']]],
				['charges' => [['vatCategory' => 'S']], 'vatBreakdown' => []],
			]
		);
		$this->assertRule(
			'en16931-br-ae-01',
			[['allowances' => [['vatCategory' => 'AE']], 'vatBreakdown' => [['category' => 'AE']]]],
			[['allowances' => [['vatCategory' => 'AE']]]]
		);
		$this->assertRule(
			'en16931-br-cl-17',
			[['invoiceLines' => [['vatCategory' => 'S']], 'vatBreakdown' => [['category' => 'S']]]],
			[
				['invoiceLines' => [['vatCategory' => 'X']]],
				['invoiceLines' => [['vatCategory' => 'S']], 'vatBreakdown' => [['category' => 'VAT']]],
				[],
			]
		);
	}//end testTheBreakdownCoversEveryUsedCategory()

	/**
	 * A "not subject to VAT" invoice is that and only that, on every line, allowance and charge.
	 *
	 * @return void
	 */
	public function testNotSubjectToVatIsExclusive(): void {
		$outside = ['vatBreakdown' => [['category' => 'O']], 'invoiceLines' => [['vatCategory' => 'O']]];

		$this->assertRule('en16931-br-o-11', [[], $outside], [['vatBreakdown' => [['category' => 'O'], ['category' => 'S']]]]);
		$this->assertRule('en16931-br-o-12', [$outside], [[...$outside, 'invoiceLines' => [['vatCategory' => 'O'], ['vatCategory' => 'S']]]]);
		$this->assertRule('en16931-br-o-13', [[...$outside, 'allowances' => [['vatCategory' => 'O']]]], [[...$outside, 'allowances' => [['vatCategory' => 'E']]]]);
		$this->assertRule('en16931-br-o-14', [[...$outside, 'charges' => [['vatCategory' => 'O']]]], [[...$outside, 'charges' => [[]]]]);
	}//end testNotSubjectToVatIsExclusive()

	/**
	 * An intra-community supply carries a delivery date or period, and the delivery country.
	 *
	 * @return void
	 */
	public function testIntraCommunitySupplies(): void {
		$supply = ['vatBreakdown' => [['category' => 'K']]];

		$this->assertRule(
			'en16931-br-ic-11',
			[[], [...$supply, 'actualDeliveryDate' => '2026-01-10'], [...$supply, 'invoicingPeriodEnd' => '2026-01-31']],
			[$supply]
		);
		$this->assertRule('en16931-br-ic-12', [[...$supply, 'deliverToCountryCode' => 'DE']], [$supply]);
	}//end testIntraCommunitySupplies()

	/**
	 * Amounts: at most two decimals where numeric, the amount due adds up, a tax currency carries its amount.
	 *
	 * @return void
	 */
	public function testAmounts(): void {
		$this->assertRule('en16931-br-dec-18', [['amountDue' => '10.25'], ['amountDue' => 'n/a']], [['amountDue' => 10.255]]);
		$this->assertRule('en16931-br-15', [[], ['amountDue' => '0']], [['amountDue' => 'ten']]);
		$this->assertRule(
			'en16931-br-co-16',
			[['amountDue' => '90.01', 'grossAmount' => 100, 'paidAmount' => 10, 'roundingAmount' => 0.01]],
			[['amountDue' => '95', 'grossAmount' => 100, 'paidAmount' => 10]]
		);
		$this->assertRule(
			'en16931-br-53',
			[[], ['taxCurrencyCode' => 'USD', 'taxAmountAccountingCurrency' => '21.00']],
			[['taxCurrencyCode' => 'USD']]
		);
	}//end testAmounts()

	/**
	 * A buyer VAT identifier starts with a country prefix.
	 *
	 * @return void
	 */
	public function testTheBuyerVatIdStartsWithACountry(): void {
		$this->assertRule('vatdir-art226-4', [[], ['buyerVatId' => ' NL001234567B01']], [['buyerVatId' => '001234567B01']]);
	}//end testTheBuyerVatIdStartsWithACountry()
}//end class
