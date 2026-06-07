<?php

/**
 * Unit tests for StatementParser.
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
 * @spec openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\StatementParser;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for StatementParser parse() (CAMT.053 + MT940) and allLinesResolved()
 * (REQ-BR-003, REQ-BR-004).
 */
class StatementParserTest extends TestCase
{

    /**
     * Mock ContainerInterface.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface&MockObject $container;

    /**
     * The parser under test.
     *
     * @var StatementParser
     */
    private StatementParser $parser;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container = $this->createMock(originalClassName: ContainerInterface::class);
        $appConfig       = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('shillinq');
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->parser = new StatementParser(
            container: $this->container,
            appConfig: $appConfig,
            logger: $logger,
        );

    }//end setUp()

    /**
     * CAMT.053 XML with two entries parses into two normalised lines.
     *
     * @return void
     */
    public function testParseCamt053(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">'
            .'<BkToCstmrStmt><Stmt>'
            .'<Ntry><Amt Ccy="EUR">1210.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
            .'<ValDt><Dt>2026-01-15</Dt></ValDt>'
            .'<NtryDtls><TxDtls>'
            .'<Refs><EndToEndId>E2E-1</EndToEndId></Refs>'
            .'<RmtInf><Ustrd>Betaling factuur 2026-0042</Ustrd></RmtInf>'
            .'<RltdPties><Cdtr><Nm>Klant B.V.</Nm></Cdtr>'
            .'<CdtrAcct><Id><IBAN>NL91ABNA0417164300</IBAN></Id></CdtrAcct></RltdPties>'
            .'</TxDtls></NtryDtls></Ntry>'
            .'<Ntry><Amt Ccy="EUR">50.00</Amt><CdtDbtInd>DBIT</CdtDbtInd>'
            .'<ValDt><Dt>2026-01-16</Dt></ValDt></Ntry>'
            .'</Stmt></BkToCstmrStmt></Document>';

        $lines = $this->parser->parse($xml, 'camt053');

        self::assertCount(expectedCount: 2, haystack: $lines);
        self::assertSame(expected: '2026-01-15', actual: $lines[0]['valueDate']);
        self::assertSame(expected: 1210.00, actual: $lines[0]['amount']);
        self::assertSame(expected: 'Betaling factuur 2026-0042', actual: $lines[0]['remittanceInfo']);
        self::assertSame(expected: 'Klant B.V.', actual: $lines[0]['counterpartyName']);
        self::assertSame(expected: 'NL91ABNA0417164300', actual: $lines[0]['counterpartyIban']);
        // Debit entry is negated.
        self::assertSame(expected: -50.00, actual: $lines[1]['amount']);

    }//end testParseCamt053()

    /**
     * Malformed CAMT.053 XML yields an empty array, not an exception.
     *
     * @return void
     */
    public function testParseCamt053MalformedReturnsEmpty(): void
    {
        self::assertSame(expected: [], actual: $this->parser->parse('<not-valid-xml', 'camt053'));

    }//end testParseCamt053MalformedReturnsEmpty()

    /**
     * MT940 text with two :61: lines parses into two normalised lines with
     * the :86: description attached.
     *
     * @return void
     */
    public function testParseMt940(): void
    {
        $mt940 = ":20:STARTUMS\r\n"
            .":61:2601150115C1210,00N123NONREF\r\n"
            .":86:Betaling factuur 2026-0042\r\n"
            .":61:2601160116D50,00N456NONREF\r\n"
            .":86:Bankkosten\r\n";

        $lines = $this->parser->parse($mt940, 'mt940');

        self::assertCount(expectedCount: 2, haystack: $lines);
        self::assertSame(expected: '2026-01-15', actual: $lines[0]['valueDate']);
        self::assertSame(expected: 1210.00, actual: $lines[0]['amount']);
        self::assertSame(expected: 'Betaling factuur 2026-0042', actual: $lines[0]['remittanceInfo']);
        self::assertSame(expected: -50.00, actual: $lines[1]['amount']);

    }//end testParseMt940()

    /**
     * An unknown format returns an empty array.
     *
     * @return void
     */
    public function testParseUnknownFormatReturnsEmpty(): void
    {
        self::assertSame(expected: [], actual: $this->parser->parse('anything', 'ofx'));

    }//end testParseUnknownFormatReturnsEmpty()

    /**
     * AllLinesResolved returns true when no unmatched lines remain.
     *
     * @return void
     */
    public function testAllLinesResolvedTrueWhenNoUnmatched(): void
    {
        $objectService = $this->buildObjectServiceStub(unmatched: []);
        $this->container->method('get')->willReturn($objectService);

        self::assertTrue(condition: $this->parser->allLinesResolved(['statementId' => 'BS-1']));

    }//end testAllLinesResolvedTrueWhenNoUnmatched()

    /**
     * AllLinesResolved returns false when unmatched lines remain.
     *
     * @return void
     */
    public function testAllLinesResolvedFalseWhenUnmatchedRemain(): void
    {
        $objectService = $this->buildObjectServiceStub(unmatched: [['lineId' => 'L1', 'status' => 'unmatched']]);
        $this->container->method('get')->willReturn($objectService);

        self::assertFalse(condition: $this->parser->allLinesResolved(['statementId' => 'BS-1']));

    }//end testAllLinesResolvedFalseWhenUnmatchedRemain()

    /**
     * AllLinesResolved is fail-closed: returns false on exception.
     *
     * @return void
     */
    public function testAllLinesResolvedFailClosedOnException(): void
    {
        $this->container->method('get')->willThrowException(new \RuntimeException('boom'));

        self::assertFalse(condition: $this->parser->allLinesResolved(['statementId' => 'BS-1']));

    }//end testAllLinesResolvedFailClosedOnException()

    /**
     * Build a fluent ObjectService stub returning the given unmatched lines.
     *
     * @param array<mixed> $unmatched Lines to return from findAll().
     *
     * @return object
     */
    private function buildObjectServiceStub(array $unmatched): object
    {
        return new class($unmatched) {
            /**
             * Constructor for the anonymous ObjectService stub.
             *
             * @param array<mixed> $unmatched Unmatched lines to return from findAll().
             *
             * @return void
             */
            public function __construct(private array $unmatched)
            {
            }//end __construct()

            /**
             * Set the register (no-op stub).
             *
             * @param string $register Register slug.
             *
             * @return static
             */
            public function setRegister(string $register): static
            {
                return $this;
            }//end setRegister()

            /**
             * Set the schema (no-op stub).
             *
             * @param string $schema Schema slug.
             *
             * @return static
             */
            public function setSchema(string $schema): static
            {
                return $this;
            }//end setSchema()

            /**
             * Find all unmatched lines for the current context.
             *
             * @param array<string,mixed> $params Query parameters.
             *
             * @return array<mixed>
             */
            public function findAll(array $params=[]): array
            {
                return $this->unmatched;
            }//end findAll()
        };

    }//end buildObjectServiceStub()
}//end class
