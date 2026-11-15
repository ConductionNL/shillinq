<?php

/**
 * Unit tests for the zz-financial-documents-linked-objects register fragment.
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
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every financial document declares the same linkedObjects list as Contract.
 */
final class LinkedObjectsFragmentTest extends TestCase {
	/**
	 * The register fragment directory.
	 *
	 * @var string
	 */
	private const FRAGMENT_DIR = __DIR__ . '/../../../lib/Settings/register.d';

	/**
	 * The fragment under test.
	 *
	 * @var string
	 */
	private const FRAGMENT = 'zz-financial-documents-linked-objects.json';

	/**
	 * The four document schemas that carry the list.
	 *
	 * @var array<int, string>
	 */
	private const DOCUMENTS = ['Quote', 'ARInvoice', 'PaymentRequest', 'InboundPayable'];

	/**
	 * Decode one fragment.
	 *
	 * @param string $name The file name inside register.d.
	 *
	 * @return array<string, mixed> The decoded fragment.
	 */
	private static function fragment(string $name): array {
		$data = json_decode((string)file_get_contents(self::FRAGMENT_DIR . '/' . $name), true);
		self::assertIsArray($data, $name . ' does not decode');

		return $data;
	}//end fragment()

	/**
	 * The highest version any other fragment declares for a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return string The version.
	 */
	private static function highestOtherVersion(string $schema): string {
		$highest = '0.0.0';
		foreach (glob(self::FRAGMENT_DIR . '/*.json') as $file) {
			if (basename($file) === self::FRAGMENT) {
				continue;
			}

			$data    = json_decode((string)file_get_contents($file), true);
			$version = (string)($data['components']['schemas'][$schema]['version'] ?? '0.0.0');
			if (version_compare($version, $highest, '>') === true) {
				$highest = $version;
			}
		}

		return $highest;
	}//end highestOtherVersion()

	/**
	 * Each document declares linkedObjects with Contract's item shape plus `app`.
	 *
	 * @return void
	 */
	public function testEveryDocumentDeclaresTheContractListShape(): void {
		$schemas  = self::fragment(self::FRAGMENT)['components']['schemas'];
		$contract = self::fragment('contract-lifecycle-management.json')['components']['schemas']['Contract']['properties']['linkedObjects'];

		foreach (self::DOCUMENTS as $name) {
			self::assertArrayHasKey($name, $schemas, $name . ' is missing from the fragment');
			$list = $schemas[$name]['properties']['linkedObjects'];

			self::assertSame('array', $list['type'], $name);
			self::assertSame(['register', 'schema', 'id'], $list['items']['required'], $name);
			foreach (array_keys($contract['items']['properties']) as $part) {
				self::assertArrayHasKey($part, $list['items']['properties'], $name . '.linkedObjects lacks ' . $part);
			}

			self::assertArrayHasKey('app', $list['items']['properties'], $name . '.linkedObjects lacks app');
		}
	}//end testEveryDocumentDeclaresTheContractListShape()

	/**
	 * The fragment sorts after every other fragment that sets a version for these schemas,
	 * because the last fragment in name order wins the version.
	 *
	 * @return void
	 */
	public function testTheFragmentHasTheLastWordOnTheVersions(): void {
		foreach (glob(self::FRAGMENT_DIR . '/*.json') as $file) {
			$schemas = (json_decode((string)file_get_contents($file), true)['components']['schemas'] ?? []);
			foreach (self::DOCUMENTS as $name) {
				if (isset($schemas[$name]['version']) === true && basename($file) !== self::FRAGMENT) {
					self::assertLessThan(0, strcmp(basename($file), self::FRAGMENT), basename($file) . ' sets ' . $name . ' after this fragment');
				}
			}
		}
	}//end testTheFragmentHasTheLastWordOnTheVersions()

	/**
	 * Each schema's version moves past every other fragment, so the import picks the list up.
	 *
	 * @return void
	 */
	public function testEveryDocumentVersionMovesUp(): void {
		$schemas = self::fragment(self::FRAGMENT)['components']['schemas'];
		foreach (self::DOCUMENTS as $name) {
			self::assertTrue(
				version_compare((string)$schemas[$name]['version'], self::highestOtherVersion($name), '>'),
				$name . ' version must be above ' . self::highestOtherVersion($name)
			);
		}
	}//end testEveryDocumentVersionMovesUp()
}//end class
