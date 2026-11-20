<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\InvoiceMentionsTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * The invoice mentions once their trigger is set: every exempt breakdown row
 * gives a reason, a reverse-charged line or row carries its mention, and a
 * simplified invoice still states its date, the seller's VAT id and the VAT.
 *
 * @covers \OCA\Shillinq\Standards\Checks\InvoiceMentionsTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class InvoiceMentionsTailChecksBehaviourTest extends TestCase {

	/**
	 * Run one ARInvoice rule on one invoice.
	 *
	 * @param string               $rule   The rule id.
	 * @param array<string, mixed> $object The invoice.
	 *
	 * @return bool
	 */
	private static function passes(string $rule, array $object): bool {
		return (InvoiceMentionsTailChecks::checks()['ARInvoice'][$rule])($object, ['jurisdiction' => 'NL']);

	}//end passes()

	/**
	 * Every exempt breakdown row gives a reason code or text; taxed rows need none.
	 *
	 * @return void
	 */
	public function testEveryExemptRowGivesAReason(): void {
		$rule = 'vatdir-art226-11';

		$this->assertTrue(self::passes($rule, ['vatBreakdown' => [['category' => 'S'], 'junk', ['category' => 'E', 'exemptionReasonCode' => 'VATEX-EU-79-C'], ['category' => 'G', 'exemptionReasonText' => 'Export']]]));
		$this->assertFalse(self::passes($rule, ['vatBreakdown' => [['category' => 'K', 'exemptionReasonText' => ' ']]]));
		$this->assertFalse(self::passes($rule, ['vatBreakdown' => [['category' => 'O']]]));
		$this->assertTrue(self::passes($rule, ['vatBreakdown' => 'none']));

	}//end testEveryExemptRowGivesAReason()

	/**
	 * A reverse-charged line or breakdown row needs the reverse-charge mention.
	 *
	 * @return void
	 */
	public function testAReverseChargedInvoiceCarriesItsMention(): void {
		$rule = 'vatdir-art226-11a';

		$this->assertFalse(self::passes($rule, ['invoiceLines' => ['junk', ['vatCategory' => 'AE']]]));
		$this->assertFalse(self::passes($rule, ['invoiceLines' => 'none', 'vatBreakdown' => [['category' => 'AE']]]));
		$this->assertTrue(self::passes($rule, ['vatBreakdown' => [['category' => 'AE']], 'reverseChargeMention' => 'Reverse charge']));
		$this->assertTrue(self::passes($rule, ['invoiceLines' => [['vatCategory' => 'S']], 'vatBreakdown' => ['junk', ['category' => 'S']]]));

	}//end testAReverseChargedInvoiceCarriesItsMention()

	/**
	 * A simplified invoice still states its date, the seller's VAT id and a numeric VAT amount.
	 *
	 * @return void
	 */
	public function testASimplifiedInvoiceKeepsItsEssentials(): void {
		$rule = 'vatdir-art226b-simplified';
		$ok = ['simplifiedInvoice' => 'true', 'invoiceDate' => '2026-11-13', 'sellerVatId' => 'NL1', 'vatAmount' => '0'];

		$this->assertTrue(self::passes($rule, $ok));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['vatAmount' => 'n/a'])));
		$this->assertFalse(self::passes($rule, array_merge($ok, ['sellerVatId' => ' '])));
		$this->assertTrue(self::passes($rule, ['simplifiedInvoice' => 'yes']));

	}//end testASimplifiedInvoiceKeepsItsEssentials()
}//end class
