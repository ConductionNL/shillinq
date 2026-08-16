<?php

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Standards\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RuleEngine — the executable layer over the RuleCatalogue.
 *
 * RuleEngine::evaluate() is a dispatcher: it pulls the rule set from the
 * RuleCatalogue, runs every registered Checks provider against the payload and
 * returns Violation objects. Those collaborators are therefore executed by
 * every test here but are NOT what these tests claim to cover — each Checks
 * class has (or wants) its own dedicated test. Declaring them below is what
 * beStrictAboutCoverageMetadata asks for; without those declarations PHPUnit
 * reports every test in this class RISKY and, with failOnRisky, exits non-zero
 * on a suite that otherwise passes.
 *
 * Do not write the annotation name with its leading sigil in this prose:
 * PHPUnit parses docblock text as metadata, so a bare mention becomes a
 * malformed annotation and is reported as "is invalid".
 *
 * @covers \OCA\Shillinq\Standards\RuleEngine
 *
 * @uses \OCA\Shillinq\Standards\RuleCatalogue
 * @uses \OCA\Shillinq\Standards\Violation
 * @uses \OCA\Shillinq\Standards\Checks\AmlBankingChecks
 * @uses \OCA\Shillinq\Standards\Checks\ChartOfAccountsChecks
 * @uses \OCA\Shillinq\Standards\Checks\ComplianceTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\FinalTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\FinancialStatementsChecks
 * @uses \OCA\Shillinq\Standards\Checks\IfrsUsGaapChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoiceMentionsTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoicingExtraChecks
 * @uses \OCA\Shillinq\Standards\Checks\InvoicingTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\LedgerIntegrityChecks
 * @uses \OCA\Shillinq\Standards\Checks\NationalExtraTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\NationalReportingTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\OssIossChecks
 * @uses \OCA\Shillinq\Standards\Checks\PublicSectorIpsasGasbChecks
 * @uses \OCA\Shillinq\Standards\Checks\RemainingVatChecks
 * @uses \OCA\Shillinq\Standards\Checks\SustainabilityChecks
 * @uses \OCA\Shillinq\Standards\Checks\VatBbvLedgerTailChecks
 * @uses \OCA\Shillinq\Standards\Checks\VatChecks
 */
class RuleEngineTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		RuleEngine::reset();

	}//end setUp()

	/**
	 * A compliant invoice produces no violations.
	 *
	 * @return void
	 */
	public function testCompliantInvoiceHasNoViolations(): void {
		$this->assertSame([], RuleEngine::evaluate('ARInvoice', self::compliantInvoice()));

	}//end testCompliantInvoiceHasNoViolations()

	/**
	 * A fully EN 16931-compliant invoice fixture (header + lines + VAT breakdown).
	 *
	 * @return array<string, mixed>
	 */
	private static function compliantInvoice(): array {
		// Merge in any per-domain provider seedSpec defaults for ARInvoice so the
		// fixture stays compliant as provider checks are added (the literal below
		// wins for the fields it sets).
		return array_merge((RuleEngine::providerSeedSpecs()['ARInvoice'] ?? []), [
			'invoiceNumber' => '2026-001',
			'invoiceTypeCode' => '380',
			'invoiceDate' => '2026-06-21',
			'supplyDate' => '2026-06-21',
			'dueDate' => '2026-07-21',
			'customerId' => 'CUST-1',
			'currency' => 'EUR',
			'netAmount' => 100.00,
			'vatAmount' => 21.00,
			'grossAmount' => 121.00,
			'lineNetTotal' => 100.00,
			'specificationId' => 'urn:cen.eu:en16931:2017',
			'sellerName' => 'Test Seller B.V.',
			'sellerIdentifier' => 'NL-KVK-00000000',
			'sellerVatId' => 'NL123456789B01',
			'sellerAddress' => 'Street 1, Amsterdam',
			'sellerCountryCode' => 'NL',
			'buyerAddress' => 'Street 2, Rotterdam',
			'allowances' => [],
			'charges' => [],
			'allowancesTotal' => 0,
			'chargesTotal' => 0,
			'invoiceLines' => [
				[
					'lineId' => '1',
					'quantity' => 1,
					'unitCode' => 'C62',
					'netAmount' => 100.00,
					'itemName' => 'Consultancy',
					'netPrice' => 100.00,
					'vatCategory' => 'S',
					'vatRate' => 21,
				],
			],
			'vatBreakdown' => [
				['category' => 'S', 'taxableAmount' => 100.00, 'taxAmount' => 21.00, 'rate' => 21],
			],
		]);

	}//end compliantInvoice()

	/**
	 * Line-level and VAT-breakdown rules flag a structurally broken invoice and
	 * pass a complete one.
	 *
	 * @return void
	 */
	public function testInvoiceLineAndVatBreakdownRules(): void {
		// Missing line id + unit, and a VAT breakdown whose tax amount does not
		// equal taxable × rate → BR-21 / BR-23 / BR-CO-17 must fire.
		$broken = self::compliantInvoice();
		unset($broken['invoiceLines'][0]['lineId'], $broken['invoiceLines'][0]['unitCode']);
		$broken['vatBreakdown'][0]['taxAmount'] = 19.00;

		$ids = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $broken));
		$this->assertContains('en16931-br-21', $ids, 'missing invoice line identifier');
		$this->assertContains('en16931-br-23', $ids, 'missing invoiced quantity unit');
		$this->assertContains('en16931-br-co-17', $ids, 'VAT breakdown tax ≠ taxable × rate');

		// An invoice with no lines at all fails the line-presence group (BR-16).
		$headerOnly = self::compliantInvoice();
		unset($headerOnly['invoiceLines'], $headerOnly['vatBreakdown'], $headerOnly['lineNetTotal']);
		$headerIds = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $headerOnly));
		$this->assertContains('en16931-br-16', $headerIds, 'an invoice must have at least one line');
		$this->assertContains('en16931-br-co-18', $headerIds, 'an invoice must have a VAT breakdown');

	}//end testInvoiceLineAndVatBreakdownRules()

	/**
	 * A bad invoice (no number, total mismatch) yields mandatory violations
	 * referencing real EN 16931 rule ids.
	 *
	 * @return void
	 */
	public function testBadInvoiceYieldsMandatoryViolations(): void {
		$invoice = [
			'invoiceNumber' => '',
			'invoiceDate' => '2026-06-21',
			'currency' => 'EUR',
			'netAmount' => 100.00,
			'vatAmount' => 21.00,
			'grossAmount' => 130.00,
		];

		$violations = RuleEngine::evaluate('ARInvoice', $invoice);
		$ids = array_map(static fn (Violation $v): string => $v->ruleId, $violations);

		$this->assertContains('en16931-br-02', $ids, 'missing invoice number');
		$this->assertContains('en16931-br-co-15', $ids, 'total-with-VAT ≠ net + VAT');
		$this->assertTrue(RuleEngine::hasMandatory($violations));

	}//end testBadInvoiceYieldsMandatoryViolations()

	/**
	 * A balanced, complete GL transaction passes; an incomplete one fails on
	 * completeness + sequential numbering.
	 *
	 * @return void
	 */
	public function testGlTransactionCompleteness(): void {
		// Merge provider seedSpec defaults (period-lock, audit-trail, retention,
		// SAF-T flags, …) so the fixture satisfies the ledger-integrity checks too.
		$ok = array_merge((RuleEngine::providerSeedSpecs()['GLTransaction'] ?? []), [
			'transactionNumber' => 'J-100',
			'postingDate' => '2026-06-21',
			'sourceReference' => 'DOC-J-100',
			'lines' => [
				['accountNumber' => '8000', 'amount' => 100, 'side' => 'credit'],
				['accountNumber' => '1300', 'amount' => 100, 'side' => 'debit'],
			],
		]);
		$this->assertSame([], RuleEngine::evaluate('GLTransaction', $ok));

		$bad = ['transactionNumber' => '', 'lines' => [['accountNumber' => '8000', 'amount' => 100, 'side' => 'credit']]];
		$ids = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('GLTransaction', $bad));
		$this->assertContains('gl-completeness-timeliness', $ids);
		$this->assertContains('gl-sequential-journal-numbering', $ids);

		// Lines present and sided but debits ≠ credits → double-entry balance flagged.
		$unbalanced = [
			'transactionNumber' => 'J-200',
			'postingDate' => '2026-06-21',
			'sourceReference' => 'DOC-J-200',
			'lines' => [
				['accountNumber' => '8000', 'amount' => 100, 'side' => 'credit'],
				['accountNumber' => '1300', 'amount' => 90, 'side' => 'debit'],
			],
		];
		$unbalancedIds = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('GLTransaction', $unbalanced));
		$this->assertContains('ledger-double-entry-balance', $unbalancedIds, 'debits ≠ credits must flag the balance rule');

	}//end testGlTransactionCompleteness()

	/**
	 * Jurisdiction scopes applicability: EU-only EN 16931 rules do not fire for a
	 * US administration.
	 *
	 * @return void
	 */
	public function testEuRulesDoNotApplyToUs(): void {
		$badInvoice = ['invoiceNumber' => '', 'netAmount' => 100, 'vatAmount' => 21, 'grossAmount' => 130];

		$nl = RuleEngine::evaluate('ARInvoice', $badInvoice, ['jurisdiction' => 'NL']);
		$us = RuleEngine::evaluate('ARInvoice', $badInvoice, ['jurisdiction' => 'US']);

		$nlIds = array_map(static fn (Violation $v): string => $v->ruleId, $nl);
		$usIds = array_map(static fn (Violation $v): string => $v->ruleId, $us);

		$this->assertContains('en16931-br-02', $nlIds, 'EN 16931 applies in the EU');
		$this->assertNotContains('en16931-br-02', $usIds, 'EN 16931 (EU) must not fire for US');

	}//end testEuRulesDoNotApplyToUs()

	/**
	 * Currency-format and decimal-limit checks flag bad data and pass good data.
	 *
	 * @return void
	 */
	public function testCurrencyAndDecimalChecks(): void {
		$base = [
			'invoiceNumber' => '1', 'invoiceDate' => '2026-06-21', 'customerId' => 'c1',
			'netAmount' => 100.00, 'vatAmount' => 21.00, 'grossAmount' => 121.00,
		];

		// Bad currency (not ISO) + sub-cent amount → violations.
		$bad = array_merge($base, ['currency' => 'euro', 'grossAmount' => 121.005, 'netAmount' => 100.005]);
		$ids = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $bad));
		$this->assertContains('en16931-br-cl-03', $ids, 'non-ISO currency flagged');
		$this->assertContains('en16931-br-dec-14', $ids, 'sub-cent total flagged');

		// Clean data → none of these fire.
		$good = array_merge($base, ['currency' => 'EUR']);
		$goodIds = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $good));
		$this->assertNotContains('en16931-br-cl-03', $goodIds);
		$this->assertNotContains('en16931-br-dec-14', $goodIds);

	}//end testCurrencyAndDecimalChecks()

	/**
	 * Per-category line VAT rate and exemption-reason rules flag inconsistencies.
	 *
	 * @return void
	 */
	public function testCategoryRateAndExemptionRules(): void {
		// A Standard-rated line with a zero rate violates BR-S-05.
		$noRate = self::compliantInvoice();
		$noRate['invoiceLines'][0]['vatRate'] = 0;
		$ids = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $noRate));
		$this->assertContains('en16931-br-s-05', $ids, 'standard-rated line must have a positive rate');

		// An exempt breakdown without an exemption reason violates BR-E-10; a
		// standard breakdown that carries one violates BR-S-10.
		$exempt = self::compliantInvoice();
		$exempt['vatBreakdown'][0]['exemptionReasonText'] = 'Article 44';
		$sIds = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $exempt));
		$this->assertContains('en16931-br-s-10', $sIds, 'standard breakdown must not carry an exemption reason');

		$exemptLine = self::compliantInvoice();
		$exemptLine['invoiceLines'][0]['vatCategory'] = 'E';
		$exemptLine['invoiceLines'][0]['vatRate'] = 0;
		$exemptLine['vatBreakdown'][0]['category'] = 'E';
		$exemptLine['vatBreakdown'][0]['rate'] = 0;
		$exemptLine['vatBreakdown'][0]['taxAmount'] = 0;
		$eIds = array_map(static fn (Violation $v): string => $v->ruleId, RuleEngine::evaluate('ARInvoice', $exemptLine));
		$this->assertContains('en16931-br-e-10', $eIds, 'exempt breakdown must carry an exemption reason');

	}//end testCategoryRateAndExemptionRules()

	/**
	 * violationFor() hydrates severity + source from the catalogue.
	 *
	 * @return void
	 */
	public function testViolationForHydratesFromCatalogue(): void {
		$violation = RuleEngine::violationFor('gl-double-entry-balanced');
		$this->assertSame('gl-double-entry-balanced', $violation->ruleId);
		$this->assertSame('mandatory', $violation->severity);
		$this->assertNotSame('', $violation->statement);

	}//end testViolationForHydratesFromCatalogue()

}//end class
