<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\VatChecks;
use PHPUnit\Framework\TestCase;

/**
 * What the VatChecks rules accept and refuse on a filled-in invoice: the
 * taxable-amount reconciliations, the rate bounds per VAT breakdown row, the
 * reverse-charge and exemption mentions, the intra-Community VAT id and
 * Member State tests, untaxed exempt categories and the VAT rounding.
 *
 * @covers \OCA\Shillinq\Standards\Checks\VatChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class VatChecksBehaviourTest extends TestCase {

	/**
	 * Run one ARInvoice rule on one invoice.
	 *
	 * @param string               $rule   The rule id.
	 * @param array<string, mixed> $object The invoice.
	 *
	 * @return bool
	 */
	private static function passes(string $rule, array $object): bool {
		return (VatChecks::checks()['ARInvoice'][$rule])($object, ['jurisdiction' => 'NL']);

	}//end passes()

	/**
	 * The breakdown's taxable amounts add up to the line total, or to the net amount without one.
	 *
	 * @return void
	 */
	public function testTheBreakdownReconcilesToTheNet(): void {
		$rule = 'vatdir-art78-taxable-amount-inclusions';
		$rows = [['category' => 'S', 'taxableAmount' => 100.0], ['category' => 'AA', 'taxableAmount' => 50.004], 'junk'];

		$this->assertTrue(self::passes($rule, ['vatBreakdown' => $rows, 'netAmount' => 150]));
		$this->assertFalse(self::passes($rule, ['vatBreakdown' => $rows, 'netAmount' => 150.02]));
		$this->assertTrue(self::passes($rule, ['vatBreakdown' => $rows, 'lineNetTotal' => 150, 'netAmount' => 1]));
		$this->assertTrue(self::passes($rule, ['vatBreakdown' => 'none']));

	}//end testTheBreakdownReconcilesToTheNet()

	/**
	 * The net is the line total less allowances plus charges.
	 *
	 * @return void
	 */
	public function testTheNetIsTheLinesLessAllowancesPlusCharges(): void {
		$rule = 'vatdir-art79-taxable-amount-exclusions';

		$this->assertTrue(self::passes($rule, ['lineNetTotal' => 200, 'allowancesTotal' => 20, 'chargesTotal' => 5, 'netAmount' => 185]));
		$this->assertFalse(self::passes($rule, ['lineNetTotal' => 200, 'allowancesTotal' => 20, 'netAmount' => 200]));
		$this->assertTrue(self::passes($rule, ['netAmount' => 1]));

	}//end testTheNetIsTheLinesLessAllowancesPlusCharges()

	/**
	 * Standard-rated rows state a numeric rate, one standard rate, at least 15 percent.
	 *
	 * @return void
	 */
	public function testStandardRatedRowsHaveOneRateOfAtLeastFifteen(): void {
		$this->assertTrue(self::passes('vatdir-art93-rate-applicable', ['vatBreakdown' => [['category' => 'S', 'rate' => '21'], ['category' => 'Z']]]));
		$this->assertFalse(self::passes('vatdir-art93-rate-applicable', ['vatBreakdown' => [['category' => 'S']]]));
		$this->assertFalse(self::passes('vatdir-art93-rate-applicable', ['vatBreakdown' => [['category' => 'S', 'rate' => 'high']]]));

		$this->assertTrue(self::passes('vatdir-art96-standard-rate-single', ['vatBreakdown' => [['category' => 'S', 'rate' => 21], ['category' => 'S', 'rate' => 21.001], ['category' => 'AA', 'rate' => 9]]]));
		$this->assertFalse(self::passes('vatdir-art96-standard-rate-single', ['vatBreakdown' => [['category' => 'S', 'rate' => 21], ['category' => 'S', 'rate' => 19]]]));

		$this->assertTrue(self::passes('vatdir-art97-standard-rate-minimum', ['vatBreakdown' => [['category' => 'S', 'rate' => 15], ['category' => 'S', 'rate' => 0]]]));
		$this->assertFalse(self::passes('vatdir-art97-standard-rate-minimum', ['vatBreakdown' => [['category' => 'S', 'rate' => 14.9], ['category' => 'AA', 'rate' => 5]]]));

	}//end testStandardRatedRowsHaveOneRateOfAtLeastFifteen()

	/**
	 * A reduced rate below the standard band is at least 5 percent.
	 *
	 * @return void
	 */
	public function testAReducedRateIsAtLeastFive(): void {
		$rule = 'vatdir-art98-reduced-rates';

		$this->assertTrue(self::passes($rule, ['vatBreakdown' => [['rate' => 5], ['rate' => 9], ['rate' => 21], ['rate' => 0]]]));
		$this->assertFalse(self::passes($rule, ['vatBreakdown' => [['rate' => 4.5]]]));

	}//end testAReducedRateIsAtLeastFive()

	/**
	 * A reverse-charged or exempt invoice says so in its note.
	 *
	 * @return void
	 */
	public function testReverseChargeAndExemptionAreMentioned(): void {
		$reverse = ['invoiceLines' => [['vatCategory' => 'AE'], 'junk']];
		$this->assertFalse(self::passes('vatdir-art226-11a-reverse-charge-mention', $reverse));
		$this->assertTrue(self::passes('vatdir-art226-11a-reverse-charge-mention', $reverse + ['vatNote' => 'VAT Reverse Charge']));
		$this->assertTrue(self::passes('vatdir-art226-11a-reverse-charge-mention', ['invoiceLines' => 'none']));

		$exempt = ['vatBreakdown' => [['category' => 'K']]];
		$this->assertFalse(self::passes('vatdir-art138-exempt-mention', $exempt + ['vatNote' => 'thanks']));
		foreach (['Art. 138', 'intra-Community supply', 'Livraison intra-communautaire', 'VAT exempt'] as $note) {
			$this->assertTrue(self::passes('vatdir-art138-exempt-mention', $exempt + ['vatNote' => $note]), $note);
		}

	}//end testReverseChargeAndExemptionAreMentioned()

	/**
	 * Exempt intra-Community and export rows carry neither tax nor a rate.
	 *
	 * @return void
	 */
	public function testExemptCategoriesAreUntaxed(): void {
		$this->assertTrue(self::passes('vatdir-art138-ics-exemption', ['vatBreakdown' => [['category' => 'K', 'taxAmount' => 0.004, 'rate' => 0], ['category' => 'S', 'taxAmount' => 21]]]));
		$this->assertFalse(self::passes('vatdir-art138-ics-exemption', ['vatBreakdown' => [['category' => 'K', 'taxAmount' => 0.01]]]));
		$this->assertFalse(self::passes('vatdir-art138-ics-exemption', ['vatBreakdown' => [['category' => 'K', 'taxAmount' => 0, 'rate' => 21]]]));

		$this->assertTrue(self::passes('vatdir-art146-export-exemption', ['vatBreakdown' => [['category' => 'G', 'taxAmount' => 0]]]));
		$this->assertFalse(self::passes('vatdir-art146-export-exemption', ['vatBreakdown' => [['category' => 'G', 'taxAmount' => 3]]]));

	}//end testExemptCategoriesAreUntaxed()

	/**
	 * An intra-Community supply names both VAT ids, a shaped buyer id, in another Member State.
	 *
	 * @return void
	 */
	public function testAnIntraCommunitySupplyCrossesAMemberState(): void {
		$rule = 'vatdir-art138-vatid-substantive';
		$ok = ['vatBreakdown' => [['category' => 'K']], 'sellerVatId' => 'NL123456789B01', 'buyerVatId' => 'BE 0123.456.789'];

		$this->assertTrue(self::passes($rule, $ok));
		$this->assertTrue(self::passes($rule, array_merge($ok, ['sellerCountryCode' => 'nl'])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['buyerVatId' => 'NL987654321B01'])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['sellerCountryCode' => 'BE'])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['sellerVatId' => ' '])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['buyerVatId' => 'XX123'])));
		$this->assertTrue(self::passes($rule, []));

		$this->assertTrue(self::passes('vies-vatid-validation', $ok));
		$this->assertFalse(self::passes('vies-vatid-validation', array_merge($ok, ['buyerVatId' => 'BE1'])));

	}//end testAnIntraCommunitySupplyCrossesAMemberState()

	/**
	 * A B2B reverse-charged service is untaxed and names both parties' VAT ids.
	 *
	 * @return void
	 */
	public function testAReverseChargedServiceIsUntaxedWithBothIds(): void {
		$rule = 'vatdir-art196-reverse-charge-b2b-services';
		$ok = ['vatBreakdown' => [['category' => 'AE', 'taxAmount' => 0]], 'sellerVatId' => 'NL1', 'buyerVatId' => 'DE123456789'];

		$this->assertTrue(self::passes($rule, $ok));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['vatBreakdown' => [['category' => 'AE', 'taxAmount' => 21]]])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['buyerVatId' => ''])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['sellerVatId' => ''])));

	}//end testAReverseChargedServiceIsUntaxedWithBothIds()

	/**
	 * The VAT amount equals the breakdown's tax, to the cent.
	 *
	 * @return void
	 */
	public function testTheVatAmountRoundsToTheBreakdown(): void {
		$rule = 'vat-rounding-line-or-total';
		$rows = [['taxAmount' => 10.004], ['taxAmount' => 2.1]];

		$this->assertTrue(self::passes($rule, ['vatBreakdown' => $rows, 'vatAmount' => 12.1]));
		$this->assertFalse(self::passes($rule, ['vatBreakdown' => $rows, 'vatAmount' => 12.11]));
		$this->assertTrue(self::passes($rule, []));

	}//end testTheVatAmountRoundsToTheBreakdown()
}//end class
