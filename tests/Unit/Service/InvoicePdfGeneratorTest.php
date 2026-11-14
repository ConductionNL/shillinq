<?php

/**
 * Unit tests for InvoicePdfGenerator, including the hybrid PDF embed path
 * added by add-invoice-pdf-export-with-ubl-peppol-support (REQ-EINV-002)
 * and corrected by facturx-cii-conformance (REQ-EINV-002 / REQ-EINV-008):
 * the embedded filename is `ubl-invoice.xml` (not the Factur-X/ZUGFeRD
 * well-known `factur-x.xml`, since the payload is UBL not CII) and the
 * PDF's XMP metadata does NOT assert `pdfaid:part`/`pdfaid:conformance`
 * (the byte-writer emits no ICC OutputIntent and does not embed its font,
 * so no PDF/A conformance level is actually met).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/facturx-cii-conformance/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-002
 * @spec openspec/changes/facturx-cii-conformance/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Reporting\DocudeskUnavailableException;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceAmountFormatter;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceDocumentData;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceDocumentRenderer;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceDocumentSources;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceLabels;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the docudesk PDF path (REQ-SID-002) and the hybrid embed path
 * (REQ-EINV-002 / REQ-EINV-008).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoicePdfGeneratorTest extends TestCase {
	/**
	 * Without docudesk generatePdf() refuses before reading any record
	 * (REQ-SID-002), and the hybrid path below still works without it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function testWithoutDocudeskThePdfIsRefusedBeforeAnyRecordIsRead(): void {
		$renderer = $this->createMock(InvoiceDocumentRenderer::class);
		$renderer->method('assertAvailable')->willThrowException(new DocudeskUnavailableException('absent'));
		$renderer->expects($this->never())->method('renderPdf');
		$sources = $this->createMock(InvoiceDocumentSources::class);
		$sources->expects($this->never())->method('administration');

		$this->expectException(DocudeskUnavailableException::class);
		(new InvoicePdfGenerator($renderer, $this->documentData(), $sources))->generatePdf(
			invoice: ['invoiceNumber' => '2026-0042', 'administrationId' => 'adm-1'],
			lines: []
		);

	}//end testWithoutDocudeskThePdfIsRefusedBeforeAnyRecordIsRead()

	/**
	 * Built without its collaborators, the generator reports docudesk's absence.
	 *
	 * @return void
	 */
	public function testWithoutCollaboratorsDocudeskIsReportedAbsent(): void {
		$this->expectException(DocudeskUnavailableException::class);
		(new InvoicePdfGenerator())->generatePdf(invoice: ['invoiceNumber' => '2026-0042'], lines: []);

	}//end testWithoutCollaboratorsDocudeskIsReportedAbsent()

	/**
	 * With docudesk the served file is the PDF it renders, in the customer's
	 * language and currency, in the administration's layout, with the
	 * creditor read from the administration (REQ-SID-002 to REQ-SID-005).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 */
	public function testWithDocudeskTheServedFileIsItsPdf(): void {
		$sources = $this->createMock(InvoiceDocumentSources::class);
		$sources->method('administration')->with('adm-1')->willReturn(
			['name' => 'Adviesbureau Van Dijk', 'primaryIban' => 'NL91ABNA0417164300', 'defaultLanguage' => 'nl']
		);
		$sources->method('customer')->with('c-7', 'adm-1')->willReturn(['legalName' => 'Gemeente Voorbeeld', 'language' => '']);
		$sources->method('layout')->with('adm-1')->willReturn(['variant' => 'modern', 'logoFileId' => '48213']);
		$sources->method('payLink')->with('2026-0042', 'adm-1')->willReturn(null);
		$sources->method('logoDataUri')->with('48213')->willReturn('data:image/png;base64,AA==');

		$seen     = null;
		$renderer = $this->createMock(InvoiceDocumentRenderer::class);
		$renderer->expects($this->once())->method('assertAvailable');
		$renderer->method('renderPdf')->willReturnCallback(
			static function (array $data, string $userId) use (&$seen): string {
				$seen = [$data, $userId];
				return '%PDF-1.7 rendered';
			}
		);

		$result = (new InvoicePdfGenerator($renderer, $this->documentData(), $sources))->generatePdf(
			invoice: ['invoiceNumber' => '2026-0042', 'administrationId' => 'adm-1', 'customerId' => 'c-7', 'currency' => 'EUR', 'grossAmount' => 24200],
			lines: [],
			userId: 'alice'
		);

		self::assertSame(['filename', 'pdf', 'mimeType', 'language'], array_keys($result));
		self::assertStringStartsWith('%PDF', $result['pdf']);
		self::assertSame('invoice-2026-0042.pdf', $result['filename']);
		self::assertSame('application/pdf', $result['mimeType']);
		self::assertSame('nl', $result['language']);
		self::assertSame('alice', $seen[1]);
		self::assertSame('Adviesbureau Van Dijk', $seen[0]['creditor']['name']);
		self::assertSame('Gemeente Voorbeeld', $seen[0]['recipient']['name']);
		self::assertSame('modern', $seen[0]['layout']['variant']);
		self::assertSame('data:image/png;base64,AA==', $seen[0]['layout']['logo']);
		self::assertStringContainsString("\nEUR24200.00\n", (string)$seen[0]['qr']['epc']);

	}//end testWithDocudeskTheServedFileIsItsPdf()

	/**
	 * An English GBP customer passed in by the caller gets an English pound document.
	 *
	 * @return void
	 */
	public function testTheCallersRecipientSetsTheLanguage(): void {
		$seen     = null;
		$renderer = $this->createMock(InvoiceDocumentRenderer::class);
		$renderer->method('renderPdf')->willReturnCallback(
			static function (array $data) use (&$seen): string {
				$seen = $data;
				return '%PDF';
			}
		);

		$result = (new InvoicePdfGenerator($renderer, $this->documentData()))->generatePdf(
			invoice: ['invoiceNumber' => 'F-1', 'currency' => 'GBP', 'grossAmount' => 4800],
			lines: [],
			creditor: ['legalName' => 'Van Dijk', 'iban' => 'NL91ABNA0417164300'],
			recipient: ['legalName' => 'Brightside Consulting Ltd', 'language' => 'en']
		);

		self::assertSame('en', $result['language']);
		self::assertSame('£4,800.00', $seen['invoice']['gross']);
		self::assertNull($seen['qr']['epc']);

	}//end testTheCallersRecipientSetsTheLanguage()

	/**
	 * The real data assembler, with labels echoing their source.
	 *
	 * @return InvoiceDocumentData
	 */
	private function documentData(): InvoiceDocumentData {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new InvoiceDocumentData(new InvoiceLabels($factory), new InvoiceAmountFormatter());

	}//end documentData()

	/**
	 * REQ-EINV-002 scenario 1: the hybrid export embeds the XML as
	 * AFRelationship=Alternative under the truthful `ubl-invoice.xml`
	 * filename — NOT the Factur-X/ZUGFeRD well-known `factur-x.xml` name,
	 * since the embedded payload is UBL, not UN/CEFACT CII.
	 *
	 * @return void
	 */
	public function testHybridExportEmbedsXmlAsAlternativeAssociatedFile(): void {
		$generator = new InvoicePdfGenerator();
		$ublXml = '<Invoice><cbc:ID>2026-0042</cbc:ID></Invoice>';

		$result = $generator->generateHybridPdf(
			invoice: ['invoiceNumber' => '2026-0042', 'grossAmount' => 1210.0, 'currency' => 'EUR'],
			lines: [],
			ublXml: $ublXml
		);

		self::assertSame(['filename', 'pdf', 'mimeType', 'embeddedXmlFilename'], array_keys($result));
		self::assertSame('application/pdf', $result['mimeType']);
		self::assertSame(InvoicePdfGenerator::HYBRID_XML_FILENAME, $result['embeddedXmlFilename']);
		self::assertSame('ubl-invoice.xml', $result['embeddedXmlFilename']);

		$pdf = $result['pdf'];
		self::assertStringStartsWith('%PDF-1.7', $pdf);
		self::assertStringContainsString('%%EOF', $pdf);
		self::assertStringContainsString('/AFRelationship /Alternative', $pdf);
		self::assertStringContainsString('/Type /EmbeddedFile', $pdf);
		// The embedded XML bytes are retrievable verbatim from the PDF stream.
		self::assertStringContainsString($ublXml, $pdf);
		// Neither the Factur-X/ZUGFeRD filename nor a false PDF/A
		// conformance claim is present (REQ-EINV-002 / REQ-EINV-008 —
		// this generator emits no ICC OutputIntent and does not embed its
		// font, so it does not meet any PDF/A conformance level).
		self::assertStringNotContainsString('factur-x.xml', $pdf);
		self::assertStringNotContainsString('pdfaid:part', $pdf);
		self::assertStringNotContainsString('pdfaid:conformance', $pdf);

	}//end testHybridExportEmbedsXmlAsAlternativeAssociatedFile()

	/**
	 * The written PDF structure is byte-accurate: the xref table's object
	 * offsets each point at a real "N 0 obj" marker (regression guard against
	 * off-by-one errors in the byte-writer).
	 *
	 * @return void
	 */
	public function testXrefOffsetsPointAtRealObjects(): void {
		$generator = new InvoicePdfGenerator();
		$result = $generator->generateHybridPdf(
			invoice: ['invoiceNumber' => '2026-0042', 'grossAmount' => 1210.0, 'currency' => 'EUR'],
			lines: [],
			ublXml: '<Invoice/>'
		);
		$pdf = $result['pdf'];

		$xrefPos = strpos($pdf, "\nxref\n");
		self::assertNotFalse($xrefPos);
		$tableStart = ($xrefPos + strlen("\nxref\n"));
		$lines = explode("\n", substr($pdf, $tableStart));
		// First line is "0 N" (subsection header); skip the free-object line.
		array_shift($lines);
		array_shift($lines);

		for ($i = 1; $i <= 8; $i++) {
			$entry = $lines[($i - 1)];
			self::assertMatchesRegularExpression('/^\d{10} \d{5} n $/', $entry);
			$offset = (int)substr($entry, 0, 10);
			self::assertSame(
				$i . ' 0 obj',
				substr($pdf, $offset, strlen($i . ' 0 obj')),
				"object $i xref offset does not point at its \"N 0 obj\" marker"
			);
		}

	}//end testXrefOffsetsPointAtRealObjects()

	/**
	 * The hybrid e-invoice PDF prints the ARInvoice's own lines on its page.
	 * EInvoiceService hands the generator `invoiceLines`; until now the page
	 * carried one summary line and the lines were never printed (REQ-EINV-009).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#requirement-req-einv-009-the-hybrid-pdf-shall-print-the-arinvoices-own-lines
	 */
	public function testTheHybridPdfPrintsTheArInvoiceLines(): void {
		$result = (new InvoicePdfGenerator())->generateHybridPdf(
			invoice: ['invoiceNumber' => '2026-0042', 'grossAmount' => 242.0, 'currency' => 'EUR'],
			lines: [
				['lineId' => '1', 'itemName' => 'Consulting (senior)', 'quantity' => 2, 'netPrice' => 100.0, 'netAmount' => 200.0, 'vatRate' => 21],
				['lineId' => '2', 'itemName' => 'Travel', 'quantity' => 1, 'netPrice' => 42.5, 'netAmount' => 42.5, 'vatRate' => 0],
			],
			ublXml: '<Invoice/>'
		);

		self::assertStringContainsString('(1  Consulting \\(senior\\)  2 x 100,00 = 200,00 EUR  btw 21%)', $result['pdf']);
		self::assertStringContainsString('(2  Travel  1 x 42,50 = 42,50 EUR  btw 0%)', $result['pdf']);
		self::assertStringContainsString('Factuur 2026-0042 - EUR 242,00', $result['pdf']);
	}//end testTheHybridPdfPrintsTheArInvoiceLines()
}//end class
