<?php

/**
 * Unit tests for SepaPain008Generator and SepaPain008Validator.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Receivables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-the-batch-is-written-as-a-validated-pain008-file-req-sdd-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Receivables;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Receivables\SepaPain008Generator;
use OCA\Shillinq\Receivables\SepaPain008Validator;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SDD-012: the batch is written as a pain.008.001.02 file that passes the XSD.
 *
 * @covers \OCA\Shillinq\Receivables\SepaPain008Generator
 * @covers \OCA\Shillinq\Receivables\SepaPain008Validator
 * @uses   \OCA\Shillinq\PaymentRun\RenderedFile
 * @uses   \OCA\Shillinq\Validation\IbanValidator
 */
class SepaPain008GeneratorTest extends TestCase {

	private const CREDITOR = [
		'name' => 'Voorbeeld Consultancy B.V.',
		'iban' => 'NL20INGB0001234567',
		'bic' => '',
		'creditorIdentifier' => 'NL98ZZZ999999999999',
	];

	private const BATCH = [
		'id' => 'b-1',
		'messageId' => 'DD-20261001093000-CORE-RCUR',
		'creationDateTime' => '2026-10-01T09:30:00+00:00',
		'requestedCollectionDate' => '2026-10-15',
		'scheme' => 'CORE',
		'sequenceType' => 'RCUR',
		'status' => 'draft',
	];

	/**
	 * A collection with its mandate and remittance text.
	 *
	 * @param string               $ref     The mandate reference.
	 * @param float                $amount  The amount.
	 * @param array<string, mixed> $mandate Mandate fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function collection(string $ref, float $amount, array $mandate=[]): array {
		return [
			'endToEndId' => 'DD-F-' . $ref,
			'amount' => $amount,
			'currency' => 'EUR',
			'remittanceInfo' => 'F-' . $ref,
			'mandate' => $mandate + [
				'mandateReference' => $ref,
				'signedAt' => '2026-01-05T10:00:00+01:00',
				'debtorIban' => 'NL91 ABNA 0417 1643 00',
				'debtorBic' => 'abnanl2a',
				'debtorName' => 'Klant ' . $ref,
			],
		];

	}//end collection()

	/**
	 * The XPath over a rendered document, with the pain.008 namespace as `p`.
	 *
	 * @param string $xml The document.
	 *
	 * @return DOMXPath
	 */
	private static function xpath(string $xml): DOMXPath {
		$document = new DOMDocument();
		$document->loadXML($xml);
		$xpath = new DOMXPath($document);
		$xpath->registerNamespace('p', SepaPain008Generator::PAIN_NS);

		return $xpath;

	}//end xpath()

