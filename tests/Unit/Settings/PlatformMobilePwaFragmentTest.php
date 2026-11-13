<?php

/**
 * Unit tests for the platform-mobile-pwa register fragment (ADR-037).
 *
 * A receipt and an invoice draft made on the phone carry the client id the
 * phone gave them, unique per schema, and both schema versions move up.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Structural tests for the platform-mobile-pwa register fragment.
 */
final class PlatformMobilePwaFragmentTest extends TestCase {

	/**
	 * The register's settings directory.
	 */
	private const ROOT = __DIR__ . '/../../../lib/Settings';

	/**
	 * Receipt and ARInvoice declare a nullable uuid clientId and a uniqueness
	 * clause on it.
	 *
	 * @return void
	 */
	public function testBothSchemasDeclareAUniqueClientId(): void {
		$schemas = $this->decode(path: self::ROOT . '/register.d/zz-platform-mobile-pwa.json')['components']['schemas'];

		foreach (['Receipt', 'ARInvoice'] as $name) {
			$clientId = $schemas[$name]['properties']['clientId'];
			self::assertSame('string', $clientId['type'], $name);
			self::assertSame('uuid', $clientId['format'], $name);
			self::assertTrue($clientId['nullable'], $name . ': a desktop record has none');
			self::assertSame([['clientId']], $schemas[$name]['x-openregister-unique'], $name);
		}
	}//end testBothSchemasDeclareAUniqueClientId()

	/**
	 * Both versions move past every earlier setter, so an existing instance
	 * re-imports the schemas: Receipt's here, ARInvoice's in the fragment that
	 * keeps the last word on it.
	 *
	 * @return void
	 */
	public function testBothVersionsMoveUp(): void {
		$files = glob(self::ROOT . '/register.d/*.json');
		sort($files);
		$setters = [];
		foreach (array_merge([self::ROOT . '/shillinq_register.json'], $files) as $file) {
			$schemas = ($this->decode(path: $file)['components']['schemas'] ?? []);
			foreach (['Receipt', 'ARInvoice'] as $name) {
				if (isset($schemas[$name]['version']) === true) {
					$setters[$name][] = [basename($file), $schemas[$name]['version']];
				}
			}
		}

		$expected = [
			'Receipt' => ['zz-platform-mobile-pwa.json', '0.2.0'],
			'ARInvoice' => ['zz-financial-documents-linked-objects.json', '0.18.0'],
		];
		foreach ($expected as $name => [$file, $version]) {
			self::assertSame([$file, $version], end($setters[$name]), $name);
			$others = array_column(array_slice($setters[$name], 0, -1), 1);
			usort($others, 'version_compare');
			self::assertTrue(version_compare($version, (string)end($others), '>'), $name . ' moves past ' . end($others));
		}
	}//end testBothVersionsMoveUp()

	/**
	 * Decode one register file.
	 *
	 * @param string $path The file.
	 *
	 * @return array<string,mixed>
	 */
	private function decode(string $path): array {
		$decoded = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($decoded, $path);

		return $decoded;
	}//end decode()
}//end class
