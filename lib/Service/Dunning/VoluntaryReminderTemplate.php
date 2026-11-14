<?php

/**
 * Voluntary Reminder Template
 *
 * The one reminder a voluntary school contribution may get is its own letter,
 * not the ladder's first stage (decision D28). The generic stage 1 template
 * names a due date and a bank account; the voluntary one repeats that the
 * contribution is voluntary and that the child takes part either way, and
 * names no term, no costs and no interest.
 *
 * The text lives in the docudesk template registry
 * (`lib/Settings/docudesk-templates.json`) next to every other dunning
 * template, in English and Dutch. This class picks the entry for the invoice's
 * language and fills its merge fields, so the DunningRun carries the rendered
 * letter as evidence. It does no I/O beyond reading that file.
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
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Dunning;

use RuntimeException;

/**
 * Picks and renders the voluntary contribution reminder.
 *
 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
 */
final class VoluntaryReminderTemplate {
	/**
	 * The template id without its language suffix.
	 *
	 * @var string
	 */
	public const TEMPLATE_PREFIX = 'tpl-dunning-voluntary-contribution-';

	/**
	 * The language every other value falls back to: the raise's default.
	 *
	 * @var string
	 */
	public const DEFAULT_LANGUAGE = 'nl';

	/**
	 * Construct the reader.
	 *
	 * @param string $templateFile The docudesk template registry to read.
	 */
	public function __construct(
		private readonly string $templateFile = __DIR__ . '/../../Settings/docudesk-templates.json',
	) {
	}//end __construct()

	/**
	 * The template language for an invoice: English for `en` and `en_*`,
	 * Dutch for everything else and for no language at all.
	 *
	 * @param array<string, mixed>|null $invoice The invoice.
	 *
	 * @return string `en` or `nl`.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function languageFor(?array $invoice): string {
		$contribution = ($invoice['contribution'] ?? null);
		$language = '';
		if (is_array($contribution) === true && is_string($contribution['language'] ?? null) === true) {
			$language = strtolower($contribution['language']);
		}

		if ($language === 'en' || str_starts_with($language, 'en_') === true || str_starts_with($language, 'en-') === true) {
			return 'en';
		}

		return self::DEFAULT_LANGUAGE;
	}//end languageFor()

	/**
	 * The template id, rendered subject and rendered body for an invoice.
	 *
	 * @param array<string, mixed> $invoice The voluntary contribution invoice.
	 *
	 * @return array{templateId: string, subject: string, body: string} The rendered reminder.
	 *
	 * @throws RuntimeException When the registry lacks the template.
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function render(array $invoice): array {
		$language = $this->languageFor(invoice: $invoice);
		$templateId = self::TEMPLATE_PREFIX . $language;
		$template = $this->find(templateId: $templateId);

		$values = [
			'{{description}}' => $this->description(invoice: $invoice),
			'{{amount}}' => $this->amount(invoice: $invoice, language: $language),
			'{{invoiceNumber}}' => (string)($invoice['invoiceNumber'] ?? ''),
		];

		return [
			'templateId' => $templateId,
			'subject' => strtr((string)($template['subject'] ?? ''), $values),
			'body' => strtr((string)($template['body'] ?? ''), $values),
		];
	}//end render()

	/**
	 * One template from the registry.
	 *
	 * @param string $templateId The template id.
	 *
	 * @return array<string, mixed> The template.
	 *
	 * @throws RuntimeException When the file is unreadable or has no such template.
	 */
	private function find(string $templateId): array {
		$content = false;
		if (is_file($this->templateFile) === true) {
			$content = file_get_contents($this->templateFile);
		}

		$registry = null;
		if (is_string($content) === true) {
			$registry = json_decode($content, true);
		}

		foreach ((array)($registry['templates'] ?? []) as $template) {
			if (is_array($template) === true && ($template['templateId'] ?? null) === $templateId) {
				return $template;
			}
		}

		throw new RuntimeException(sprintf('The voluntary contribution reminder template %s is missing from the template registry.', $templateId));
	}//end find()

	/**
	 * What the contribution is for: the line text, or the invoice number when
	 * the invoice has no line.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return string The description.
	 */
	private function description(array $invoice): string {
		$lines = ($invoice['invoiceLines'] ?? null);
		if (is_array($lines) === true && is_array($lines[0] ?? null) === true) {
			$name = trim((string)($lines[0]['itemName'] ?? ''));
			if ($name !== '') {
				return $name;
			}
		}

		return (string)($invoice['invoiceNumber'] ?? '');
	}//end description()

	/**
	 * The amount as a parent reads it: `€ 60,00` in Dutch, `€60.00` in English.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param string $language The template language.
	 *
	 * @return string The amount.
	 */
	private function amount(array $invoice, string $language): string {
		$value = (float)($invoice['grossAmount'] ?? 0.0);
		$currency = (string)($invoice['currency'] ?? 'EUR');

		$symbol = $currency . ' ';
		if ($currency === 'EUR') {
			$symbol = '€';
		}

		if ($language === 'en') {
			return $symbol . number_format($value, 2, '.', ',');
		}

		if ($currency === 'EUR') {
			$symbol = '€ ';
		}

		return $symbol . number_format($value, 2, ',', '.');
	}//end amount()
}//end class
