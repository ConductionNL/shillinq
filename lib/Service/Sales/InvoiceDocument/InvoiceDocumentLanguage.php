<?php

/**
 * Invoice Document Language
 *
 * The language an invoice document speaks and what that means on paper: its
 * labels in that language and its amounts formatted for that language in the
 * invoice currency (sales-invoice-document REQ-SID-003, REQ-SID-004, design
 * D2 and D4). The template holds no language; the labels are resolved here
 * before the data is handed to the renderer.
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

use NumberFormatter;
use OCA\Shillinq\Kernel\AppIdentity;
use OCP\L10N\IFactory;

/**
 * Labels and amount formatting per document language.
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
 */
class InvoiceDocumentLanguage {

	/**
	 * The languages an invoice can be written in, with the locale its numbers follow.
	 */
	public const LOCALES = [
		'nl' => 'nl_NL',
		'en' => 'en_GB',
		'de' => 'de_DE',
		'fr' => 'fr_FR',
	];

	/**
	 * The language used when the customer names none the invoice supports.
	 */
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * The labels of the invoice document by key, as English source strings.
	 */
	public const LABELS = [
		'title' => 'Invoice',
		'invoiceNumber' => 'Invoice number',
		'invoiceDate' => 'Invoice date',
		'dueDate' => 'Due date',
		'customer' => 'Customer',
		'vatNumber' => 'VAT number',
		'iban' => 'IBAN',
		'description' => 'Description',
		'quantity' => 'Quantity',
		'price' => 'Price',
		'amount' => 'Amount',
		'vat' => 'VAT',
		'vatRate' => 'VAT rate',
		'net' => 'Net',
		'gross' => 'Gross',
		'totalExclVat' => 'Total excluding VAT',
		'total' => 'Total',
		'amountDue' => 'Amount due',
		'paymentTerms' => 'Payment terms',
		'payOnline' => 'Pay online',
		'scanToPay' => 'Scan to pay',
	];

	/**
	 * @param IFactory $l10nFactory Gives the app's translations in a chosen language.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The document language: the customer's when the invoice supports it, else the fallback's, else Dutch.
	 *
	 * @param string $customerLanguage The customer's `language`.
	 * @param string $fallback         The administration's default language.
	 *
	 * @return string One of the keys of LOCALES.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function resolve(string $customerLanguage, string $fallback = self::DEFAULT_LANGUAGE): string {
		foreach ([$customerLanguage, $fallback] as $candidate) {
			$code = strtolower(trim($candidate));
			if (array_key_exists($code, self::LOCALES) === true) {
				return $code;
			}
		}

		return self::DEFAULT_LANGUAGE;
	}//end resolve()

	/**
	 * Every label of the document in a language.
	 *
	 * @param string $language One of the keys of LOCALES.
	 *
	 * @return array<string, string> The label per key.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function labels(string $language): array {
		$l10n = $this->l10nFactory->get(AppIdentity::APP_ID, $this->resolve(customerLanguage: $language));
		$labels = [];
		foreach (self::LABELS as $key => $source) {
			$labels[$key] = (string)$l10n->t($source);
		}

		return $labels;
	}//end labels()

	/**
	 * An amount in the invoice currency, formatted for the language.
	 *
	 * @param float  $amount   The amount.
	 * @param string $currency The ISO 4217 currency of the invoice.
	 * @param string $language One of the keys of LOCALES.
	 *
	 * @return string For example `£4,800.00` (en, GBP) or `€ 24.200,00` (nl, EUR).
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function money(float $amount, string $currency, string $language): string {
		$formatter = new NumberFormatter(self::LOCALES[$this->resolve(customerLanguage: $language)], NumberFormatter::CURRENCY);
		$code = strtoupper(trim($currency));
		if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
			$code = 'EUR';
		}

		return (string)$formatter->formatCurrency($amount, $code);
	}//end money()

	/**
	 * A plain number (a quantity or a rate) formatted for the language, with at most two decimals.
	 *
	 * @param float  $value    The number.
	 * @param string $language One of the keys of LOCALES.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function number(float $value, string $language): string {
		$formatter = new NumberFormatter(self::LOCALES[$this->resolve(customerLanguage: $language)], NumberFormatter::DECIMAL);
		$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

		return (string)$formatter->format($value);
	}//end number()
}//end class
