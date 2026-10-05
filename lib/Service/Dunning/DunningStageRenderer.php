<?php

/**
 * Renders a dunning stage's subject and body for one invoice.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use DateTimeImmutable;

/**
 * Fills a ladder stage's text with DunningTemplateRegistry's merge fields
 * (REQ-RAD-003, design D5).
 *
 * The text is the stage's `subject` and `body` in the customer's language,
 * else in the other language, else DunningStageDefaultTexts for the stage.
 * The renderer does no I/O: the caller hands it the invoice, the customer and
 * the values it worked out (bank account, collection costs, interest).
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
 */
final class DunningStageRenderer {

	/**
	 * Buyer countries whose customers get Dutch texts.
	 *
	 * @var array<int, string>
	 */
	private const DUTCH_COUNTRIES = ['NL', 'BE', 'SR', 'AW', 'CW', 'SX', 'BQ'];

	/**
	 * The payment term in days a text names when the caller gives none: the
	 * statutory minimum of the 14-day letter (art. 6:96 lid 6 BW).
	 *
	 * @var int
	 */
	private const DEFAULT_PAYMENT_TERM_DAYS = 14;

	/**
	 * Construct the renderer.
	 *
	 * @param DunningTemplateRegistry       $registry The merge fields.
	 * @param DunningStageDefaultTexts|null $defaults The text of a stage without one.
	 */
	public function __construct(
		private readonly DunningTemplateRegistry $registry,
		private readonly ?DunningStageDefaultTexts $defaults = null,
	) {
	}//end __construct()

	/**
	 * The subject and body of a stage for one invoice, in the customer's language.
	 *
	 * @param array<string, mixed> $stage    The ladder stage (nr, subject, body).
	 * @param array<string, mixed> $invoice  The ARInvoice.
	 * @param array<string, mixed> $customer The CustomerMaster, or [] when unknown.
	 * @param array<string, mixed> $values   Values the caller worked out: iban,
	 *                                       incassokosten, rente, betalingstermijn.
	 *
	 * @return array{language: string, subject: string, body: string} The rendered text.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
	 */
	public function render(array $stage, array $invoice, array $customer = [], array $values = []): array {
		$language = $this->languageFor(invoice: $invoice, customer: $customer);
		$text     = $this->textFor(stage: $stage, language: $language);
		$fields   = $this->fields(invoice: $invoice, customer: $customer, values: $values, language: $language);

		return [
			'language' => $language,
			'subject' => strtr($text['subject'], $fields),
			'body' => strtr($text['body'], $fields),
		];
	}//end render()

	/**
	 * The customer's language: an explicit `language` on the customer or the
	 * invoice first, else the buyer's country (Dutch-speaking countries get
	 * Dutch, every other country English), else Dutch.
	 *
	 * @param array<string, mixed> $invoice  The invoice.
	 * @param array<string, mixed> $customer The customer.
	 *
	 * @return string `nl` or `en`.
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-2.1
	 */
	public function languageFor(array $invoice, array $customer = []): string {
		$explicit = strtolower((string)($customer['language'] ?? ($invoice['language'] ?? '')));
		if ($explicit !== '') {
			return $this->baseLanguage(value: $explicit);
		}

		$country = strtoupper((string)($invoice['buyerCountryCode'] ?? ($customer['countryCode'] ?? '')));
		if ($country === '' || in_array($country, self::DUTCH_COUNTRIES, true) === true) {
			return 'nl';
		}

		return 'en';
	}//end languageFor()

	/**
	 * `en`, `en_GB` and `en-US` are English; everything else is Dutch.
	 *
	 * @param string $value A lower-cased language or locale.
	 *
	 * @return string `nl` or `en`.
	 */
	private function baseLanguage(string $value): string {
		if ($value === 'en' || str_starts_with($value, 'en_') === true || str_starts_with($value, 'en-') === true) {
			return 'en';
		}

		return 'nl';
	}//end baseLanguage()

	/**
	 * The unfilled text of a stage in a language: the stage's own text, then
	 * its other language, then the default text of that stage. Also what the
	 * ladder page shows (task 4.1), so the page and the letter agree.
	 *
	 * @param array<string, mixed> $stage    The stage.
	 * @param string               $language `nl` or `en`.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
	 */
	public function textFor(array $stage, string $language): array {
		$other = 'en';
		if ($language === 'en') {
			$other = 'nl';
		}

		foreach ([$language, $other] as $candidate) {
			$subject = $this->localised(value: ($stage['subject'] ?? null), language: $candidate);
			$body    = $this->localised(value: ($stage['body'] ?? null), language: $candidate);
			if ($subject !== '' && $body !== '') {
				return ['subject' => $subject, 'body' => $body];
			}
		}

		$defaults = ($this->defaults ?? new DunningStageDefaultTexts());
		return $defaults->for(stageNr: (int)($stage['nr'] ?? 1), language: $language);
	}//end textFor()

