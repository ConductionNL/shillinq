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

use OCA\Shillinq\Service\InvoicePdfGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pre-existing plain-PDF path (regression) and the hybrid embed
 * path (REQ-EINV-002 / REQ-EINV-008).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoicePdfGeneratorTest extends TestCase {
	/**
	 * REQ-EINV-002 scenario 2: the existing generatePdf() call is unaffected
	 * by the hybrid addition — same {filename, html, mimeType} shape.
	 *
	 * @return void
	 */
	public function testPlainPdfPathIsUnaffected(): void {
		$generator = new InvoicePdfGenerator();
		$result = $generator->generatePdf(
			invoice: ['invoiceNumber' => '2026-0042', 'grossAmount' => 1210.0],
			lines: [],
			creditor: ['legalName' => 'Shillinq Consultancy B.V.'],
			recipient: ['legalName' => 'Gemeente Zuidoost']
		);

		self::assertSame(['filename', 'html', 'mimeType'], array_keys($result));
		self::assertSame('invoice-2026-0042.pdf', $result['filename']);
		self::assertSame('application/pdf', $result['mimeType']);
		self::assertStringContainsString('Gemeente Zuidoost', $result['html']);

	}//end testPlainPdfPathIsUnaffected()

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
}//end class
