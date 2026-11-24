<?php

/**
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentLanguage;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentRenderer;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The `sales-invoice` template in docudesk-templates.json, held to the data
 * InvoiceDocumentData assembles and the variants a layout names (REQ-SID-002).
 *
 * @covers \OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentRenderer
 */
final class SalesInvoiceTemplateTest extends TestCase {

	private const REGISTRY = __DIR__.'/../../../lib/Settings/docudesk-templates.json';

	/**
	 * The sales-invoice entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function template(): array {
		$registry = json_decode((string)file_get_contents(self::REGISTRY), true, 512, JSON_THROW_ON_ERROR);
		$found = array_values(array_filter($registry['templates'], static fn (array $t): bool => ($t['templateId'] ?? '') === 'sales-invoice'));
		self::assertCount(1, $found, 'exactly one sales-invoice template');

		return $found[0];
	}

	/**
	 * A renderer reading the given registry.
	 *
	 * @param string $file The registry file.
	 *
	 * @return InvoiceDocumentRenderer
	 */
	private function renderer(string $file = self::REGISTRY): InvoiceDocumentRenderer {
		return new InvoiceDocumentRenderer(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IAppConfig::class),
			$file
		);
	}

	/**
	 * The entry is discoverable the way the renderer looks for it, and lists
	 * the data keys InvoiceDocumentData::assemble() returns.
	 *
	 * @return void
	 */
	public function testTheEntryNamesItsCategoryAndData(): void {
		$template = self::template();

		self::assertSame(
			['shillinq', 'sales-invoice', 'pdf', 'invoiceDocument'],
			[$template['namespace'], $template['category'], $template['format'], $template['dataRoot']]
		);
		self::assertSame(InvoiceDocumentRenderer::TEMPLATE_ID, $template['category']);
		self::assertSame(
			['language', 'currency', 'labels', 'invoice', 'creditor', 'recipient', 'lines', 'totals', 'qr', 'layout'],
			$template['dataKeys']
		);
		$assembler = (string)file_get_contents(__DIR__.'/../../../lib/Service/Sales/InvoiceDocument/InvoiceDocumentData.php');
		foreach ($template['dataKeys'] as $key) {
			self::assertStringContainsString("'".$key."' => ", $assembler, $key.' is assembled');
		}
	}

	/**
	 * The three variants of the InvoiceLayout schema, each with the same settings.
	 *
	 * @return void
	 */
	public function testTheThreeVariantsCarryTheSameSettings(): void {
		$template = self::template();

		self::assertSame(['classic', 'modern', 'compact'], array_keys($template['variants']));
		self::assertSame('classic', $template['defaultVariant']);
		$keys = array_keys($template['variants']['classic']);
		foreach ($template['variants'] as $name => $variant) {
			self::assertSame($keys, array_keys($variant), $name);
		}

		$file = __DIR__.'/../../../lib/Settings/register.d/zz-sales-invoice-document.json';
		$fragment = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		$enum = $fragment['components']['schemas']['InvoiceLayout']['properties']['variant']['enum'] ?? null;
		self::assertSame(['classic', 'modern', 'compact'], $enum);
	}

	/**
	 * The body uses only assembled data, known labels and variant settings,
	 * and holds no language of its own.
	 *
	 * @return void
	 */
	public function testTheBodyReadsOnlyWhatItIsGiven(): void {
		$template = self::template();
		$body = $template['body'];

		preg_match_all('/invoiceDocument\.([A-Za-z]+)/', $body, $roots);
		self::assertSame([], array_values(array_diff(array_unique($roots[1]), $template['dataKeys'])));

		preg_match_all('/invoiceDocument\.labels\.([A-Za-z]+)/', $body, $labels);
		self::assertSame([], array_values(array_diff(array_unique($labels[1]), array_keys(InvoiceDocumentLanguage::LABELS))));

		preg_match_all('/variant\.([A-Za-z]+)/', $body, $settings);
		self::assertSame([], array_values(array_diff(array_unique($settings[1]), array_keys($template['variants']['classic']))));

		foreach (['Factuur', 'Vervaldatum', 'Totaal', '€'] as $literal) {
			self::assertStringNotContainsString($literal, $body);
		}

		self::assertStringContainsString('<barcode type="QR" code="{{ invoiceDocument.qr.epc|e(\'html_attr\') }}"', $body);
	}

	/**
	 * The renderer hands docudesk the variant a layout names, else the default.
	 *
	 * @return void
	 */
	public function testTheRendererReadsTheVariant(): void {
		$renderer = $this->renderer();

		self::assertSame(['modern', 'variant-modern'], [$renderer->variant('modern')['name'], $renderer->variant('modern')['className']]);
		self::assertSame('classic', $renderer->variant('')['name']);
		self::assertSame('classic', $renderer->variant('baroque')['name']);
	}

	/**
	 * A registry without the template is an error, not a guess.
	 *
	 * @return void
	 */
	public function testAMissingRegistryIsAnError(): void {
		$this->expectException(RuntimeException::class);
		$this->renderer(__DIR__.'/does-not-exist.json')->variant('classic');
	}
}
