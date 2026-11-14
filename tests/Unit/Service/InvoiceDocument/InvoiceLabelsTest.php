<?php

/**
 * Unit tests for the invoice labels and the document language (REQ-SID-003).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\InvoiceDocument;

use OCA\Shillinq\Service\InvoiceDocument\InvoiceLabels;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Labels are resolved through the l10n factory in the document language.
 */
#[CoversClass(InvoiceLabels::class)]
final class InvoiceLabelsTest extends TestCase {
	/**
	 * The customer's language wins, then the administration's, then Dutch.
	 *
	 * @return void
	 */
	public function testTheDocumentLanguageFallsBackInOrder(): void {
		self::assertSame('en', InvoiceLabels::documentLanguage('en', 'nl'));
		self::assertSame('de', InvoiceLabels::documentLanguage('DE_de', 'nl'));
		self::assertSame('fr', InvoiceLabels::documentLanguage('', 'fr'));
		self::assertSame('nl', InvoiceLabels::documentLanguage('es', 'it'));
		self::assertSame('nl', InvoiceLabels::documentLanguage('', ''));

	}//end testTheDocumentLanguageFallsBackInOrder()

	/**
	 * English labels read "Invoice", "Due date" and "Total" for the English customer.
	 *
	 * @return void
	 */
	public function testEnglishLabelsComeFromTheShillinqCatalogue(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->expects($this->once())->method('get')->with('shillinq', 'en')->willReturn($l10n);

		$labels = (new InvoiceLabels($factory))->forLanguage('en');

		self::assertSame('Invoice', $labels['invoice']);
		self::assertSame('Due date', $labels['dueDate']);
		self::assertSame('Total', $labels['total']);
		self::assertSame('Scan to pay', $labels['scanToPay']);

	}//end testEnglishLabelsComeFromTheShillinqCatalogue()

	/**
	 * Dutch labels are the catalogue's translations.
	 *
	 * @return void
	 */
	public function testDutchLabelsAreTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text): string => ['Invoice' => 'Factuur', 'Due date' => 'Vervaldatum'][$text] ?? $text
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->with('shillinq', 'nl')->willReturn($l10n);

		$labels = (new InvoiceLabels($factory))->forLanguage('nl');

		self::assertSame('Factuur', $labels['invoice']);
		self::assertSame('Vervaldatum', $labels['dueDate']);

	}//end testDutchLabelsAreTranslated()
}//end class
