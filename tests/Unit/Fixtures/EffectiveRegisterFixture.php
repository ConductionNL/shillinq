<?php

/**
 * The effective register, as SettingsService assembles it.
 *
 * The monolith plus every register.d fragment in file-name order, merged the way
 * `SettingsService::deepMergeConfig()` merges them: objects by key, lists
 * concatenated, a later scalar wins. A test that asks whether a written key is a
 * declared property must ask this merged register, because no single fragment
 * holds a whole schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Fixtures
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/billing-inherited-defects/specs/recurring-invoicing/spec.md (REQ-RIN-010)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Fixtures;

/**
 * Reads the merged register and answers declared property names.
 */
final class EffectiveRegisterFixture {
	/**
	 * The merged register config.
	 *
	 * @return array<string,mixed>
	 */
	public static function register(): array {
		$root = __DIR__ . '/../../../lib/Settings';
		$merged = self::decode(path: $root . '/shillinq_register.json');
		$fragments = glob($root . '/register.d/*.json');
		sort($fragments);
		foreach ($fragments as $fragment) {
			$merged = self::merge(base: $merged, overlay: self::decode(path: $fragment));
		}

		return $merged;
	}//end register()

	/**
	 * One merged schema.
	 *
	 * @param string $schema The schema key.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(string $schema): array {
		return (array)(self::register()['components']['schemas'][$schema] ?? []);
	}//end schema()

	/**
	 * The declared top-level property names of a schema.
	 *
	 * @param string $schema The schema key.
	 *
	 * @return array<int,string>
	 */
	public static function properties(string $schema): array {
		return array_keys((array)(self::schema(schema: $schema)['properties'] ?? []));
	}//end properties()

	/**
	 * The declared property names of the items of an array property.
	 *
	 * @param string $schema   The schema key.
	 * @param string $property The array property.
	 *
	 * @return array<int,string>
	 */
	public static function itemProperties(string $schema, string $property): array {
		return array_keys((array)(self::schema(schema: $schema)['properties'][$property]['items']['properties'] ?? []));
	}//end itemProperties()

	/**
	 * Decode one JSON file.
	 *
	 * @param string $path The file.
	 *
	 * @return array<mixed>
	 */
	private static function decode(string $path): array {
		$data = json_decode((string)file_get_contents($path), true);
		if (is_array($data) === true) {
			return $data;
		}

		return [];
	}//end decode()

	/**
	 * Merge the way SettingsService::deepMergeConfig() does.
	 *
	 * @param array<mixed> $base    The accumulated config.
	 * @param array<mixed> $overlay The fragment.
	 *
	 * @return array<mixed>
	 */
	private static function merge(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true && isset($base[$key]) === true && is_array($base[$key]) === true) {
				if (array_is_list($base[$key]) === true && array_is_list($value) === true) {
					$base[$key] = array_merge($base[$key], $value);
					continue;
				}

				$base[$key] = self::merge(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end merge()
}//end class
