<?php

/**
 * The printed labels of an invoice document, in one language.
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
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-speaks-the-customers-language-req-sid-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvoiceDocument;

use OCP\L10N\IFactory;

/**
 * Resolves the invoice template's labels in the recipient's language before
 * the data is handed to the renderer, so the template itself holds no language.
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-speaks-the-customers-language-req-sid-003
 */
final class InvoiceLabels {

	/**
	 * The languages an invoice can be written in.
	 *
	 * @var array<int,string>
	 */
	public const LANGUAGES = ['nl', 'en', 'de', 'fr'];

	/**
	 * Template label key => English source string (the translation key).
	 *
	 * @var array<string,string>
	 */
	private const SOURCES = [
		'invoice' => 'Invoice',
		'invoiceNumber' => 'Invoice number',
		'invoiceDate' => 'Invoice date',
		'dueDate' => 'Due date',
		'customer' => 'Customer',
		'vatNumber' => 'VAT number',
		'iban' => 'IBAN',
		'description' => 'Description',
		'quantity' => 'Quantity',
		'rate' => 'Rate',
		'amount' => 'Amount',
		'vat' => 'VAT',
		'vatRate' => 'VAT rate',
		'net' => 'Net',
		'gross' => 'Gross',
		'totalNet' => 'Total net',
		'total' => 'Total',
		'paymentTerms' => 'Payment terms',
		'scanToPay' => 'Scan to pay',
		'payOnline' => 'Pay online',
	];

	/**
	 * Constructor.
	 *
	 * @param IFactory $l10nFactory Nextcloud's translation factory.
	 */
	public function __construct(
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The document language: the customer's, else the administration's
	 * default, else Dutch. A value outside the four supported languages is
	 * passed over rather than trusted.
	 *
	 * @param string $customerLanguage       CustomerMaster.language.
	 * @param string $administrationLanguage Administration.defaultLanguage.
	 *
	 * @return string One of LANGUAGES.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public static function documentLanguage(string $customerLanguage, string $administrationLanguage = ''): string {
		foreach ([$customerLanguage, $administrationLanguage] as $candidate) {
			$language = strtolower(substr(trim($candidate), 0, 2));
			if (in_array($language, self::LANGUAGES, true) === true) {
				return $language;
			}
		}

		return 'nl';

	}//end documentLanguage()

	/**
	 * Every label, translated into the language.
	 *
	 * @param string $language One of LANGUAGES.
	 *
	 * @return array<string,string> Template label key => printed text.
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function forLanguage(string $language): array {
		$l10n   = $this->l10nFactory->get('shillinq', $language);
		$labels = [];
		foreach (self::SOURCES as $key => $source) {
			$labels[$key] = (string)$l10n->t($source);
		}

		return $labels;

	}//end forLanguage()
}//end class
