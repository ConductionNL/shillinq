<?php

/**
 * Assembles the data the sales-invoice template renders.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-is-rendered-through-docudesk-req-sid-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvoiceDocument;

/**
 * Turns an invoice, its lines, both parties and the administration's layout
 * into the template's data: labels in the document language, every amount
 * formatted in the invoice currency, the layout, and the QR payloads. The
 * template only places what it is given.
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-is-rendered-through-docudesk-req-sid-002
 */
final class InvoiceDocumentData {

	/**
	 * The layout an administration without its own record gets (design.md
	 * Migration Plan: classic, no logo).
	 *
	 * @var array<string,mixed>
	 */
	public const DEFAULT_LAYOUT = [
		'variant' => 'classic',
		'primaryColor' => '#1A2A6C',
		'accentColor' => '#6B7280',
		'logoFileId' => null,
		'footerText' => '',
		'showQr' => true,
	];

	/**
	 * The layout variants the template knows.
	 *
	 * @var array<int,string>
	 */
	private const VARIANTS = ['classic', 'modern', 'compact'];

	/**
	 * Constructor.
	 *
	 * @param InvoiceLabels          $labels    Labels per language.
	 * @param InvoiceAmountFormatter $formatter Amount formatting.
	 */
	public function __construct(
		private readonly InvoiceLabels $labels,
		private readonly InvoiceAmountFormatter $formatter,
	) {
	}//end __construct()

	/**
	 * The template data.
	 *
	 * @param array<string,mixed>            $invoice        The invoice.
	 * @param array<int,array<string,mixed>> $lines          Its lines, in either shape the generator accepts.
	 * @param array<string,mixed>            $creditor       The issuing party (legalName, address, vatID, iban, bic).
	 * @param array<string,mixed>            $recipient      The customer (legalName, vatId, language).
	 * @param array<string,mixed>            $layout         The administration's InvoiceLayout, or [].
	 * @param string                         $language       The document language.
	 * @param string|null                    $payLink        The invoice's payment link, when it has one.
	 * @param string|null                    $logoDataUri    The logo as a data URI, when there is one.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.2
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
	 */
	public function assemble(
		array $invoice,
		array $lines,
		array $creditor,
		array $recipient,
		array $layout,
		string $language,
		?string $payLink = null,
		?string $logoDataUri = null,
	): array {
		$currency = $this->currency(invoice: $invoice);
		$layout   = $this->layout(layout: $layout);

		return [
			'language' => $language,
			'currency' => $currency,
			'labels' => $this->labels->forLanguage(language: $language),
			'layout' => $layout + ['logo' => $logoDataUri],
			'invoice' => [
				'number' => (string)($invoice['invoiceNumber'] ?? ''),
				'date' => (string)($invoice['invoiceDate'] ?? ''),
				'dueDate' => (string)($invoice['dueDate'] ?? ''),
				'paymentTerms' => (string)($invoice['paymentTerms'] ?? ''),
				'notes' => (string)($invoice['notes'] ?? ''),
				'net' => $this->money(value: $invoice['netAmount'] ?? 0, currency: $currency, language: $language),
				'vat' => $this->money(value: $invoice['vatAmount'] ?? 0, currency: $currency, language: $language),
				'gross' => $this->money(value: $invoice['grossAmount'] ?? 0, currency: $currency, language: $language),
				'open' => $this->money(value: $this->openAmount(invoice: $invoice), currency: $currency, language: $language),
			],
			'lines' => $this->lines(lines: $lines, currency: $currency, language: $language),
			'vatBreakdown' => $this->breakdown(invoice: $invoice, currency: $currency, language: $language),
			'creditor' => [
				'name' => (string)($creditor['legalName'] ?? ''),
				'address' => (string)($creditor['address'] ?? ''),
				'vatNumber' => (string)($creditor['vatID'] ?? ($creditor['vatId'] ?? '')),
				'iban' => (string)($creditor['iban'] ?? ''),
			],
			'recipient' => [
				'name' => (string)($recipient['legalName'] ?? ($invoice['customerId'] ?? '')),
				'address' => (string)($recipient['address'] ?? ''),
				'vatNumber' => (string)($recipient['vatId'] ?? ($recipient['vatID'] ?? '')),
			],
			'qr' => $this->qr(invoice: $invoice, creditor: $creditor, layout: $layout, currency: $currency, payLink: $payLink),
		];

	}//end assemble()

	/**
	 * The invoice currency, EUR when it names none.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return string
	 */
	private function currency(array $invoice): string {
		$currency = strtoupper(trim((string)($invoice['currency'] ?? '')));

		if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
			return 'EUR';
		}

