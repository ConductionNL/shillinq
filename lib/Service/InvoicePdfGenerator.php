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
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Dutch-formatted invoice HTML / PDF builder.
 */
class InvoicePdfGenerator
{
    /**
     * Generate the renderable invoice payload.
     *
     * @param array<string,mixed>            $invoice  BillableInvoice record.
     * @param array<int,array<string,mixed>> $lines    BillableInvoiceLine records.
     * @param array<string,mixed>            $creditor Creditor (issuing party) details.
     * @param array<string,mixed>            $recipient Recipient details.
     *
     * @return array{filename:string,html:string,mimeType:string}
     */
    public function generatePdf(
        array $invoice,
        array $lines,
        array $creditor = [],
        array $recipient = []
    ): array {
        $invoiceNumber = (string) ($invoice['invoiceNumber'] ?? 'INVOICE');
        $html          = $this->renderHtml(invoice: $invoice, lines: $lines, creditor: $creditor, recipient: $recipient);
        $filename      = sprintf('invoice-%s.pdf', $invoiceNumber);

        return [
            'filename' => $filename,
            'html'     => $html,
            'mimeType' => 'application/pdf',
        ];

    }//end generatePdf()

    /**
     * Build the HTML document with header, line table, totals, payment terms, footer.
     *
     * @param array<string,mixed>            $invoice   Invoice.
     * @param array<int,array<string,mixed>> $lines     Lines.
     * @param array<string,mixed>            $creditor  Creditor.
     * @param array<string,mixed>            $recipient Recipient.
     *
     * @return string
     */
    private function renderHtml(array $invoice, array $lines, array $creditor, array $recipient): string
    {
        $rows = '';
        foreach ($lines as $line) {
            $rows .= sprintf(
                '<tr><td>%d</td><td>%s</td><td class="num">%s</td><td class="num">€ %s</td><td class="num">€ %s</td><td class="num">%s%%</td></tr>',
                (int) ($line['lineNumber'] ?? 0),
                htmlspecialchars((string) ($line['description'] ?? ''), ENT_QUOTES),
                $this->fmt((float) ($line['billableUnits'] ?? 0)),
                $this->fmtMoney(isset($line['rateApplied']['rateCents']) ? ((int) $line['rateApplied']['rateCents']) / 100 : 0.0),
                $this->fmtMoney((float) ($line['costAmount'] ?? 0)),
                $this->fmt((float) ($line['vatRate'] ?? 21))
            );
        }

        $summary = $invoice['summary'] ?? [];
        $breakdown = '';
        foreach (($summary['breakdown'] ?? []) as $group) {
            $breakdown .= sprintf(
                '<tr><td>BTW %s%%</td><td class="num">€ %s</td><td class="num">€ %s</td><td class="num">€ %s</td></tr>',
                $this->fmt((float) $group['rate']),
                $this->fmtMoney((float) $group['net']),
                $this->fmtMoney((float) $group['vat']),
                $this->fmtMoney((float) $group['gross'])
            );
        }

        return sprintf(
            '<!doctype html><html lang="nl"><head><meta charset="utf-8">'.
            '<title>Factuur %s</title>'.
            '<style>body{font-family:Helvetica,Arial,sans-serif;color:#222;font-size:11pt;margin:24px;}'.
            'h1{font-size:20pt;margin:0 0 12px;}'.
            'table{border-collapse:collapse;width:100%%;margin:12px 0;}'.
            'th,td{padding:6px 8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top;}'.
            'th{background:#f4f4f4;}'.
            '.num{text-align:right;}'.
            '.totals{margin-top:24px;}'.
            '.party{display:inline-block;width:48%%;vertical-align:top;}'.
            '.footer{margin-top:32px;color:#666;font-size:9pt;}'.
            '</style></head><body>'.
            '<h1>Factuur %s</h1>'.
            '<div class="party"><strong>%s</strong><br>%s<br>BTW: %s<br>IBAN: %s</div>'.
            '<div class="party"><strong>Klant:</strong> %s<br>BTW: %s</div>'.
            '<p>Factuurdatum: %s &nbsp; Vervaldatum: %s</p>'.
            '<table><thead><tr><th>#</th><th>Omschrijving</th><th class="num">Aantal</th>'.
            '<th class="num">Tarief</th><th class="num">Bedrag</th><th class="num">BTW</th></tr></thead>'.
            '<tbody>%s</tbody></table>'.
            '<table class="totals"><thead><tr><th>BTW-tarief</th><th class="num">Netto</th>'.
            '<th class="num">BTW</th><th class="num">Bruto</th></tr></thead><tbody>%s</tbody></table>'.
            '<p><strong>Totaal netto:</strong> € %s &nbsp; '.
            '<strong>BTW:</strong> € %s &nbsp; '.
            '<strong>Te betalen:</strong> € %s</p>'.
            '<p>Betalingscondities: %s</p>'.
            '<p>%s</p>'.
            '<div class="footer">Gegenereerd op %s — Shillinq invoice-from-time-and-expense</div>'.
            '</body></html>',
            htmlspecialchars((string) ($invoice['invoiceNumber'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($invoice['invoiceNumber'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($creditor['legalName'] ?? 'Shillinq Operator'), ENT_QUOTES),
            htmlspecialchars((string) ($creditor['address'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($creditor['vatID'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($creditor['iban'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($recipient['legalName'] ?? ($invoice['customerId'] ?? '')), ENT_QUOTES),
            htmlspecialchars((string) ($recipient['vatID'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($invoice['invoiceDate'] ?? ''), ENT_QUOTES),
            htmlspecialchars((string) ($invoice['dueDate'] ?? ''), ENT_QUOTES),
            $rows,
            $breakdown,
            $this->fmtMoney((float) ($invoice['netAmount'] ?? 0)),
            $this->fmtMoney((float) ($invoice['vatAmount'] ?? 0)),
            $this->fmtMoney((float) ($invoice['grossAmount'] ?? 0)),
            htmlspecialchars((string) ($invoice['paymentTerms'] ?? 'net 30'), ENT_QUOTES),
            htmlspecialchars((string) ($invoice['notes'] ?? ''), ENT_QUOTES),
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
    private function fmtMoney(float $value): string
    {
        return number_format($value, 2, ',', '.');

    }//end fmtMoney()

    /**
     * Format a number with up to 2 decimals.
     *
     * @param float $value Value.
     *
     * @return string
     */
    private function fmt(float $value): string
    {
        $s = number_format($value, 2, ',', '');
        return rtrim(rtrim($s, '0'), ',');

    }//end fmt()

}//end class
