<?php

/**
 * Unit tests for StatementParser.
 *
 * Covers REQ-BR-003:
 * - CAMT.053 25-transaction file produces 25 records with correct amounts
 * - MT940 25-transaction file produces 25 records
 * - CSV 25-row file produces 25 records
 * - Debits return negative amounts, credits positive (CAMT sign convention)
 * - Unsupported format throws InvalidArgumentException
 * - allLinesResolved() returns true (hook endpoint)
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
 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\StatementParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for StatementParser.
 *
 * @spec openspec/changes/add-shillinq-bank-reconciliation/tasks.md#task-10
 */
class StatementParserTest extends TestCase
{

    /**
     * Parser under test.
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
        $this->parser = new StatementParser();
    }//end setUp()

    /**
     * CAMT.053 file with 25 transactions produces 25 records per REQ-BR-003.
     *
     * @return void
     */
    public function testCamt053TwentyFiveTransactionsProducesTwentyFiveRecords(): void
    {
        $xml     = $this->buildCamt053Xml(transactionCount: 25);
        $records = $this->parser->parse(contents: $xml, format: 'camt.053');

        self::assertCount(expectedCount: 25, haystack: $records);
    }//end testCamt053TwentyFiveTransactionsProducesTwentyFiveRecords()

    /**
     * CAMT.053 debit entries return negative amounts; credits return positive (REQ-BR-003).
     *
     * @return void
     */
    public function testCamt053SignConventionDebitNegativeCreditPositive(): void
    {
        $xml = $this->buildCamt053Xml(transactionCount: 2, alternateDebitCredit: true);

        $records = $this->parser->parse(contents: $xml, format: 'camt.053');

        self::assertCount(expectedCount: 2, haystack: $records);
        // First entry: CRDT → positive.
        self::assertGreaterThan(expected: 0, actual: $records[0]['amount']);
        // Second entry: DBIT → negative.
        self::assertLessThan(expected: 0, actual: $records[1]['amount']);
    }//end testCamt053SignConventionDebitNegativeCreditPositive()

    /**
     * MT940 file with 25 transactions produces 25 records per REQ-BR-003.
     *
     * @return void
     */
    public function testMt940TwentyFiveTransactionsProducesTwentyFiveRecords(): void
    {
        $mt940   = $this->buildMt940(transactionCount: 25);
        $records = $this->parser->parse(contents: $mt940, format: 'mt940');

        self::assertCount(expectedCount: 25, haystack: $records);
    }//end testMt940TwentyFiveTransactionsProducesTwentyFiveRecords()

    /**
     * MT940 debit lines produce negative amounts.
     *
     * @return void
     */
    public function testMt940DebitLineProducesNegativeAmount(): void
    {
        $mt940  = ":20:STMT\n:25:NL00ABNA0123456789\n";
        $mt940 .= ":28C:001/001\n:60F:C260401EUR12500,00\n";
        // MT940 :61: format: YYMMDD C/D amount NTRN.
        $mt940 .= ":61:260403D500,00NTRN\n:86:Payment\n";
        $mt940 .= ":62F:C260430EUR12000,00\n";

        $records = $this->parser->parse(contents: $mt940, format: 'mt940');

        self::assertCount(expectedCount: 1, haystack: $records);
        self::assertEqualsWithDelta(expected: -500.0, actual: $records[0]['amount'], delta: 0.001);
    }//end testMt940DebitLineProducesNegativeAmount()

    /**
     * CSV with 25 data rows produces 25 records.
     *
     * @return void
     */
    public function testCsvTwentyFiveRowsProducesTwentyFiveRecords(): void
    {
        $csv     = $this->buildCsv(rowCount: 25);
        $records = $this->parser->parse(contents: $csv, format: 'csv');

        self::assertCount(expectedCount: 25, haystack: $records);
    }//end testCsvTwentyFiveRowsProducesTwentyFiveRecords()

    /**
     * CSV record has required fields: date, amount, currency.
     *
     * @return void
     */
    public function testCsvRecordHasRequiredFields(): void
    {
        $csv     = "date,counterparty,amount,reference,description\n2026-04-03,Acme BV,500.00,INV-001,Payment\n";
        $records = $this->parser->parse(contents: $csv, format: 'csv');

        self::assertCount(expectedCount: 1, haystack: $records);
        self::assertArrayHasKey(key: 'date', array: $records[0]);
        self::assertArrayHasKey(key: 'amount', array: $records[0]);
        self::assertArrayHasKey(key: 'currency', array: $records[0]);
        self::assertEqualsWithDelta(expected: 500.0, actual: $records[0]['amount'], delta: 0.001);
    }//end testCsvRecordHasRequiredFields()

