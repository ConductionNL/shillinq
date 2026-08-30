<?php

/**
 * Invoice PDF Generator
 *
 * Renders a Dutch-compliant invoice document (Task 20, issue #111). Produces
 * HTML by default; downstream renderers (mPDF / wkhtmltopdf / browser print)
 * convert it to PDF. Keeping the renderer HTML-first avoids a hard mPDF
 * dependency in this build — the controller advertises the document with
 * Content-Type: application/pdf when a PDF binary is requested and the
 * environment provides a converter.
 *
 * REQ-EINV-002 (add-invoice-pdf-export-with-ubl-peppol-support) adds a hybrid
 * path: {@see generateHybridPdf()} embeds a NLCIUS UBL 2.1 XML document (see
 * {@see \OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper}) into a single,
 * self-contained PDF binary as an `AFRelationship=Alternative` embedded file
 * (ISO 32000-2's Associated-Files mechanism), written with a small
 * dependency-free PDF byte-writer (no new composer dependency — design.md's
 * Trade-offs section rejects a heavy PDF/A-3 toolchain as gold-plating: the
 * XML is the compliance artefact and is transmitted to the Peppol access
 * point independently of the PDF's fidelity). This artefact serves the
 * NL/Peppol UBL send path ONLY — it is explicitly NOT a Factur-X/ZUGFeRD
 * document (those mandate UN/CEFACT CII syntax; the embedded payload here
 * is UBL, see REQ-EINV-008) and it does NOT declare PDF/A-3 conformance in
 * its XMP metadata, because it does not meet PDF/A-3's hard requirements:
 * no ICC `OutputIntent` is emitted and the page's Helvetica font is a
 * standard-14 reference, not embedded (both are independently disqualifying
 * under ISO 19005-3). Asserting `pdfaid:conformance` without meeting them
 * would be a false machine-readable claim (facturx-cii-conformance fixed an
 * earlier version of this file that did exactly that — see git history).
 * The existing plain-HTML {@see generatePdf()} path is untouched.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Dutch-formatted invoice HTML / PDF builder.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class InvoicePdfGenerator {
	/**
	 * Embedded-filename for the NLCIUS UBL hybrid attachment. Deliberately
	 * NOT the Factur-X/ZUGFeRD well-known name (`factur-x.xml`) — the
	 * embedded payload is UBL, not UN/CEFACT CII, so naming it as if it
	 * were Factur-X/ZUGFeRD would make DE/FR e-invoicing software
	 * auto-detect and attempt to parse it as the wrong syntax
	 * (facturx-cii-conformance / REQ-EINV-008).
	 *
	 * @var string
	 */
	public const HYBRID_XML_FILENAME = 'ubl-invoice.xml';

	/**
	 * Generate the renderable invoice payload.
	 *
	 * @param array<string,mixed> $invoice BillableInvoice record.
	 * @param array<int,array<string,mixed>> $lines BillableInvoiceLine records.
	 * @param array<string,mixed> $creditor Creditor (issuing party) details.
	 * @param array<string,mixed> $recipient Recipient details.
	 *
	 * @return array{filename:string,html:string,mimeType:string}
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
	 */
	public function generatePdf(
		array $invoice,
		array $lines,
		array $creditor = [],
		array $recipient = [],
	): array {
		$invoiceNumber = (string)($invoice['invoiceNumber'] ?? 'INVOICE');
		$html = $this->renderHtml(invoice: $invoice, lines: $lines, creditor: $creditor, recipient: $recipient);
		$filename = sprintf('invoice-%s.pdf', $invoiceNumber);

		return [
			'filename' => $filename,
			'html' => $html,
			'mimeType' => 'application/pdf',
		];

	}//end generatePdf()

	/**
	 * Generate the hybrid PDF export: the same human-readable HTML/summary
	 * as {@see generatePdf()}, materialised as a real PDF binary with the
	 * NLCIUS UBL XML embedded as an `AFRelationship=Alternative` associated
	 * file (REQ-EINV-002). This is the NL/Peppol UBL artefact — it is
	 * explicitly NOT Factur-X/ZUGFeRD (CII) and does NOT declare PDF/A-3
	 * conformance (REQ-EINV-008; see the class docblock for why).
	 *
	 * @param array<string,mixed> $invoice ARInvoice record (or any invoice-shaped
	 *                                     array carrying invoiceNumber/grossAmount/
	 *                                     currency).
	 * @param array<int,array<string,mixed>> $lines Invoice lines (used for the human-readable
	 *                                              summary only — the XML itself is supplied
	 *                                              pre-rendered by the caller).
	 * @param string $ublXml The NLCIUS UBL 2.1 XML document to embed
	 *                       (see {@see \OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper::toNlciusXml()}).
	 * @param array<string,mixed> $creditor Creditor (issuing party) details.
	 * @param array<string,mixed> $recipient Recipient details.
	 *
	 * @return array{filename:string,pdf:string,mimeType:string,embeddedXmlFilename:string}
	 *                                                                                      The `pdf` key carries the raw PDF binary (not base64).
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function generateHybridPdf(
		array $invoice,
		array $lines,
		string $ublXml,
		array $creditor = [],
		array $recipient = [],
	): array {
		$invoiceNumber = (string)($invoice['invoiceNumber'] ?? 'INVOICE');
		$html = $this->renderHtml(invoice: $invoice, lines: $lines, creditor: $creditor, recipient: $recipient);
		$pdfBytes = $this->buildHybridPdfBytes(invoice: $invoice, html: $html, xmlContent: $ublXml);
		$filename = sprintf('invoice-%s-hybrid.pdf', $invoiceNumber);

		return [
			'filename' => $filename,
			'pdf' => $pdfBytes,
			'mimeType' => 'application/pdf',
			'embeddedXmlFilename' => self::HYBRID_XML_FILENAME,
		];

	}//end generateHybridPdf()

	/**
	 * Build a minimal, self-contained PDF binary with the NLCIUS UBL XML
	 * embedded as an Associated File (`/AFRelationship /Alternative`,
	 * ISO 32000-2 §14.13). Written by hand (no PDF library dependency — see
	 * the class docblock) using a fixed 9-object layout with a correct
	 * cross-reference table so the result is a structurally valid PDF any
	 * standard viewer/extractor can open. Deliberately does NOT assert
	 * `pdfaid:part`/`pdfaid:conformance` XMP metadata — this byte-writer
	 * emits no ICC `OutputIntent` and does not embed the Helvetica font it
	 * references, so it does not meet PDF/A-3 (or any PDF/A level)
	 * conformance; asserting one would be a false claim
	 * (facturx-cii-conformance / REQ-EINV-002).
	 *
	 * @param array<string,mixed> $invoice Invoice record (invoiceNumber / grossAmount / currency).
	 * @param string $html Rendered HTML (used only to derive a one-line summary
	 *                     shown on the PDF page — the HTML markup itself is
	 *                     not embedded).
	 * @param string $xmlContent The UBL XML document to embed.
	 *
	 * @return string Raw PDF bytes.
	 */
	private function buildHybridPdfBytes(array $invoice, string $html, string $xmlContent): string {
		unset($html);

		$invoiceNumber = (string)($invoice['invoiceNumber'] ?? '');
		$currency = (string)($invoice['currency'] ?? 'EUR');
		$gross = (float)($invoice['grossAmount'] ?? 0);
		$summaryLine = sprintf('Factuur %s - %s %s', $invoiceNumber, $currency, number_format($gross, 2, ',', '.'));
		$now = gmdate('YmdHis') . '+00\'00\'';
		$xmlFilename = self::HYBRID_XML_FILENAME;

		$contentStream = 'BT /F1 14 Tf 56 770 Td (' . $this->pdfEscape(value: $summaryLine) . ') Tj ET';

		// Only a dc:title entry — no pdfaid:part/pdfaid:conformance
		// assertion (see this method's docblock for why: no ICC
		// OutputIntent, no embedded font, so no PDF/A conformance level is
		// actually met).
		$xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
			. '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">'
			. '<dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . htmlspecialchars($summaryLine, ENT_QUOTES) . '</rdf:li></rdf:Alt></dc:title>'
			. '</rdf:Description>'
			. '</rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

		// Object numbering: 1 Catalog, 2 Pages, 3 Page, 4 Font, 5 Content
		// stream, 6 EmbeddedFile stream, 7 Filespec, 8 XMP Metadata stream.
		$objects = [];

		$objects[1] = '<< /Type /Catalog /Pages 2 0 R /Metadata 8 0 R'
			. ' /Names << /EmbeddedFiles << /Names [(' . $this->pdfEscape(value: $xmlFilename) . ') 7 0 R] >> >>'
			. ' /AF [7 0 R] >>';

		$objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

		$objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
			. ' /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R /AF [7 0 R] >>';

		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

		$objects[5] = '<< /Length ' . strlen($contentStream) . " >>\nstream\n" . $contentStream . "\nendstream";

		$objects[6] = '<< /Type /EmbeddedFile /Subtype /application#2Fxml /Length ' . strlen($xmlContent)
			. ' /Params << /ModDate (D:' . $now . ") >> >>\nstream\n" . $xmlContent . "\nendstream";

		$objects[7] = '<< /Type /Filespec /F (' . $this->pdfEscape(value: $xmlFilename) . ')'
			. ' /UF (' . $this->pdfEscape(value: $xmlFilename) . ')'
			. ' /AFRelationship /Alternative'
			. ' /Desc (NLCIUS UBL 2.1 Invoice - urn:cen.eu:en16931:2017#compliant#urn:fdc:nen.nl:nlcius:v1.0)'
			. ' /EF << /F 6 0 R /UF 6 0 R >> >>';

		$objects[8] = '<< /Type /Metadata /Subtype /XML /Length ' . strlen($xmp) . ' >>' . "\nstream\n" . $xmp . "\nendstream";

		return $this->assemblePdf(objects: $objects);
	}//end buildHybridPdfBytes()

	/**
	 * Serialise a set of PDF objects (indexed by object number starting at 1)
	 * into a complete PDF document with a correct cross-reference table and
	 * trailer.
	 *
	 * @param array<int,string> $objects Object bodies keyed by object number
	 *                                   (without the `N 0 obj` / `endobj` wrapper).
	 *
	 * @return string
	 */
	private function assemblePdf(array $objects): string {
		ksort($objects);
		$count = count($objects);

		$out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
		$offsets = [0 => 0];

		foreach ($objects as $number => $body) {
			$offsets[$number] = strlen($out);
			$out .= $number . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xrefOffset = strlen($out);
		$out .= "xref\n0 " . ($count + 1) . "\n";
		$out .= "0000000000 65535 f \n";
		for ($i = 1; $i <= $count; $i++) {
			$out .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}

		$out .= "trailer\n<< /Size " . ($count + 1) . ' /Root 1 0 R /ID [<' . md5((string)$xrefOffset) . '> <' . md5($xrefOffset . '-2') . ">] >>\n";
		$out .= 'startxref' . "\n" . $xrefOffset . "\n%%EOF";

		return $out;
	}//end assemblePdf()

	/**
	 * Escape a value for a PDF literal string `(...)` token (backslash,
	 * parentheses, and control characters).
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private function pdfEscape(string $value): string {
		$escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

		return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $escaped);
	}//end pdfEscape()

	/**
	 * Build the HTML document with header, line table, totals, payment terms, footer.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 * @param array<int,array<string,mixed>> $lines Lines.
	 * @param array<string,mixed> $creditor Creditor.
	 * @param array<string,mixed> $recipient Recipient.
	 *
	 * @return string
	 */
	private function renderHtml(array $invoice, array $lines, array $creditor, array $recipient): string {
		$rows = '';
		foreach ($lines as $line) {
			if (isset($line['rateApplied']['rateCents']) === true) {
				$rateValue = ((int)$line['rateApplied']['rateCents']) / 100;
			} else {
				$rateValue = 0.0;
			}

			$rows .= sprintf(
				'<tr><td>%d</td><td>%s</td><td class="num">%s</td><td class="num">€ %s</td><td class="num">€ %s</td><td class="num">%s%%</td></tr>',
				(int)($line['lineNumber'] ?? 0),
				htmlspecialchars((string)($line['description'] ?? ''), ENT_QUOTES),
				$this->fmt(value: (float)($line['billableUnits'] ?? 0)),
				$this->fmtMoney(value: $rateValue),
				$this->fmtMoney(value: (float)($line['costAmount'] ?? 0)),
				$this->fmt(value: (float)($line['vatRate'] ?? 21))
			);
		}

		$summary = $invoice['summary'] ?? [];
		$breakdown = '';
		foreach (($summary['breakdown'] ?? []) as $group) {
			$breakdown .= sprintf(
				'<tr><td>BTW %s%%</td><td class="num">€ %s</td><td class="num">€ %s</td><td class="num">€ %s</td></tr>',
				$this->fmt(value: (float)$group['rate']),
				$this->fmtMoney(value: (float)$group['net']),
				$this->fmtMoney(value: (float)$group['vat']),
				$this->fmtMoney(value: (float)$group['gross'])
			);
		}

		return sprintf(
			'<!doctype html><html lang="nl"><head><meta charset="utf-8">'
			. '<title>Factuur %s</title>'
			. '<style>body{font-family:Helvetica,Arial,sans-serif;color:#222;font-size:11pt;margin:24px;}'
			. 'h1{font-size:20pt;margin:0 0 12px;}'
			. 'table{border-collapse:collapse;width:100%%;margin:12px 0;}'
			. 'th,td{padding:6px 8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;}'
			. 'th{background:#f4f4f4;}'
			. '.num{text-align:right;}'
			. '.totals{margin-top:24px;}'
			. '.party{display:inline-block;width:48%%;vertical-align:top;}'
			. '.footer{margin-top:32px;color:#666;font-size:9pt;}'
			. '</style></head><body>'
			. '<h1>Factuur %s</h1>'
			. '<div class="party"><strong>%s</strong><br>%s<br>BTW: %s<br>IBAN: %s</div>'
			. '<div class="party"><strong>Klant:</strong> %s<br>BTW: %s</div>'
			. '<p>Factuurdatum: %s &nbsp; Vervaldatum: %s</p>'
			. '<table><thead><tr><th>#</th><th>Omschrijving</th><th class="num">Aantal</th>'
			. '<th class="num">Tarief</th><th class="num">Bedrag</th><th class="num">BTW</th></tr></thead>'
			. '<tbody>%s</tbody></table>'
			. '<table class="totals"><thead><tr><th>BTW-tarief</th><th class="num">Netto</th>'
			. '<th class="num">BTW</th><th class="num">Bruto</th></tr></thead><tbody>%s</tbody></table>'
			. '<p><strong>Totaal netto:</strong> € %s &nbsp; '
			. '<strong>BTW:</strong> € %s &nbsp; '
			. '<strong>Te betalen:</strong> € %s</p>'
			. '<p>Betalingscondities: %s</p>'
			. '<p>%s</p>'
			. '<div class="footer">Gegenereerd op %s — Shillinq invoice-from-time-and-expense</div>'
			. '</body></html>',
			htmlspecialchars((string)($invoice['invoiceNumber'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($invoice['invoiceNumber'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($creditor['legalName'] ?? 'Shillinq Operator'), ENT_QUOTES),
			htmlspecialchars((string)($creditor['address'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($creditor['vatID'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($creditor['iban'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($recipient['legalName'] ?? ($invoice['customerId'] ?? '')), ENT_QUOTES),
			htmlspecialchars((string)($recipient['vatID'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($invoice['invoiceDate'] ?? ''), ENT_QUOTES),
			htmlspecialchars((string)($invoice['dueDate'] ?? ''), ENT_QUOTES),
			$rows,
			$breakdown,
			$this->fmtMoney(value: (float)($invoice['netAmount'] ?? 0)),
			$this->fmtMoney(value: (float)($invoice['vatAmount'] ?? 0)),
			$this->fmtMoney(value: (float)($invoice['grossAmount'] ?? 0)),
			htmlspecialchars((string)($invoice['paymentTerms'] ?? 'net 30'), ENT_QUOTES),
			htmlspecialchars((string)($invoice['notes'] ?? ''), ENT_QUOTES),
			date('Y-m-d H:i')
		);

	}//end renderHtml()

	/**
	 * Format a 2-decimal float with Dutch locale.
	 *
	 * @param float $value Value.
	 *
	 * @return string
	 */
	private function fmtMoney(float $value): string {
		return number_format($value, 2, ',', '.');
	}//end fmtMoney()

	/**
	 * Format a number with up to 2 decimals.
	 *
	 * @param float $value Value.
	 *
	 * @return string
	 */
	private function fmt(float $value): string {
		$s = number_format($value, 2, ',', '');
		return rtrim(rtrim($s, '0'), ',');
	}//end fmt()
}//end class
