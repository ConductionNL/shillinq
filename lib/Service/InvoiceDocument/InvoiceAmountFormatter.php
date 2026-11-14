<?php

/**
 * Formats invoice amounts for a language and a currency.
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
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-shows-its-own-currency-req-sid-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\InvoiceDocument;

use NumberFormatter;

/**
 * Money and plain numbers in the document language, through ICU's
 * NumberFormatter, so a pound invoice in English reads "£4,800.00" and a euro
 * invoice in Dutch "€ 24.200,00".
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-invoice-shows-its-own-currency-req-sid-004
 */
final class InvoiceAmountFormatter {

	/**
	 * Document language => ICU locale.
	 *
	 * @var array<string,string>
	 */
	private const LOCALES = [
		'nl' => 'nl_NL',
		'en' => 'en_GB',
		'de' => 'de_DE',
		'fr' => 'fr_FR',
	];

	/**
	 * The ICU locale of a document language, Dutch for an unknown one.
	 *
	 * @param string $language Document language.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public static function locale(string $language): string {
		return self::LOCALES[$language] ?? self::LOCALES['nl'];

	}//end locale()

	/**
	 * An amount with its currency sign, in the language's formatting.
	 *
	 * @param float  $amount   The amount in currency units.
	 * @param string $currency ISO 4217 code; empty means EUR.
	 * @param string $language Document language.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function money(float $amount, string $currency, string $language): string {
		$code      = strtoupper(trim($currency));
		if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
			$code = 'EUR';
		}

		$formatter = new NumberFormatter(self::locale(language: $language), NumberFormatter::CURRENCY);
		$formatted = $formatter->formatCurrency($amount, $code);
		if ($formatted === false) {
			return $code.' '.number_format($amount, 2, '.', '');
		}

		return $formatted;

	}//end money()

	/**
	 * A plain number (a quantity or a VAT rate) with at most two decimals.
	 *
	 * @param float  $value    The number.
	 * @param string $language Document language.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
	 */
	public function number(float $value, string $language): string {
		$formatter = new NumberFormatter(self::locale(language: $language), NumberFormatter::DECIMAL);
		$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
		$formatted = $formatter->format($value);

		if ($formatted === false) {
			return (string)$value;
		}

		return $formatted;

	}//end number()
}//end class