		return $currency;

	}//end currency()

	/**
	 * The layout with every field present; an unknown variant becomes classic.
	 *
	 * @param array<string,mixed> $layout The stored layout, or [].
	 *
	 * @return array<string,mixed>
	 */
	private function layout(array $layout): array {
		$merged = array_intersect_key($layout, self::DEFAULT_LAYOUT) + self::DEFAULT_LAYOUT;
		if (in_array($merged['variant'], self::VARIANTS, true) === false) {
			$merged['variant'] = 'classic';
		}

		$merged['showQr'] = $merged['showQr'] !== false;

		return $merged;

	}//end layout()

	/**
	 * What is still owed: the open amount when the invoice carries one, else the gross.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return float
	 */
	private function openAmount(array $invoice): float {
		foreach (['openAmount', 'outstandingAmount'] as $key) {
			if (isset($invoice[$key]) === true && is_numeric($invoice[$key]) === true) {
				return (float)$invoice[$key];
			}
		}

		return (float)($invoice['grossAmount'] ?? 0);

	}//end openAmount()

	/**
	 * Format a raw value as money.
	 *
	 * @param mixed  $value    The raw value.
	 * @param string $currency The currency.
	 * @param string $language The language.
	 *
	 * @return string
	 */
	private function money(mixed $value, string $currency, string $language): string {
		$amount = 0.0;
		if (is_numeric($value) === true) {
			$amount = (float)$value;
		}

		return $this->formatter->money(amount: $amount, currency: $currency, language: $language);

	}//end money()

	/**
	 * The printed lines. A BillableInvoiceLine (lineNumber, billableUnits,
	 * rateApplied.rateCents, costAmount) and an ARInvoice line (lineId,
	 * itemName, quantity, netPrice, netAmount) are both read.
	 *
	 * @param array<int,array<string,mixed>> $lines    The lines.
	 * @param string                         $currency The currency.
	 * @param string                         $language The language.
	 *
	 * @return array<int,array<string,string|int>>
	 */
	private function lines(array $lines, string $currency, string $language): array {
		$printed = [];
		foreach ($lines as $line) {
			$line  = (array)$line;
			$price = (float)($line['netPrice'] ?? 0);
			if (isset($line['rateApplied']['rateCents']) === true) {
				$price = ((int)$line['rateApplied']['rateCents']) / 100;
			}

			$printed[] = [
				'number' => (int)($line['lineNumber'] ?? ($line['lineId'] ?? 0)),
				'description' => (string)($line['description'] ?? ($line['itemName'] ?? '')),
				'quantity' => $this->formatter->number(value: (float)($line['billableUnits'] ?? ($line['quantity'] ?? 0)), language: $language),
				'rate' => $this->formatter->money(amount: $price, currency: $currency, language: $language),
				'amount' => $this->money(value: $line['costAmount'] ?? ($line['netAmount'] ?? 0), currency: $currency, language: $language),
				'vatRate' => $this->formatter->number(value: (float)($line['vatRate'] ?? 21), language: $language).'%',
			];
		}

		return $printed;

	}//end lines()

	/**
	 * The VAT breakdown from the invoice summary.
	 *
	 * @param array<string,mixed> $invoice  The invoice.
	 * @param string              $currency The currency.
	 * @param string              $language The language.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function breakdown(array $invoice, string $currency, string $language): array {
		$rows = [];
		foreach ((array)($invoice['summary']['breakdown'] ?? []) as $group) {
			$group  = (array)$group;
			$rows[] = [
				'rate' => $this->formatter->number(value: (float)($group['rate'] ?? 0), language: $language).'%',
				'net' => $this->money(value: $group['net'] ?? 0, currency: $currency, language: $language),
				'vat' => $this->money(value: $group['vat'] ?? 0, currency: $currency, language: $language),
				'gross' => $this->money(value: $group['gross'] ?? 0, currency: $currency, language: $language),
			];
		}

		return $rows;

	}//end breakdown()

	/**
	 * The QR payloads: none when the layout hides them; the EPC transfer for
	 * a EUR invoice with an IBAN; the payment link when there is one.
	 *
	 * @param array<string,mixed> $invoice  The invoice.
	 * @param array<string,mixed> $creditor The issuing party.
	 * @param array<string,mixed> $layout   The resolved layout.
	 * @param string              $currency The currency.
	 * @param string|null         $payLink  The payment link.
	 *
	 * @return array{epc: string|null, payLink: string|null}
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
	 */
	private function qr(array $invoice, array $creditor, array $layout, string $currency, ?string $payLink): array {
		if ($layout['showQr'] === false) {
			return ['epc' => null, 'payLink' => null];
		}

		$link = trim((string)$payLink);
		if (preg_match('#^https://#i', $link) !== 1) {
			$link = null;
		}

		return [
			'epc' => EpcQrPayload::build(
				name: (string)($creditor['legalName'] ?? ''),
				iban: (string)($creditor['iban'] ?? ''),
				amount: $this->openAmount(invoice: $invoice),
				currency: $currency,
				reference: (string)($invoice['invoiceNumber'] ?? ''),
				bic: (string)($creditor['bic'] ?? '')
			),
			'payLink' => $link,
		];

	}//end qr()
}//end class
