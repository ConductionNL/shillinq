<?php

/**
 * Tests for the CAMT.053 booked-outgoing-entry parser.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentRun
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\PaymentRun;

use OCA\Shillinq\PaymentRun\Camt053StatementParser;
use PHPUnit\Framework\TestCase;

/**
 * Pins which entries the parser yields and how each is read.
 */
final class Camt053StatementParserTest extends TestCase {

	/**
	 * Booked debit entries yield one entry per transaction; others are skipped.
	 *
	 * @return void
	 */
	public function testBookedOutgoingEntriesAreRead(): void {
		$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt>
<Ntry><Amt Ccy="EUR">300.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts> book </Sts><NtryDtls>
  <TxDtls><Refs><EndToEndId>PR-1-1</EndToEndId></Refs><Amt Ccy="eur">100.004</Amt><RltdPties><CdtrAcct><Id><IBAN> NL20INGB0001234567 </IBAN></Id></CdtrAcct></RltdPties></TxDtls>
  <TxDtls><Refs><EndToEndId>notprovided</EndToEndId></Refs></TxDtls>
  <TxDtls><Refs><EndToEndId>PR-1-3</EndToEndId></Refs><Amt>50</Amt></TxDtls>
</NtryDtls></Ntry>
<Ntry><Amt Ccy="USD">12.5</Amt><CdtDbtInd>dbit</CdtDbtInd><Sts>BOOK</Sts></Ntry>
<Ntry><Amt Ccy="EUR">9.99</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts></Ntry>
<Ntry><Amt Ccy="EUR">8.88</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>PDNG</Sts></Ntry>
<Ntry><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts></Ntry>
</Stmt></BkToCstmrStmt></Document>
XML;

		self::assertSame(
			[
				['endToEndId' => 'PR-1-1', 'amount' => 100.0, 'creditorIban' => 'NL20INGB0001234567', 'currency' => 'EUR'],
				['endToEndId' => '', 'amount' => 300.0, 'creditorIban' => '', 'currency' => 'EUR'],
				['endToEndId' => 'PR-1-3', 'amount' => 50.0, 'creditorIban' => '', 'currency' => ''],
				['endToEndId' => '', 'amount' => 12.5, 'creditorIban' => '', 'currency' => 'USD'],
				['endToEndId' => '', 'amount' => 0.0, 'creditorIban' => '', 'currency' => ''],
			],
			(new Camt053StatementParser())->parse($xml)
		);

	}//end testBookedOutgoingEntriesAreRead()

	/**
	 * Empty and unparseable input yield nothing.
	 *
	 * @return void
	 */
	public function testEmptyAndInvalidInputYieldNothing(): void {
		$parser = new Camt053StatementParser();

		self::assertSame([], $parser->parse('   '));
		self::assertSame([], $parser->parse('<not xml'));
		self::assertSame([], $parser->parse('<Document/>'));

	}//end testEmptyAndInvalidInputYieldNothing()
}//end class
