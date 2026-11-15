<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\VatBbvLedgerTailChecks;
use PHPUnit\Framework\TestCase;

/**
 * What each VatBbvLedgerTailChecks rule accepts and refuses once its trigger
 * is set: the triangulation and reverse-charge conditions, the SME exemption
 * limits, the pro-rata and adjustment arithmetic, the FEC, NF525 and PT chain
 * records, and the BBV budget, paragraph, asset and task-field contents.
 *
 * @covers \OCA\Shillinq\Standards\Checks\VatBbvLedgerTailChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class VatBbvLedgerTailChecksBehaviourTest extends TestCase {

	/**
	 * Run one rule on one object.
	 *
	 * @param string               $type   The object type.
	 * @param string               $rule   The rule id.
	 * @param array<string, mixed> $object The object.
	 *
	 * @return bool
	 */
	private static function passes(string $type, string $rule, array $object): bool {
		return (VatBbvLedgerTailChecks::checks()[$type][$rule])($object, ['jurisdiction' => 'NL']);

	}//end passes()

	/**
	 * A triangulation invoice that meets every art. 141 condition.
	 *
	 * @return array<string, mixed>
	 */
	private static function triangulation(): array {
		return [
			'triangulation' => 'yes',
			'sellerCountryCode' => 'nl',
			'intermediaryCountryCode' => 'BE',
			'finalCustomerCountryCode' => 'DE',
			'directDelivery' => true,
			'finalCustomerVatId' => 'DE 123.456.789',
		];

	}//end triangulation()

	/**
	 * Art. 141 holds only for three Member States, direct delivery and a shaped VAT id.
	 *
	 * @return void
	 */
	public function testTriangulationNeedsThreeStatesDirectDeliveryAndAVatId(): void {
		$rule = 'vatdir-art141-triangulation-simplification';
		$ok = self::triangulation();

		$this->assertTrue(self::passes('ARInvoice', $rule, $ok));
		$this->assertTrue(self::passes('ARInvoice', $rule, ['triangulation' => false]));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['finalCustomerCountryCode' => 'NL'])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['intermediaryCountryCode' => 'nl'])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['sellerCountryCode' => ''])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['directDelivery' => 'no'])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['finalCustomerVatId' => 'US123456'])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['finalCustomerVatId' => 'DE1'])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['finalCustomerVatId' => 'DE'])));

	}//end testTriangulationNeedsThreeStatesDirectDeliveryAndAVatId()

	/**
	 * Art. 197 also needs a reverse-charge mention, as a flag or in the note.
	 *
	 * @return void
	 */
	public function testReverseChargeOnTriangulationNeedsAMention(): void {
		$rule = 'vatdir-art197-reverse-charge-triangulation';
		$ok = self::triangulation();

		$this->assertFalse(self::passes('ARInvoice', $rule, $ok));
		$this->assertTrue(self::passes('ARInvoice', $rule, array_merge($ok, ['reverseChargeMention' => '1'])));
		$this->assertTrue(self::passes('ARInvoice', $rule, array_merge($ok, ['invoiceNote' => 'Reverse Charge, art. 197'])));
		$this->assertTrue(self::passes('ARInvoice', $rule, array_merge($ok, ['invoiceNote' => 'BTW verlegd'])));
		$this->assertTrue(self::passes('ARInvoice', $rule, array_merge($ok, ['invoiceNote' => 'Heffing verlegd'])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['invoiceNote' => 'Thanks', 'sellerCountryCode' => 'DE'])));
		$this->assertTrue(self::passes('ARInvoice', $rule, []));

	}//end testReverseChargeOnTriangulationNeedsAMention()

	/**
	 * The SME exemption needs turnover at most the threshold, and the Union cap when cross-border.
	 *
	 * @return void
	 */
	public function testTheSmeExemptionStaysWithinItsLimits(): void {
		$rule = 'vatdir-art282-smes-exemption';
		$ok = ['smeExemptionApplied' => true, 'annualTurnover' => 20000, 'smeThreshold' => '20000'];

		$this->assertTrue(self::passes('ARInvoice', $rule, $ok));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['annualTurnover' => 20000.01])));
		$this->assertFalse(self::passes('ARInvoice', $rule, ['smeExemptionApplied' => true, 'annualTurnover' => 100]));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['smeThreshold' => 'n/a'])));
		$this->assertTrue(self::passes('ARInvoice', $rule, array_merge($ok, ['crossBorderSme' => true, 'unionTurnover' => 100000])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['crossBorderSme' => true, 'unionTurnover' => 100000.5])));
		$this->assertFalse(self::passes('ARInvoice', $rule, array_merge($ok, ['crossBorderSme' => true])));

	}//end testTheSmeExemptionStaysWithinItsLimits()

	/**
	 * A mixed-use purchase deducts its tax times the pro-rata, to the cent.
	 *
	 * @return void
	 */
	public function testTheProRataDeductionIsTheTaxTimesTheProportion(): void {
		$rule = 'vatdir-art173-pro-rata-deduction';
		$ok = ['mixedUse' => true, 'proRataPercent' => 37.5, 'taxAmount' => 210.0, 'deductibleVatAmount' => 78.75];

		$this->assertTrue(self::passes('APTransaction', $rule, $ok));
		$this->assertTrue(self::passes('APTransaction', $rule, array_merge($ok, ['deductibleVatAmount' => 78.754])));
		$this->assertFalse(self::passes('APTransaction', $rule, array_merge($ok, ['deductibleVatAmount' => 78.76])));
		$this->assertFalse(self::passes('APTransaction', $rule, array_merge($ok, ['proRataPercent' => 100.1])));
		$this->assertFalse(self::passes('APTransaction', $rule, array_merge($ok, ['proRataPercent' => -1])));
		$this->assertFalse(self::passes('APTransaction', $rule, ['mixedUse' => true, 'proRataPercent' => 50, 'taxAmount' => 10]));
		$this->assertTrue(self::passes('APTransaction', $rule, ['mixedUse' => false]));

	}//end testTheProRataDeductionIsTheTaxTimesTheProportion()

	/**
	 * An adjusted deduction records its amount, and a capital good its revision period.
	 *
	 * @return void
	 */
	public function testAnAdjustedDeductionRecordsItsAmountAndPeriod(): void {
		$rule = 'vatdir-art184-adjustment-deductions';

		$this->assertTrue(self::passes('APTransaction', $rule, ['deductionAdjusted' => true, 'adjustmentAmount' => -12.5]));
		$this->assertFalse(self::passes('APTransaction', $rule, ['deductionAdjusted' => true]));
		$this->assertTrue(self::passes('APTransaction', $rule, ['deductionAdjusted' => true, 'adjustmentAmount' => 5, 'capitalGood' => true, 'adjustmentPeriodYears' => 10]));
		$this->assertFalse(self::passes('APTransaction', $rule, ['deductionAdjusted' => true, 'adjustmentAmount' => 5, 'capitalGood' => true, 'adjustmentPeriodYears' => 0]));
		$this->assertFalse(self::passes('APTransaction', $rule, ['deductionAdjusted' => true, 'adjustmentAmount' => 5, 'capitalGood' => true]));

	}//end testAnAdjustedDeductionRecordsItsAmountAndPeriod()

	/**
	 * An FEC export has a text or XML type, tab or pipe delimiter, an encoding and the SIREN name.
	 *
	 * @return void
	 */
	public function testTheFecExportFormat(): void {
		$rule = 'fr-fec-format';
		$fec = ['fileType' => ' TXT ', 'delimiter' => 'Pipe', 'encoding' => 'UTF-8', 'fileName' => '123456789FEC20251231.txt'];

		$this->assertTrue(self::passes('GLTransaction', $rule, ['fecExport' => $fec]));
		$this->assertTrue(self::passes('GLTransaction', $rule, ['fecExport' => 'not a record']));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['fecExport' => array_merge($fec, ['fileType' => 'csv'])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['fecExport' => array_merge($fec, ['delimiter' => 'comma'])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['fecExport' => array_merge($fec, ['encoding' => ' '])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['fecExport' => array_merge($fec, ['fileName' => '12345678FEC20251231.txt'])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['fecExport' => array_merge($fec, ['fileName' => '123456789FEC20251231.csv'])]));

	}//end testTheFecExportFormat()

	/**
	 * An NF525 closing has a period, a numeric perpetual total, a positive sequence and a signature.
	 *
	 * @return void
	 */
	public function testTheNf525Closing(): void {
		$rule = 'fr-nf525-secured-archive';
		$closing = ['period' => 'Daily', 'perpetualTotal' => '0', 'sequenceNumber' => 1, 'signature' => 'sig'];

		$this->assertTrue(self::passes('GLTransaction', $rule, ['nf525Closing' => $closing]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['nf525Closing' => array_merge($closing, ['period' => 'weekly'])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['nf525Closing' => array_merge($closing, ['perpetualTotal' => 'x'])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['nf525Closing' => array_merge($closing, ['sequenceNumber' => 0])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['nf525Closing' => array_merge($closing, ['signature' => ' '])]));

	}//end testTheNf525Closing()

	/**
	 * A PT chain carries its ATCUD, its hash and the previous hash.
	 *
	 * @return void
	 */
	public function testThePortugueseChain(): void {
		$rule = 'pt-certified-invoicing-software';
		$chain = ['atcud' => 'ABCD-1', 'hash' => 'h2', 'previousHash' => 'h1'];

		$this->assertTrue(self::passes('GLTransaction', $rule, ['ptChain' => $chain]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['ptChain' => array_merge($chain, ['previousHash' => ''])]));
		$this->assertFalse(self::passes('GLTransaction', $rule, ['ptChain' => array_merge($chain, ['atcud' => ' '])]));
		$this->assertTrue(self::passes('GLTransaction', $rule, []));

	}//end testThePortugueseChain()

	/**
	 * A budget has both parts and a complete programme plan; other documents are not judged.
	 *
	 * @return void
	 */
	public function testABudgetHasBothPartsAndAProgrammePlan(): void {
		$budget = ['documentType' => ' Begroting ', 'parts' => ['Beleids-begroting', 'financiele begroting']];
		$plan = ['programmes' => ['Veiligheid'], 'generalFundingSources' => 1, 'overhead' => 2, 'vpbCharge' => 0, 'unforeseen' => '3'];

		$this->assertTrue(self::passes('BbvStatement', 'bbv-art7-begroting-structure', $budget));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art7-begroting-structure', ['documentType' => 'begroting', 'parts' => ['beleidsbegroting']]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art7-begroting-structure', ['documentType' => 'begroting', 'parts' => 'all']));
		$this->assertTrue(self::passes('BbvStatement', 'bbv-art7-begroting-structure', ['documentType' => 'jaarstukken']));

		$this->assertTrue(self::passes('BbvStatement', 'bbv-art8-programmaplan', array_merge($budget, ['programmePlan' => $plan])));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art8-programmaplan', array_merge($budget, ['programmePlan' => array_merge($plan, ['programmes' => []])])));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art8-programmaplan', array_merge($budget, ['programmePlan' => array_merge($plan, ['overhead' => null])])));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art8-programmaplan', $budget));

	}//end testABudgetHasBothPartsAndAProgrammePlan()

	/**
	 * Every mandatory paragraph must be present, however it is written.
	 *
	 * @return void
	 */
	public function testEveryMandatoryParagraphIsPresent(): void {
		$all = ['Lokale heffingen', 'Weerstandsvermogen', 'Onderhoud kapitaalgoederen', 'Financiering', 'Bedrijfsvoering', 'Verbonden partijen', 'Grondbeleid'];

		$this->assertTrue(self::passes('BbvStatement', 'bbv-art9-mandatory-paragraphs', ['paragraphs' => $all]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art9-mandatory-paragraphs', ['paragraphs' => array_slice($all, 1)]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art9-mandatory-paragraphs', ['paragraphs' => 'all']));

	}//end testEveryMandatoryParagraphIsPresent()

	/**
	 * Annual accounts without the report part fail, whatever else they hold.
	 *
	 * @return void
	 */
	public function testAnnualAccountsWithoutTheirPartsFail(): void {
		$rule = 'bbv-art24-jaarstukken';

		$this->assertFalse(self::passes('BbvStatement', $rule, ['documentType' => 'jaarstukken', 'parts' => ['programmaverantwoording']]));
		$this->assertTrue(self::passes('BbvStatement', $rule, ['documentType' => 'begroting']));

	}//end testAnnualAccountsWithoutTheirPartsFail()

	/**
	 * Every fixed asset is capitalised and valued at acquisition or production cost.
	 *
	 * @return void
	 */
	public function testFixedAssetsAreCapitalisedAtCost(): void {
		$asset = ['capitalised' => 'true', 'acquisitionCost' => 1200, 'valuationBasis' => 'Acquisition cost'];

		$this->assertTrue(self::passes('BbvStatement', 'bbv-art59-activeren', ['fixedAssets' => [$asset]]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art59-activeren', ['fixedAssets' => [$asset, ['capitalised' => 'no']]]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art59-activeren', ['fixedAssets' => ['x']]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art59-activeren', ['fixedAssets' => []]));

		$this->assertTrue(self::passes('BbvStatement', 'bbv-art62-verkrijgingsprijs', ['fixedAssets' => [$asset, array_merge($asset, ['valuationBasis' => 'vervaardigingsprijs'])]]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art62-verkrijgingsprijs', ['fixedAssets' => [array_merge($asset, ['valuationBasis' => 'market value'])]]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art62-verkrijgingsprijs', ['fixedAssets' => [array_merge($asset, ['acquisitionCost' => -1])]]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art62-verkrijgingsprijs', ['fixedAssets' => ['x']]));
		$this->assertFalse(self::passes('BbvStatement', 'bbv-art62-verkrijgingsprijs', []));

	}//end testFixedAssetsAreCapitalisedAtCost()

	/**
	 * Every task-field line has a code and estimated revenue and expense.
	 *
	 * @return void
	 */
	public function testEveryTaskFieldLineIsComplete(): void {
		$rule = 'bbv-taakvelden-overview';
		$line = ['code' => '0.1', 'estimatedRevenue' => 0, 'estimatedExpense' => '100'];

		$this->assertTrue(self::passes('BbvStatement', $rule, ['taskFields' => [$line]]));
		$this->assertFalse(self::passes('BbvStatement', $rule, ['taskFields' => [array_merge($line, ['code' => ' '])]]));
		$this->assertFalse(self::passes('BbvStatement', $rule, ['taskFields' => [array_merge($line, ['estimatedExpense' => null])]]));
		$this->assertFalse(self::passes('BbvStatement', $rule, ['taskFields' => ['x']]));
		$this->assertFalse(self::passes('BbvStatement', $rule, ['taskFields' => []]));

	}//end testEveryTaskFieldLineIsComplete()
}//end class
