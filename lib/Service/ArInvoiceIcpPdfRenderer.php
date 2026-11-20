<?php

/**
 * AR Invoice ICP PDF Renderer
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tier-3 intra-community supplies (ICP) PDF representation (REQ-ICP-007). Given
 * an ARInvoice that carries `icpContext.treatAsIcp: true`, this service builds
 * the printed/PDF document with the legally required reverse-charge notice
 * ("BTW verlegd" / "VAT reverse-charged"), the buyer VAT-ID, the seller NL
 * VAT-ID and the supply-type indication (goods / services / triangulation),
 * per Article 226 paragraph 11a of VAT Directive 2006/112/EC. The renderer
 * returns the rendering payload (HTML body + filename + ICP overlay metadata)
 * that downstream converters (mPDF / wkhtmltopdf / browser print) wrap into a
 * PDF binary — matching the existing InvoicePdfGenerator (BillableInvoice
 * pattern) so the HTTP surface stays homogeneous.
 *
 * Rendering MUST fail loudly with error code `icp.invoice.vatid.missing` when
 * the buyer VAT-ID is absent on a treatAsIcp invoice (REQ-ICP-007), so the
 * seller never issues a non-compliant intra-community invoice.
 *
 * Per ADR-031 this is the engine-side fallback for the declarative
 * x-openregister-calculations shape that will compose the ICP invoice
 * representation once the OR layout engine ships PDF / UBL composition; until
 * then the rendering is driven imperatively in the app.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use InvalidArgumentException;
use OCA\Shillinq\Kernel\Contracts\ArInvoiceIcpPdfRendererInterface;
use OCP\IL10N;

/**
 * Renders the ICP overlay of an AR invoice as HTML for downstream PDF conversion.
 *
 * The class is intentionally pure: no I/O, no OpenRegister access. Callers fetch
 * the ARInvoice + CustomerMaster from the OpenRegister ObjectService (server-
 * resolved administration scope) and pass the records in. This keeps the rendering
 * deterministic and unit-testable without a live instance.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
class ArInvoiceIcpPdfRenderer implements ArInvoiceIcpPdfRendererInterface {

	/**
	 * Translation provider for the buyer-language label set.
	 *
	 * @param IL10N $l10n L10N provider (server-resolved language).
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Render the ICP overlay PDF payload for an AR invoice (REQ-ICP-007).
	 *
	 * The result mirrors the InvoicePdfGenerator shape (filename / html / mimeType)
	 * and additionally exposes the ICP-overlay metadata so callers can persist or
	 * inspect it. Throws when treatAsIcp is true but the buyer VAT-ID is missing
	 * (`icp.invoice.vatid.missing` per REQ-ICP-007).
	 *
	 * @param array<string,mixed> $invoice The ARInvoice record (read).
	 * @param array<string,mixed> $customer The CustomerMaster record (read).
	 * @param array<string,mixed> $seller The seller administration record (read):
	 *                                    MUST contain {vatId, legalName}.
	 *
	 * @return array{filename:string,html:string,mimeType:string,icp:array<string,mixed>}
	 *
	 * @throws InvalidArgumentException When buyer VAT-ID is missing on a treatAsIcp
	 *                                  invoice (error code icp.invoice.vatid.missing).
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function render(array $invoice, array $customer, array $seller): array {
		$icpContext = $this->extractIcpContext(invoice: $invoice);
		$treatAsIcp = (bool)($icpContext['treatAsIcp'] ?? false);

		$buyerVatId = $this->resolveBuyerVatId(icpContext: $icpContext, customer: $customer);

		if ($treatAsIcp === true && $buyerVatId === '') {
			throw new InvalidArgumentException(
				self::ERROR_VATID_MISSING . ': '
				. 'Cannot render ICP invoice without buyer VAT-ID; correct counterparty record or reclassify as non-ICP.'
			);
		}

		$view = new IcpInvoiceView(
			invoice: $invoice,
			customerName: (string)($customer['name'] ?? ($invoice['customerId'] ?? '')),
			sellerName: (string)($seller['legalName'] ?? ($seller['name'] ?? 'Shillinq Operator')),
			sellerVatId: (string)($seller['vatId'] ?? ($seller['vatNumber'] ?? '')),
			buyerVatId: $buyerVatId,
			treatAsIcp: $treatAsIcp,
			supplyType: (string)($icpContext['supplyType'] ?? ''),
			triangulation: (bool)($icpContext['triangulation'] ?? false)
		);

		return [
			'filename' => sprintf('arinvoice-%s.pdf', (string)($invoice['invoiceNumber'] ?? 'AR-INVOICE')),
			'html' => $this->renderHtml(view: $view),
			'mimeType' => 'application/pdf',
			'icp' => $this->icpMetadata(view: $view, icpContext: $icpContext),
		];

	}//end render()

	/**
	 * The ICP-overlay metadata callers can persist or inspect.
	 *
	 * @param IcpInvoiceView      $view       What the document shows.
	 * @param array<string,mixed> $icpContext The invoice's icpContext.
	 *
	 * @return array<string,mixed>
	 */
	private function icpMetadata(IcpInvoiceView $view, array $icpContext): array {
		return [
			'treatAsIcp' => $view->treatAsIcp,
			'supplyType' => $view->supplyType,
			'buyerVatId' => $view->buyerVatId,
			'sellerVatId' => $view->sellerVatId,
			'reverseChargeKey' => 'shillinq.icp.invoice.reverseChargeNotice',
			'supplyLabelKey' => $this->supplyTypeLabelKey(supplyType: $view->supplyType),
			'viesValidationId' => (string)($icpContext['viesValidationId'] ?? ''),
			'triangulation' => (bool)($icpContext['triangulation'] ?? false),
		];

	}//end icpMetadata()

	/**
	 * Read the icpContext sub-object off the invoice record defensively.
	 *
	 * @param array<string,mixed> $invoice ARInvoice record.
	 *
	 * @return array<string,mixed> The icpContext (possibly empty when not set).
	 */
	private function extractIcpContext(array $invoice): array {
		$context = ($invoice['icpContext'] ?? []);
		if (is_array($context) === true) {
			return $context;
		}

		return [];
	}//end extractIcpContext()

	/**
	 * Resolve the buyer VAT-ID:
	 *  - For a regular ICP invoice, the customer's `vatId` (canonical) is reported.
	 *  - For a triangulation supply (Article 141), the icpContext records the
	 *    C-party VAT-ID inline; the PDF reports the C-party identifier per
	 *    REQ-ICP-006.
	 *  - Falls back to the legacy `btwNumber` only when no `vatId` is set; this
	 *    keeps the renderer working during the canonical-form migration window.
	 *
	 * @param array<string,mixed> $icpContext The icpContext sub-object.
	 * @param array<string,mixed> $customer The CustomerMaster record.
	 *
	 * @return string The buyer VAT-ID (empty string when not resolvable).
	 */
	private function resolveBuyerVatId(array $icpContext, array $customer): string {
		$triangulation = (bool)($icpContext['triangulation'] ?? false);

		if ($triangulation === true) {
			$cPartyVatId = trim((string)($icpContext['cPartyVatId'] ?? ''));
			if ($cPartyVatId !== '') {
				return $cPartyVatId;
			}
		}

		$canonical = trim((string)($customer['vatId'] ?? ''));
		if ($canonical !== '') {
			return $canonical;
		}

		return trim((string)($customer['vatNumber'] ?? ''));
	}//end resolveBuyerVatId()

	/**
	 * Build the HTML body of the ICP overlay (header + reverse-charge notice).
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string Rendered HTML (UTF-8, no external resources).
	 */
	private function renderHtml(IcpInvoiceView $view): string {
		$bodyParts = [
			$this->titleHtml(view: $view),
			$this->sellerHtml(view: $view),
			$this->buyerHtml(view: $view),
			$this->datesHtml(view: $view),
		];

		// ICP overlay (only when treatAsIcp).
		if ($view->treatAsIcp === true) {
			$bodyParts[] = $this->icpOverlayHtml(view: $view);
		}

		// Totals (minimal — line table is the AR-core renderer's job, this overlay
		// focuses on the ICP-mandated fields per REQ-ICP-007).
		$bodyParts[] = $this->totalHtml(view: $view);

		return $this->documentHtml(view: $view, body: implode('', $bodyParts));

	}//end renderHtml()

	/**
	 * The title: "Invoice <number>".
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function titleHtml(IcpInvoiceView $view): string {
		return sprintf(
			'<h1>%s %s</h1>',
			htmlspecialchars($this->l10n->t('Invoice'), ENT_QUOTES),
			htmlspecialchars((string)($view->invoice['invoiceNumber'] ?? ''), ENT_QUOTES)
		);

	}//end titleHtml()

	/**
	 * The seller block with its VAT-ID.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function sellerHtml(IcpInvoiceView $view): string {
		return sprintf(
			'<div class="party"><strong>%s</strong><br>%s<br>%s: %s</div>',
			htmlspecialchars($view->sellerName, ENT_QUOTES),
			htmlspecialchars($this->l10n->t('Seller'), ENT_QUOTES),
			htmlspecialchars($this->l10n->t('Seller VAT-ID'), ENT_QUOTES),
			htmlspecialchars($view->sellerVatId, ENT_QUOTES)
		);

	}//end sellerHtml()

	/**
	 * The buyer block with its VAT-ID.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function buyerHtml(IcpInvoiceView $view): string {
		return sprintf(
			'<div class="party"><strong>%s:</strong> %s<br>%s: %s</div>',
			htmlspecialchars($this->l10n->t('Buyer'), ENT_QUOTES),
			htmlspecialchars($view->customerName, ENT_QUOTES),
			htmlspecialchars($this->l10n->t('Buyer VAT-ID'), ENT_QUOTES),
			htmlspecialchars($view->buyerVatId, ENT_QUOTES)
		);

	}//end buyerHtml()

	/**
	 * The invoice and due dates.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function datesHtml(IcpInvoiceView $view): string {
		return sprintf(
			'<p>%s: %s &nbsp; %s: %s</p>',
			htmlspecialchars($this->l10n->t('Invoice date'), ENT_QUOTES),
			htmlspecialchars((string)($view->invoice['invoiceDate'] ?? ''), ENT_QUOTES),
			htmlspecialchars($this->l10n->t('Due date'), ENT_QUOTES),
			htmlspecialchars((string)($view->invoice['dueDate'] ?? ''), ENT_QUOTES)
		);

	}//end datesHtml()

	/**
	 * The intra-Community overlay: reverse-charge notice, supply type and,
	 * for a triangulation supply, the Article 141 note.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function icpOverlayHtml(IcpInvoiceView $view): string {
		return sprintf(
			'<section class="icp-overlay"><h2>%s</h2>'
			. '<p class="icp-notice"><strong>%s</strong></p>'
			. '<p>%s: <strong>%s</strong></p>'
			. '%s</section>',
			htmlspecialchars($this->l10n->t('Intra-Community Supply'), ENT_QUOTES),
			htmlspecialchars($this->reverseChargeNotice(), ENT_QUOTES),
			htmlspecialchars($this->l10n->t('Supply type'), ENT_QUOTES),
			htmlspecialchars($this->supplyTypeLabel(supplyType: $view->supplyType), ENT_QUOTES),
			$this->triangulationNote(view: $view)
		);

	}//end icpOverlayHtml()

	/**
	 * The Article 141 note of a triangulation supply, or ''.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function triangulationNote(IcpInvoiceView $view): string {
		if ($view->triangulation === false) {
			return '';
		}

		return '<p class="icp-note"><em>' . htmlspecialchars(
			$this->l10n->t('Triangulation supply per Article 141 of VAT Directive 2006/112/EC.'),
			ENT_QUOTES
		) . '</em></p>';

	}//end triangulationNote()

	/**
	 * The total amount line.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 *
	 * @return string
	 */
	private function totalHtml(IcpInvoiceView $view): string {
		return sprintf(
			'<p><strong>%s:</strong> &euro; %s</p>',
			htmlspecialchars($this->l10n->t('Total amount'), ENT_QUOTES),
			$this->formatMoney(value: (float)($view->invoice['totalAmount'] ?? 0))
		);

	}//end totalHtml()

	/**
	 * The complete HTML document around the body.
	 *
	 * @param IcpInvoiceView $view What the document shows.
	 * @param string         $body The rendered body.
	 *
	 * @return string
	 */
	private function documentHtml(IcpInvoiceView $view, string $body): string {
		return sprintf(
			'<!doctype html><html lang="%s"><head><meta charset="utf-8">'
			. '<title>%s</title>'
			. '<style>body{font-family:Helvetica,Arial,sans-serif;color:#222;font-size:11pt;margin:24px;}'
			. 'h1{font-size:20pt;margin:0 0 12px;}'
			. 'h2{font-size:14pt;margin:16px 0 8px;}'
			. '.party{display:inline-block;width:48%%;vertical-align:top;}'
			. '.icp-overlay{border:1px solid #aaa;padding:12px 16px;margin:16px 0;background:#fafafa;}'
			. '.icp-notice{font-size:13pt;}'
			. '.icp-note{font-size:9pt;color:#555;}'
			. '</style></head><body>%s</body></html>',
			htmlspecialchars($this->l10n->getLanguageCode(), ENT_QUOTES),
			htmlspecialchars($this->l10n->t('Invoice'), ENT_QUOTES) . ' '
				. htmlspecialchars((string)($view->invoice['invoiceNumber'] ?? ''), ENT_QUOTES),
			$body
		);

	}//end documentHtml()

	/**
	 * Translate the reverse-charge notice. Source key is English (ADR-005 i18n rule);
	 * the Dutch translation is "BTW verlegd", the English source is "VAT reverse-charged".
	 *
	 * @return string The translated notice (UTF-8).
	 */
	private function reverseChargeNotice(): string {
		// The one notice every reverse-charged output prints (design D1).
		return $this->l10n->t(ReverseChargeNotice::NOTICE);
	}//end reverseChargeNotice()

	/**
	 * Map a supply-type code to its human-readable label (translated).
	 *
	 * @param string $supplyType Supply type code (L / S / T).
	 *
	 * @return string The label (empty when type is unknown).
	 */
	private function supplyTypeLabel(string $supplyType): string {
		return match ($supplyType) {
			'L' => $this->l10n->t('Goods'),
			'S' => $this->l10n->t('Services'),
			'T' => $this->l10n->t('Triangulation'),
			default => '',
		};

	}//end supplyTypeLabel()

	/**
	 * Return the canonical i18n key for the supply-type label (machine consumers).
	 *
	 * @param string $supplyType Supply type code.
	 *
	 * @return string The i18n key (English source string).
	 */
	private function supplyTypeLabelKey(string $supplyType): string {
		return match ($supplyType) {
			'L' => 'Goods',
			'S' => 'Services',
			'T' => 'Triangulation',
			default => '',
		};

	}//end supplyTypeLabelKey()

	/**
	 * Format a money amount with two decimals, Dutch-style separators.
	 *
	 * @param float $value The amount in major currency units.
	 *
	 * @return string Formatted value.
	 */
	private function formatMoney(float $value): string {
		return number_format($value, 2, ',', '.');
	}//end formatMoney()
}//end class
