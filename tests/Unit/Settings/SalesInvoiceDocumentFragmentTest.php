<?php

/**
 * Unit tests for the sales-invoice-document register fragment.
 *
 * REQ-SID-001 and REQ-SID-003: one invoice layout per administration with
 * colours that read against white, and the document language on the
 * customer and the invoice.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
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
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-1.2
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The fragment's schemas and seeds.
 */
final class SalesInvoiceDocumentFragmentTest extends TestCase {

	/**
	 * The register fragment directory.
	 */
	private const DIR = __DIR__ . '/../../../lib/Settings/register.d';

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed>
	 */
	private static function fragment(): array {
		$data = json_decode((string)file_get_contents(self::DIR . '/zz-sales-invoice-document.json'), true);
		self::assertIsArray($data);

		return $data;
	}//end fragment()

	/**
	 * WCAG relative luminance contrast of a hex colour against white.
	 *
	 * @param string $hex The colour, #RRGGBB.
	 *
	 * @return float
	 */
	private static function contrastOnWhite(string $hex): float {
		$channels = array_map(
			static function (string $pair): float {
				$c = hexdec($pair) / 255;
				if ($c <= 0.03928) {
					return $c / 12.92;
				}

				return (($c + 0.055) / 1.055) ** 2.4;
			},
			str_split(ltrim($hex, '#'), 2)
		);
		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		return 1.05 / ($luminance + 0.05);
	}//end contrastOnWhite()

	/**
	 * The layout carries the fields of design D1, and its default colours reach AA against white.
	 *
	 * @return void
	 */
	public function testTheLayoutSchema(): void {
		$layout = self::fragment()['components']['schemas']['InvoiceLayout'];

		$this->assertSame(['administrationId', 'variant'], $layout['required']);
		$fields = ['administrationId', 'logoFileId', 'primaryColor', 'accentColor', 'variant', 'footerText', 'showQr'];
		$this->assertSame($fields, array_keys($layout['properties']));
		$this->assertSame(['classic', 'modern', 'compact'], $layout['properties']['variant']['enum']);
		$this->assertTrue($layout['properties']['showQr']['default']);
		$this->assertTrue($layout['x-openregister-audit-trail']['enabled']);
		foreach (['primaryColor', 'accentColor'] as $colour) {
			$this->assertGreaterThanOrEqual(4.5, self::contrastOnWhite($layout['properties'][$colour]['default']), $colour);
		}
	}//end testTheLayoutSchema()

	/**
	 * Every seeded administration has exactly one default layout that satisfies the schema.
	 *
	 * @return void
	 */
	public function testEverySeededAdministrationHasOneDefaultLayout(): void {
		$administrations = [];
		foreach (glob(self::DIR . '/*.json') as $file) {
			foreach ((json_decode((string)file_get_contents($file), true)['components']['objects'] ?? []) as $object) {
				if (($object['@self']['schema'] ?? '') === 'Administration') {
					$administrations[] = $object['@self']['slug'];
				}
			}
		}

		$isLayout = static fn (array $o): bool => $o['@self']['schema'] === 'InvoiceLayout';
		$layouts = array_values(array_filter(self::fragment()['components']['objects'], $isLayout));
		$seeded = array_column($layouts, 'administrationId');
		sort($administrations);
		sort($seeded);
		$this->assertSame($administrations, $seeded);
		foreach ($layouts as $layout) {
			$this->assertSame('classic', $layout['variant']);
			$this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $layout['primaryColor']);
		}
	}//end testEverySeededAdministrationHasOneDefaultLayout()

	/**
	 * The customer and the invoice carry the document language, with versions moved up.
	 *
	 * @return void
	 */
	public function testTheDocumentLanguage(): void {
		$schemas = self::fragment()['components']['schemas'];

		$this->assertSame(['nl', 'en', 'de', 'fr'], $schemas['CustomerMaster']['properties']['language']['enum']);
		$this->assertSame(['nl', 'en', 'de', 'fr'], $schemas['ARInvoice']['properties']['documentLanguage']['enum']);
		$this->assertSame(['0.5.0', '0.20.0'], [$schemas['CustomerMaster']['version'], $schemas['ARInvoice']['version']]);
	}//end testTheDocumentLanguage()
}//end class
