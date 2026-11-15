<?php

/**
 * A schema of the shillinq register as the app imports it, and a validator
 * that answers the way OpenRegister does.
 *
 * Tests that check a service against a hand-written array pass while the
 * register refuses the same write (shillinq#1753, #1754). This helper hands a
 * test the REAL schema: shillinq_register.json merged with every register.d
 * fragment by SettingsService::deepMergeConfig(), the merge the app runs at
 * install time. It validates with opis/json-schema, the library OpenRegister
 * uses, after the preparations OpenRegister's ValidateObject applies
 * first: a `$ref` on a property is a relation marker, not a schema reference,
 * an empty string or empty array on a field that is not required is
 * dropped, and a top-level field that is not required accepts null.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\Shillinq\Service\SettingsService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use ReflectionMethod;

/**
 * Reads merged register schemas and validates objects against them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RegisterSchema {

	/**
	 * The merged register, built once per run.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $register = null;

	/**
	 * One schema of the merged register, prepared the way OpenRegister prepares it.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(string $slug): array {
		if (self::$register === null) {
			$settings = dirname(__DIR__, 4) . '/lib/Settings';
			$config = json_decode((string)file_get_contents($settings . '/shillinq_register.json'), true, flags: JSON_THROW_ON_ERROR);

			$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
			$fragments = glob($settings . '/register.d/*.json');
			sort($fragments);
			foreach ($fragments as $fragmentPath) {
				$fragment = json_decode((string)file_get_contents($fragmentPath), true);
				if (is_array($fragment) === true) {
					$config = $merge->invoke(null, $config, $fragment);
				}
			}

			self::$register = $config;
		}

		$schema = self::$register['components']['schemas'][$slug];

		// A string property with a `$ref` keeps its type and format; array
		// items that `$ref` a schema become UUID strings
		// (ValidateObject::transformPropertyForOpenRegister()).
		foreach ($schema['properties'] as $name => $property) {
			if (isset($property['$ref']) === true && ($property['type'] ?? null) === 'string') {
				unset($schema['properties'][$name]['$ref']);
			}

			if (isset($property['items']['$ref']) === true) {
				$schema['properties'][$name]['items'] = ['type' => 'string'];
			}
		}

		return $schema;
	}//end schema()

	/**
	 * Validate an object as OpenRegister would on save.
	 *
	 * @param string $slug The schema slug.
	 * @param array<string,mixed> $object The object handed to saveObject().
	 *
	 * @return array<string,mixed> Formatted errors, empty when the object is valid.
	 */
	public static function errors(string $slug, array $object): array {
		$schema = self::schema(slug: $slug);
		unset($object['id']);

		// ValidateObject::validateObject() drops an empty string or an empty
		// array on a field that is not required before it validates. A null on
		// a top-level field that is not required passes too: prepareSchemaForValidation()
		// widens that property's type with `null` (an enum without null has the
		// key filtered out instead). Nested properties are not widened, so a
		// null inside an object still fails, as it does in OpenRegister.
		$required = ($schema['required'] ?? []);
		$object = array_filter(
			$object,
			static fn ($value, $key): bool => in_array($key, $required, true) === true || ($value !== '' && $value !== [] && $value !== null),
			ARRAY_FILTER_USE_BOTH
		);

		$result = (new Validator())->validate(
			json_decode(json_encode($object, JSON_THROW_ON_ERROR)),
			json_encode($schema, JSON_THROW_ON_ERROR)
		);
		if ($result->isValid() === true) {
			return [];
		}

		return (new ErrorFormatter())->format($result->error());
	}//end errors()
}//end class