	/**
	 * Two collections totalling 1,250.00 write a valid file with that control sum.
	 *
	 * @return void
	 */
	public function testTwoCollectionsWriteAValidFileWithTheirControlSum(): void {
		$collections = [self::collection(ref: 'MR-1', amount: 1000.0), self::collection(ref: 'MR-2', amount: 250.0, mandate: ['debtorBic' => ''])];

		$file = (new SepaPain008Generator())->render(batch: self::BATCH, collections: $collections, creditor: self::CREDITOR);

		$this->assertSame([], (new SepaPain008Validator())->errors(xml: $file->content, collections: $collections, creditor: self::CREDITOR));
		$this->assertSame('DD-20261001093000-CORE-RCUR.pain008.xml', $file->fileName);
		$this->assertSame('text/xml', $file->mimeType);
		$this->assertSame('sepa-pain008', $file->format);

		$xpath = self::xpath(xml: $file->content);
		$value = static fn (string $path): string => (string)$xpath->evaluate('string(' . $path . ')');
		$this->assertSame('2', $value('/p:Document/p:CstmrDrctDbtInitn/p:GrpHdr/p:NbOfTxs'));
		$this->assertSame('1250.00', $value('/p:Document/p:CstmrDrctDbtInitn/p:GrpHdr/p:CtrlSum'));
		$this->assertSame('DD-20261001093000-CORE-RCUR', $value('//p:GrpHdr/p:MsgId'));
		$this->assertSame('2026-10-01T09:30:00+00:00', $value('//p:GrpHdr/p:CreDtTm'));
		$this->assertSame('DD', $value('//p:PmtInf/p:PmtMtd'));
		$this->assertSame('1250.00', $value('//p:PmtInf/p:CtrlSum'));
		$this->assertSame('CORE', $value('//p:PmtTpInf/p:LclInstrm/p:Cd'));
		$this->assertSame('RCUR', $value('//p:PmtTpInf/p:SeqTp'));
		$this->assertSame('2026-10-15', $value('//p:PmtInf/p:ReqdColltnDt'));
		$this->assertSame('NL20INGB0001234567', $value('//p:CdtrAcct/p:Id/p:IBAN'));
		$this->assertSame('NOTPROVIDED', $value('//p:CdtrAgt/p:FinInstnId/p:Othr/p:Id'));
		$this->assertSame('NL98ZZZ999999999999', $value('//p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:Id'));
		$this->assertSame('SLEV', $value('//p:PmtInf/p:ChrgBr'));

		$this->assertSame(2.0, $xpath->evaluate('count(//p:DrctDbtTxInf)'));
		$this->assertSame('DD-F-MR-1', $value('//p:DrctDbtTxInf[1]/p:PmtId/p:EndToEndId'));
		$this->assertSame('1000.00', $value('//p:DrctDbtTxInf[1]/p:InstdAmt'));
		$this->assertSame('EUR', $value('//p:DrctDbtTxInf[1]/p:InstdAmt/@Ccy'));
		$this->assertSame('MR-1', $value('//p:DrctDbtTxInf[1]//p:MndtRltdInf/p:MndtId'));
		$this->assertSame('2026-01-05', $value('//p:DrctDbtTxInf[1]//p:MndtRltdInf/p:DtOfSgntr'));
		$this->assertSame('ABNANL2A', $value('//p:DrctDbtTxInf[1]/p:DbtrAgt/p:FinInstnId/p:BIC'));
		$this->assertSame('NL91ABNA0417164300', $value('//p:DrctDbtTxInf[1]/p:DbtrAcct/p:Id/p:IBAN'));
		$this->assertSame('Klant MR-1', $value('//p:DrctDbtTxInf[1]/p:Dbtr/p:Nm'));
		$this->assertSame('F-MR-1', $value('//p:DrctDbtTxInf[1]/p:RmtInf/p:Ustrd'));
		$this->assertSame('NOTPROVIDED', $value('//p:DrctDbtTxInf[2]/p:DbtrAgt/p:FinInstnId/p:Othr/p:Id'));

	}//end testTwoCollectionsWriteAValidFileWithTheirControlSum()

	/**
	 * A B2B one-off batch says so, and over-long identifiers and names are cut to fit.
	 *
	 * @return void
	 */
	public function testABusinessOneOffBatchIsMarkedAndLongValuesFit(): void {
		$long = str_repeat('x', 80);
		$collections = [self::collection(ref: str_repeat('M', 40), amount: 12.345, mandate: ['debtorName' => $long])];
		$batch = ['scheme' => 'B2B', 'sequenceType' => 'OOFF', 'messageId' => str_repeat('D', 40)] + self::BATCH;

		$file = (new SepaPain008Generator())->render(batch: $batch, collections: $collections, creditor: ['bic' => 'INGBNL2A'] + self::CREDITOR);

		$this->assertSame([], (new SepaPain008Validator())->errors(xml: $file->content, collections: $collections, creditor: self::CREDITOR));
		$xpath = self::xpath(xml: $file->content);
		$this->assertSame('B2B', (string)$xpath->evaluate('string(//p:LclInstrm/p:Cd)'));
		$this->assertSame('OOFF', (string)$xpath->evaluate('string(//p:SeqTp)'));
		$this->assertSame(str_repeat('M', 35), (string)$xpath->evaluate('string(//p:MndtId)'));
		$this->assertSame(str_repeat('D', 35), (string)$xpath->evaluate('string(//p:GrpHdr/p:MsgId)'));
		$this->assertSame(str_repeat('x', 70), (string)$xpath->evaluate('string(//p:Dbtr/p:Nm)'));
		$this->assertSame('INGBNL2A', (string)$xpath->evaluate('string(//p:CdtrAgt/p:FinInstnId/p:BIC)'));
		$this->assertSame('12.35', (string)$xpath->evaluate('string(//p:InstdAmt)'));
		$this->assertSame(12.35, (new SepaPain008Generator())->controlSum(collections: $collections));

	}//end testABusinessOneOffBatchIsMarkedAndLongValuesFit()

