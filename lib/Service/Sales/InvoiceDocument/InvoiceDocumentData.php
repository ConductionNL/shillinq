<?php

/**
 * Invoice Document Data
 *
 * Assembles what the `sales-invoice` template receives (sales-invoice-document
 * design D2): the invoice, its lines, the creditor and the recipient, with
 * every label already in the recipient's language, every amount formatted for
 * that language in the invoice currency (REQ-SID-003, REQ-SID-004), and the
 * scan-to-pay payloads when the layout shows QR codes (REQ-SID-005).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sales\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Service\Payment\PayLinkService;

/**
 * The template data of one invoice document.
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
 */
class InvoiceDocumentData {

	/**
	 * @param InvoiceDocumentLanguage $language Labels and formatting per language.
	 * @param EpcQrPayload            $epc      The SEPA transfer QR payload.
	 * @param PayLinkService|null     $payLinks The pay link of an open invoice; without it none.
	 */
	public function __construct(
		private readonly InvoiceDocumentLanguage $language,
		private readonly EpcQrPayload $epc,
		private readonly ?PayLinkService $payLinks = null,
	) {
	}//end __construct()

	/**
	 * The template data of an invoice.
	 *
	 * @param array<string, mixed>             $invoice   The ARInvoice.
	 * @param array<int, array<string, mixed>> $lines     Its lines.
	 * @param array<string, mixed>             $parties   `creditor`, `recipient` and `layout` (the InvoiceLayout, may be empty).
	 * @param string                           $fallback  The administration's default language.
	 *
	 * @return array<string, mixed> language, currency, labels, invoice, creditor, recipient, lines, totals, qr, layout.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function assemble(array $invoice, array $lines, array $parties, string $fallback = InvoiceDocumentLanguage::DEFAULT_LANGUAGE): array {
		$creditor = (array)($parties['creditor'] ?? []);
		$recipient = (array)($parties['recipient'] ?? []);
		$layout = (array)($parties['layout'] ?? []);
		$language = $this->language->resolve(
			customerLanguage: (string)($invoice['documentLanguage'] ?? ($recipient['language'] ?? '')),
			fallback: $fallback
		);
		$currency = self::currencyOf(invoice: $invoice);

		return [
			'language' => $language,
			'currency' => $currency,
			'labels' => $this->language->labels(language: $language),
			'invoice' => [
				'number' => (string)($invoice['invoiceNumber'] ?? ''),
				'invoiceDate' => (string)($invoice['invoiceDate'] ?? ''),
				'dueDate' => (string)($invoice['dueDate'] ?? ''),
				'paymentTerms' => (string)($invoice['paymentTerms'] ?? ''),
				'notes' => (string)($invoice['notes'] ?? ''),
			],
			'creditor' => $creditor,
			'recipient' => $recipient,
			'lines' => $this->lines(lines: $lines, currency: $currency, language: $language),
			'totals' => $this->totals(invoice: $invoice, currency: $currency, language: $language),
			'qr' => $this->scanToPay(invoice: $invoice, creditor: $creditor, layout: $layout, currency: $currency),
			'layout' => $layout,
		];
	}//end assemble()

	/**
	 * The invoice currency, EUR when it names none.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public static function currencyOf(array $invoice): string {
		$currency = strtoupper(trim((string)($invoice['currency'] ?? '')));
		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			return 'EUR';
		}

		return $currency;
	}//end currencyOf()

	/**
	 * The amount still to pay: `amountDue` when set, else gross minus paid.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
	 */
	public static function openAmount(array $invoice): float {
		if (is_numeric($invoice['amountDue'] ?? null) === true) {
			return round((float)$invoice['amountDue'], 2);
		}

		return round((float)($invoice['grossAmount'] ?? 0) - (float)($invoice['paidAmount'] ?? 0), 2);
	}//end openAmount()

	/**
	 * The lines with their numbers formatted.
	 *
	 * @param array<int, array<string, mixed>> $lines    The lines.
	 * @param string                           $currency The invoice currency.
	 * @param string                           $language The document language.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function lines(array $lines, string $currency, string $language): array {
		$out = [];
		foreach (array_values($lines) as $index => $line) {
			$quantity = (float)($line['quantity'] ?? 1);
			$price = (float)($line['unitPrice'] ?? ($line['price'] ?? 0));
			$amount = (float)($line['lineAmount'] ?? ($line['amount'] ?? ($quantity * $price)));
			$out[] = [
				'number' => (string)($index + 1),
				'description' => (string)($line['description'] ?? ''),
				'quantity' => $this->language->number(value: $quantity, language: $language),
				'price' => $this->language->money(amount: $price, currency: $currency, language: $language),
				'amount' => $this->language->money(amount: $amount, currency: $currency, language: $language),
				'vatRate' => $this->language->number(value: (float)($line['vatRate'] ?? 0), language: $language) . '%',
			];
		}

		return $out;
	}//end lines()

	/**
	 * The totals, formatted.
	 *
	 * @param array<string, mixed> $invoice  The invoice.
	 * @param string               $currency The invoice currency.
	 * @param string               $language The document language.
	 *
	 * @return array<string, string> net, vat, gross and amountDue.
	 */
	private function totals(array $invoice, string $currency, string $language): array {
		$amounts = [
			'net' => (float)($invoice['netAmount'] ?? 0),
			'vat' => (float)($invoice['vatAmount'] ?? 0),
			'gross' => (float)($invoice['grossAmount'] ?? 0),
			'amountDue' => self::openAmount(invoice: $invoice),
		];

		return array_map(
			fn (float $amount): string => $this->language->money(amount: $amount, currency: $currency, language: $language),
			$amounts
		);
	}//end totals()

	/**
	 * The scan-to-pay payloads: the SEPA transfer and the pay link, each null when absent.
	 *
	 * @param array<string, mixed> $invoice  The invoice.
	 * @param array<string, mixed> $creditor The creditor, with `legalName`, `iban` and `bic`.
	 * @param array<string, mixed> $layout   The layout; `showQr: false` turns both off.
	 * @param string               $currency The invoice currency.
	 *
	 * @return array{epc: string|null, payLink: string|null}
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
	 */
	private function scanToPay(array $invoice, array $creditor, array $layout, string $currency): array {
		if (($layout['showQr'] ?? true) === false) {
			return ['epc' => null, 'payLink' => null];
		}

		$payLink = null;
		if ($this->payLinks !== null) {
			$payLink = $this->payLinks->forOpenInvoice(invoice: $invoice);
		}

		return [
			'epc' => $this->epc->forTransfer(
				creditor: $creditor,
				currency: $currency,
				amount: self::openAmount(invoice: $invoice),
				reference: (string)($invoice['invoiceNumber'] ?? '')
			),
			'payLink' => $payLink,
		];
	}//end scanToPay()
}//end class