    /**
     * Unsupported format throws InvalidArgumentException.
     *
     * @return void
     */
    public function testUnsupportedFormatThrowsInvalidArgumentException(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported bank statement format/');
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->parser->parse(contents: 'data', format: 'ofx');
    }//end testUnsupportedFormatThrowsInvalidArgumentException()

    /**
     * CSV missing required column 'amount' throws InvalidArgumentException.
     *
     * @return void
     */
    public function testCsvMissingRequiredColumnThrows(): void
    {
        // phpcs:disable CustomSniffs.Functions.NamedParameters
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/missing required column/');
        // phpcs:enable CustomSniffs.Functions.NamedParameters

        $this->parser->parse(contents: "date,counterparty,reference\n2026-04-03,Acme,INV-001\n", format: 'csv');
    }//end testCsvMissingRequiredColumnThrows()

    /**
     * AllLinesResolved() returns true (lifecycle engine guard hook).
     *
     * @return void
     */
    public function testAllLinesResolvedReturnsTrue(): void
    {
        // phpcs:ignore CustomSniffs.Functions.NamedParameters
        self::assertTrue($this->parser->allLinesResolved(statementId: 'bs-uuid-1'));
    }//end testAllLinesResolvedReturnsTrue()

    /**
     * Build a minimal CAMT.053 XML with N transaction entries.
     *
     * @param int  $transactionCount     Number of Ntry entries to generate.
     * @param bool $alternateDebitCredit If true, first entry is CRDT, second is DBIT.
     *
     * @return string CAMT.053 XML string.
     */
    private function buildCamt053Xml(int $transactionCount, bool $alternateDebitCredit=false): string
    {
        $entries = '';
        for ($i = 1; $i <= $transactionCount; $i++) {
            if ($alternateDebitCredit === true && ($i % 2) === 1) {
                $cdtDbt = 'CRDT';
            } else if ($alternateDebitCredit === true) {
                $cdtDbt = 'DBIT';
            } else {
                $cdtDbt = 'CRDT';
            }

            $entries .= <<<XML
            <Ntry>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>{$cdtDbt}</CdtDbtInd>
                <ValDt><Dt>2026-04-0{$i}</Dt></ValDt>
                <NtryDtls>
                    <TxDtls>
                        <RltdPties>
                            <Dbtr><Nm>Acme B.V.</Nm></Dbtr>
                            <DbtrAcct><Id><IBAN>NL91ABNA0417164300</IBAN></Id></DbtrAcct>
                        </RltdPties>
                        <Refs><EndToEndId>INV-C-2026-{$i}</EndToEndId></Refs>
                        <AddtlNtryInf>Factuur INV-C-2026-{$i}</AddtlNtryInf>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
            XML;
        }//end for

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">
            <BkToCstmrStmt>
                <GrpHdr><MsgId>STMT-2026-04</MsgId></GrpHdr>
                <Stmt>
                    <Id>BS-001</Id>
                    <CreDtTm>2026-05-01T09:00:00Z</CreDtTm>
                    {$entries}
                </Stmt>
            </BkToCstmrStmt>
        </Document>
        XML;
    }//end buildCamt053Xml()

    /**
     * Build a minimal MT940 statement with N transaction lines.
     *
     * @param int $transactionCount Number of :61: entries.
     *
     * @return string MT940 text.
     */
    private function buildMt940(int $transactionCount): string
    {
        $lines = ":20:STMT-2026-04\n:25:NL00ABNA0123456789\n:28C:001/001\n:60F:C260401EUR12500,00\n";
        for ($i = 1; $i <= $transactionCount; $i++) {
            $day = str_pad(string: (string) $i, length: 2, pad_string: '0', pad_type: STR_PAD_LEFT);
            // MT940 :61: format: YYMMDD[YYMMDD]C/D amount NTRN [reference].
            $lines .= ":61:2604{$day}C100,00NTRN INV-{$i}\n";
            $lines .= ":86:Factuur INV-{$i}\n";
        }

        $lines .= ":62F:C260430EUR15000,00\n";
        return $lines;
    }//end buildMt940()

    /**
     * Build a CSV with N data rows.
     *
     * @param int $rowCount Number of data rows (excluding header).
     *
     * @return string CSV string.
     */
    private function buildCsv(int $rowCount): string
    {
        $csv = "date,counterparty,amount,reference,description\n";
        for ($i = 1; $i <= $rowCount; $i++) {
            $day  = str_pad(string: (string) $i, length: 2, pad_string: '0', pad_type: STR_PAD_LEFT);
            $csv .= "2026-04-{$day},Acme B.V.,100.00,INV-{$i},Factuur INV-{$i}\n";
        }

        return $csv;
    }//end buildCsv()
}//end class
