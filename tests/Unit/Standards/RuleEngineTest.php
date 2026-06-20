<?php

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards;

use OCA\Shillinq\Standards\RuleEngine;
use OCA\Shillinq\Standards\Violation;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RuleEngine — the executable layer over the RuleCatalogue.
 *
 * @covers \OCA\Shillinq\Standards\RuleEngine
 */
class RuleEngineTest extends TestCase
{


    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        RuleEngine::reset();

    }//end setUp()


    /**
     * A compliant invoice produces no violations.
     *
     * @return void
     */
    public function testCompliantInvoiceHasNoViolations(): void
    {
        $invoice = [
            'invoiceNumber' => '2026-001',
            'invoiceDate'   => '2026-06-21',
            'currency'      => 'EUR',
            'netAmount'     => 100.00,
            'vatAmount'     => 21.00,
            'grossAmount'   => 121.00,
        ];

        $this->assertSame([], RuleEngine::evaluate('ARInvoice', $invoice));

    }//end testCompliantInvoiceHasNoViolations()


    /**
     * A bad invoice (no number, total mismatch) yields mandatory violations
     * referencing real EN 16931 rule ids.
     *
     * @return void
     */
    public function testBadInvoiceYieldsMandatoryViolations(): void
    {
        $invoice = [
            'invoiceNumber' => '',
            'invoiceDate'   => '2026-06-21',
            'currency'      => 'EUR',
            'netAmount'     => 100.00,
            'vatAmount'     => 21.00,
            'grossAmount'   => 130.00,
        ];

        $violations = RuleEngine::evaluate('ARInvoice', $invoice);
        $ids = array_map(static fn(Violation $v): string => $v->ruleId, $violations);

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
    public function testGlTransactionCompleteness(): void
    {
        $ok = [
            'transactionNumber' => 'J-100',
            'lines'             => [
                ['accountNumber' => '8000', 'amount' => 100, 'side' => 'credit'],
                ['accountNumber' => '1300', 'amount' => 100, 'side' => 'debit'],
            ],
        ];
        $this->assertSame([], RuleEngine::evaluate('GLTransaction', $ok));

        $bad = ['transactionNumber' => '', 'lines' => [['accountNumber' => '8000', 'amount' => 100, 'side' => 'credit']]];
        $ids = array_map(static fn(Violation $v): string => $v->ruleId, RuleEngine::evaluate('GLTransaction', $bad));
        $this->assertContains('gl-completeness-timeliness', $ids);
        $this->assertContains('gl-sequential-journal-numbering', $ids);

    }//end testGlTransactionCompleteness()


    /**
     * Jurisdiction scopes applicability: EU-only EN 16931 rules do not fire for a
     * US administration.
     *
     * @return void
     */
    public function testEuRulesDoNotApplyToUs(): void
    {
        $badInvoice = ['invoiceNumber' => '', 'netAmount' => 100, 'vatAmount' => 21, 'grossAmount' => 130];

        $nl = RuleEngine::evaluate('ARInvoice', $badInvoice, ['jurisdiction' => 'NL']);
        $us = RuleEngine::evaluate('ARInvoice', $badInvoice, ['jurisdiction' => 'US']);

        $nlIds = array_map(static fn(Violation $v): string => $v->ruleId, $nl);
        $usIds = array_map(static fn(Violation $v): string => $v->ruleId, $us);

        $this->assertContains('en16931-br-02', $nlIds, 'EN 16931 applies in the EU');
        $this->assertNotContains('en16931-br-02', $usIds, 'EN 16931 (EU) must not fire for US');

    }//end testEuRulesDoNotApplyToUs()


    /**
     * violationFor() hydrates severity + source from the catalogue.
     *
     * @return void
     */
    public function testViolationForHydratesFromCatalogue(): void
    {
        $violation = RuleEngine::violationFor('gl-double-entry-balanced');
        $this->assertSame('gl-double-entry-balanced', $violation->ruleId);
        $this->assertSame('mandatory', $violation->severity);
        $this->assertNotSame('', $violation->statement);

    }//end testViolationForHydratesFromCatalogue()


}//end class