	/**
	 * One language of a `{nl, en}` text, '' when absent.
	 *
	 * @param mixed  $value    The stage's subject or body.
	 * @param string $language The language.
	 *
	 * @return string
	 */
	private function localised(mixed $value, string $language): string {
		if (is_array($value) === false || is_string($value[$language] ?? null) === false) {
			return '';
		}

		return trim($value[$language]);
	}//end localised()

	/**
	 * The registry's merge fields with their values, as `{name}` => value.
	 * A merge field without a value renders empty, never as its placeholder.
	 *
	 * @param array<string, mixed> $invoice  The invoice.
	 * @param array<string, mixed> $customer The customer.
	 * @param array<string, mixed> $values   The caller's values.
	 * @param string               $language `nl` or `en`.
	 *
	 * @return array<string, string>
	 */
	private function fields(array $invoice, array $customer, array $values, string $language): array {
		$currency = (string)($invoice['currency'] ?? 'EUR');
		$known    = [
			'klantNaam' => (string)($customer['legalName'] ?? ($customer['tradeName'] ?? ($invoice['buyerName'] ?? ''))),
			'factuurNummer' => (string)($invoice['invoiceNumber'] ?? ''),
			'invoiceDate' => $this->date(value: ($invoice['invoiceDate'] ?? null), language: $language),
			'outstandingAmount' => $this->amount(value: $this->outstanding(invoice: $invoice), currency: $currency, language: $language),
			'expiryDate' => $this->date(value: ($invoice['dueDate'] ?? null), language: $language),
			'iban' => (string)($values['iban'] ?? ($invoice['paymentAccountId'] ?? '')),
			'betalingstermijn' => (string)($values['betalingstermijn'] ?? self::DEFAULT_PAYMENT_TERM_DAYS),
			'incassokosten' => $this->amount(value: ($values['incassokosten'] ?? null), currency: $currency, language: $language),
			'rente' => $this->amount(value: ($values['rente'] ?? null), currency: $currency, language: $language),
		];

		$fields = [];
		foreach ($this->registry->mergeFields() as $name) {
			$fields['{' . $name . '}'] = ($known[$name] ?? '');
		}

		return $fields;
	}//end fields()

	/**
	 * What is still due: `amountDue` when the invoice has it, else the gross
	 * amount less what was paid.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return float
	 */
	private function outstanding(array $invoice): float {
		if (is_numeric($invoice['amountDue'] ?? null) === true) {
			return (float)$invoice['amountDue'];
		}

		return ((float)($invoice['grossAmount'] ?? 0.0) - (float)($invoice['paidAmount'] ?? 0.0));
	}//end outstanding()

	/**
	 * A date as the customer reads it: `12-03-2026` in Dutch, `12 March 2026`
	 * in English. An unreadable date renders as given.
	 *
	 * @param mixed  $value    An ISO date.
	 * @param string $language `nl` or `en`.
	 *
	 * @return string
	 */
	private function date(mixed $value, string $language): string {
		$raw = (string)($value ?? '');
		if ($raw === '') {
			return '';
		}

		try {
			$date = new DateTimeImmutable($raw);
		} catch (\Exception $e) {
			return $raw;
		}

		if ($language === 'en') {
			return $date->format('j F Y');
		}

		return $date->format('d-m-Y');
	}//end date()

	/**
	 * An amount as the customer reads it: `€ 1.210,00` in Dutch, `€1,210.00`
	 * in English. No value renders empty.
	 *
	 * @param mixed  $value    The amount.
	 * @param string $currency The currency code.
	 * @param string $language `nl` or `en`.
	 *
	 * @return string
	 */
	private function amount(mixed $value, string $currency, string $language): string {
		if (is_numeric($value) === false) {
			return '';
		}

		$symbol = $currency . ' ';
		if ($currency === 'EUR' && $language === 'en') {
			$symbol = '€';
		} else if ($currency === 'EUR') {
			$symbol = '€ ';
		}

		if ($language === 'en') {
			return $symbol . number_format((float)$value, 2, '.', ',');
		}

		return $symbol . number_format((float)$value, 2, ',', '.');
	}//end amount()
}//end class
