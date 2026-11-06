<?php

/**
 * Unit tests for VoluntaryReminderTemplate.
 *
 * Reads the real docudesk template registry, so a template that starts naming
 * costs, interest, a term or a bank account fails here before a parent reads it.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
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

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use OCA\Shillinq\Service\Dunning\VoluntaryReminderTemplate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The voluntary reminder is friendly, in the guardian's language, and asks for
 * nothing a voluntary contribution may not ask for.
 */
final class VoluntaryReminderTemplateTest extends TestCase {
	/**
	 * Words that name a term, a deadline, costs, interest or a bank account.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FORBIDDEN = [
		'nl' => ['IBAN', 'incasso', 'rente', 'kosten', 'binnen', 'dagen', 'vervaldatum', 'termijn', 'uiterlijk', 'aanmaning'],
		'en' => ['IBAN', 'interest', 'cost', 'within', 'days', 'due', 'deadline', 'fee', 'collection'],
	];

	/**
	 * A voluntary contribution invoice in the given language.
	 *
	 * @param string|null $language The contribution language, or null for none.
	 *
	 * @return array<string, mixed> The invoice.
	 */
	private function invoice(?string $language): array {
		$contribution = ['kind' => 'parental-contribution', 'voluntary' => true];
		if ($language !== null) {
			$contribution['language'] = $language;
		}

		return [
			'id' => 'inv-vol',
			'invoiceNumber' => 'CTB-2026-DEMO0001-0001',
			'grossAmount' => 60.0,
			'currency' => 'EUR',
			'invoiceLines' => [['lineId' => '1', 'itemName' => 'Ouderbijdrage 2026-2027 (vrijwillig)']],
			'contribution' => $contribution,
		];
	}//end invoice()

	/**
	 * The notice the invoice itself carries, from the catalogue.
	 *
	 * @param string $language The catalogue language.
	 *
	 * @return string The notice.
	 */
	private function notice(string $language): string {
		$source = 'This contribution is voluntary. Your child takes part whether you pay or not.';
		if ($language === 'en') {
			return $source;
		}

		$catalogue = json_decode((string)file_get_contents(__DIR__ . '/../../../../l10n/nl.json'), true);

		return (string)$catalogue['translations'][$source];
	}//end notice()

	/**
	 * The Dutch reminder repeats the invoice's voluntary notice, names the
	 * contribution and the amount, and names no term, costs, interest or bank
	 * account (REQ-SCON-011).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function testTheDutchReminderIsVoluntaryAndNamesNoTermsCostsOrInterest(): void {
		$rendered = (new VoluntaryReminderTemplate())->render(invoice: $this->invoice(language: 'nl'));

		self::assertSame('tpl-dunning-voluntary-contribution-nl', $rendered['templateId']);
		self::assertStringContainsString($this->notice(language: 'nl'), $rendered['body']);
		self::assertStringContainsString('Ouderbijdrage 2026-2027 (vrijwillig)', $rendered['body']);
		self::assertStringContainsString('60,00', $rendered['body']);
		self::assertStringContainsString('CTB-2026-DEMO0001-0001', $rendered['body']);
		self::assertStringContainsString('Ik betaal niet', $rendered['body']);
		self::assertStringNotContainsString('{{', $rendered['subject'] . $rendered['body']);
		self::assertNotSame('', trim($rendered['subject']));
		foreach (self::FORBIDDEN['nl'] as $word) {
			self::assertStringNotContainsStringIgnoringCase($word, $rendered['subject'] . ' ' . $rendered['body'], 'the Dutch reminder names ' . $word);
		}
	}//end testTheDutchReminderIsVoluntaryAndNamesNoTermsCostsOrInterest()

	/**
	 * An English guardian gets the English reminder with the same promise; a
	 * language without a template and a missing language get Dutch, the
	 * raise's default (REQ-SCON-011).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function testTheLanguagePicksTheTemplateAndFallsBackToDutch(): void {
		$template = new VoluntaryReminderTemplate();

		$english = $template->render(invoice: $this->invoice(language: 'en_GB'));
		self::assertSame('tpl-dunning-voluntary-contribution-en', $english['templateId']);
		self::assertStringContainsString($this->notice(language: 'en'), $english['body']);
		self::assertStringContainsString('60.00', $english['body']);
		self::assertStringContainsString('I will not pay', $english['body']);
		foreach (self::FORBIDDEN['en'] as $word) {
			self::assertStringNotContainsStringIgnoringCase($word, $english['subject'] . ' ' . $english['body'], 'the English reminder names ' . $word);
		}

		self::assertSame('tpl-dunning-voluntary-contribution-nl', $template->render(invoice: $this->invoice(language: 'de'))['templateId']);
		self::assertSame('tpl-dunning-voluntary-contribution-nl', $template->render(invoice: $this->invoice(language: null))['templateId']);
		self::assertSame('en', $template->languageFor(invoice: $this->invoice(language: 'en')));
		self::assertSame('nl', $template->languageFor(invoice: null));
	}//end testTheLanguagePicksTheTemplateAndFallsBackToDutch()

	/**
	 * Without a line the invoice number stands in for the description, so no
	 * merge field is left open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function testAnInvoiceWithoutLinesStillRendersEveryField(): void {
		$invoice = $this->invoice(language: 'nl');
		unset($invoice['invoiceLines']);

		$rendered = (new VoluntaryReminderTemplate())->render(invoice: $invoice);

		self::assertStringNotContainsString('{{', $rendered['body']);
		self::assertStringContainsString('CTB-2026-DEMO0001-0001', $rendered['body']);
	}//end testAnInvoiceWithoutLinesStillRendersEveryField()

	/**
	 * A registry without the template is a deployment fault, reported as one
	 * rather than sending an empty reminder.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/voluntary-contribution-reminder/specs/school-contributions/spec.md (REQ-SCON-011)
	 */
	public function testAMissingTemplateIsReportedNotSentEmpty(): void {
		$file = tempnam(sys_get_temp_dir(), 'tpl');
		file_put_contents($file, json_encode(['templates' => []]));

		try {
			$this->expectException(RuntimeException::class);
			(new VoluntaryReminderTemplate(templateFile: $file))->render(invoice: $this->invoice(language: 'nl'));
		} finally {
			unlink($file);
		}
	}//end testAMissingTemplateIsReportedNotSentEmpty()
}//end class