	/**
	 * A debtor IBAN with a wrong check digit is an error naming its mandate.
	 *
	 * @return void
	 */
	public function testAWrongCheckDigitNamesTheMandate(): void {
		$collections = [self::collection(ref: 'MR-1', amount: 10.0), self::collection(ref: 'MR-BAD', amount: 20.0, mandate: ['debtorIban' => 'NL92ABNA0417164300'])];
		$file = (new SepaPain008Generator())->render(batch: self::BATCH, collections: $collections, creditor: self::CREDITOR);

		$this->assertSame(
			['Mandate MR-BAD: the debtor IBAN NL92ABNA0417164300 is not valid.'],
			(new SepaPain008Validator())->errors(xml: $file->content, collections: $collections, creditor: self::CREDITOR)
		);

	}//end testAWrongCheckDigitNamesTheMandate()

	/**
	 * The creditor's details, a missing mandate and an empty batch are errors.
	 *
	 * @return void
	 */
	public function testTheCreditorAndEveryCollectionMustBeComplete(): void {
		$creditor = ['name' => ' ', 'iban' => 'NL21INGB0001234567', 'creditorIdentifier' => 'nope'];
		$errors = (new SepaPain008Validator())->errors(xml: '<x/>', collections: [['endToEndId' => 'E-1']], creditor: $creditor);

		$this->assertSame('The administration has no name to collect under.', $errors[0]);
		$this->assertSame('The creditor IBAN "NL21INGB0001234567" of the administration is not valid.', $errors[1]);
		$this->assertSame('The creditor identifier "NOPE" is not a SEPA creditor identifier.', $errors[2]);
		$this->assertSame('Collection E-1 has no mandate.', $errors[3]);
		$this->assertStringStartsWith('XSD line 1: ', $errors[4]);

		$empty = (new SepaPain008Validator())->errors(xml: '<x/>', collections: [], creditor: self::CREDITOR);
		$this->assertSame('The batch has no collections.', $empty[0]);

	}//end testTheCreditorAndEveryCollectionMustBeComplete()

	/**
	 * A document the XSD refuses is reported by line, and unreadable XML is refused too.
	 *
	 * @return void
	 */
	public function testTheXsdRefusesAMalformedDocument(): void {
		$collections = [self::collection(ref: 'MR-1', amount: 10.0)];
		$file = (new SepaPain008Generator())->render(batch: ['sequenceType' => 'LAST'] + self::BATCH, collections: $collections, creditor: self::CREDITOR);

		$errors = (new SepaPain008Validator())->errors(xml: $file->content, collections: $collections, creditor: self::CREDITOR);
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('SeqTp', $errors[0]);
		$this->assertStringStartsWith('XSD line ', $errors[0]);

		$this->assertNotSame([], (new SepaPain008Validator())->errors(xml: 'not xml', collections: $collections, creditor: self::CREDITOR));
		$this->assertFileExists(SepaPain008Validator::schemaPath());

	}//end testTheXsdRefusesAMalformedDocument()
}//end class
