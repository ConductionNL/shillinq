<?php

/**
 * Unit tests for InvoiceDocumentLanguage.
 *
 * REQ-SID-003 and REQ-SID-004: the document speaks the customer's language and
 * shows amounts in the invoice currency, formatted for that language.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentLanguage;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Brightside Consulting Ltd (en, GBP) and Gemeente Voorbeeld (nl, EUR).
 */
final class InvoiceDocumentLanguageTest extends TestCase {

	/**
	 * Read a formatted amount with every kind of space as a plain space.
	 *
	 * @param string $value The formatted amount.
	 *
	 * @return string
	 */
	public static function plain(string $value): string {
		return str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
	}//end plain()

	/**
	 * A factory answering the shipped catalogue of the asked language.
	 *
	 * @param TestCase          $test  The test creating the doubles.
	 * @param array<int, string> $asked Records the app and language asked for.
	 *
	 * @return IFactory
	 */
	public static function factory(TestCase $test, array &$asked = []): IFactory {
		$factory = $test->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			static function (string $app, ?string $lang = null) use ($test, &$asked): IL10N {
				$asked[] = $app . ':' . $lang;
				$catalogue = json_decode((string)file_get_contents(__DIR__ . '/../../../../../l10n/' . $lang . '.json'), true)['translations'];
				$l10n = $test->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(static fn (string $text): string => ($catalogue[$text] ?? $text));
				return $l10n;
			}
		);

		return $factory;
	}//end factory()

	/**
	 * The customer's language when supported, else the administration's, else Dutch.
	 *
	 * @return void
	 */
	public function testTheLanguageIsTheCustomersWhenTheInvoiceSupportsIt(): void {
		$language = new InvoiceDocumentLanguage(self::factory($this));
		$this->assertSame('en', $language->resolve('EN', 'nl'));
		$this->assertSame('de', $language->resolve('es', 'de'));
		$this->assertSame('nl', $language->resolve('', ''));
	}//end testTheLanguageIsTheCustomersWhenTheInvoiceSupportsIt()

	/**
	 * An English customer reads Invoice, Due date and Total; a Dutch one Factuur and Vervaldatum.
	 *
	 * @return void
	 */
	public function testTheLabelsAreInTheDocumentLanguage(): void {
		$asked = [];
		$language = new InvoiceDocumentLanguage(self::factory($this, $asked));

		$english = $language->labels('en');
		$this->assertSame(['Invoice', 'Due date', 'Total'], [$english['title'], $english['dueDate'], $english['total']]);
		$dutch = $language->labels('nl');
		$this->assertSame('Factuur', $dutch['title']);
		$this->assertSame('Vervaldatum', $dutch['dueDate']);
		$this->assertSame(['shillinq:en', 'shillinq:nl'], $asked);
		$this->assertSame(array_keys(InvoiceDocumentLanguage::LABELS), array_keys($dutch));
	}//end testTheLabelsAreInTheDocumentLanguage()

	/**
	 * Every label has a Dutch, German and French translation.
	 *
	 * @return void
	 */
	public function testEveryLabelIsTranslated(): void {
		foreach (['nl', 'de', 'fr'] as $lang) {
			$catalogue = json_decode((string)file_get_contents(__DIR__ . '/../../../../../l10n/' . $lang . '.json'), true)['translations'];
			$untranslated = static fn (string $label): bool => ($catalogue[$label] ?? '') === '';
			$missing = array_values(array_filter(InvoiceDocumentLanguage::LABELS, $untranslated));
			$this->assertSame([], $missing, $lang);
		}
	}//end testEveryLabelIsTranslated()

	/**
	 * Pounds in English formatting with no euro sign; euros in Dutch and German formatting.
	 *
	 * @return void
	 */
	public function testAmountsUseTheInvoiceCurrencyAndTheLanguagesFormatting(): void {
		$language = new InvoiceDocumentLanguage(self::factory($this));
		$this->assertSame('£4,800.00', self::plain($language->money(4800, 'GBP', 'en')));
		$this->assertStringNotContainsString('€', $language->money(4800, 'GBP', 'en'));
		$this->assertSame('€ 24.200,00', self::plain($language->money(24200, 'EUR', 'nl')));
		$this->assertSame('24.200,00 €', self::plain($language->money(24200, 'EUR', 'de')));
		$this->assertSame('€ 1,50', self::plain($language->money(1.5, 'bogus', 'nl')), 'an unreadable currency reads as EUR');
		$this->assertSame('1.234,5', $language->number(1234.5, 'nl'));
		$this->assertSame('12.5', $language->number(12.5, 'en'));
	}//end testAmountsUseTheInvoiceCurrencyAndTheLanguagesFormatting()
}//end class
