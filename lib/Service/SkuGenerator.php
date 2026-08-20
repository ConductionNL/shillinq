<?php

/**
 * Shillinq SKU Generator
 *
 * ADR-031 exception-path service for declarative SKU generation. Reads SKU
 * templates from lib/Settings/sku-templates.json and interpolates product
 * attributes into the template pattern, applying per-field transformation
 * rules (mapping, passthrough, hex_first_3_chars). This is the single
 * exception service permitted by the inventory-barcode-sku design (D2 /
 * Declarative-vs-Imperative decision): the declarative OpenRegister engine
 * cannot interpolate a pattern string with per-field rule transforms, so
 * pattern interpolation lives here.
 *
 * ADR-031 exception reason: pattern interpolation with field-mapping
 * transforms is procedural string assembly that the OR schema/lifecycle
 * DSL cannot express. Templates themselves remain fully declarative (JSON);
 * only the interpolation engine is PHP. Replace with a declarative engine
 * when OR gains pattern-interpolation support.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;

/**
 * Declarative SKU generator (ADR-031 exception per inventory-barcode-sku D2).
 *
 * @spec openspec/specs/inventory-barcode-sku/spec.md
 */
class SkuGenerator {
	/**
	 * Generate a SKU for the given product attributes using a named template.
	 *
	 * Reads the template from sku-templates.json, applies each rule's
	 * transform to the matching product attribute, then interpolates the
	 * transformed values into the template pattern (e.g.
	 * "{category}-{manufacturer}-{size}-{color}").
	 *
	 * Spec wording: "InventoryItem" — the live shillinq schema is `Product`.
	 * The generator is shape-agnostic: pass any array<string, mixed> of
	 * product attributes; only the keys referenced by the template's rules
	 * are consulted.
	 *
	 * @param array<string, mixed> $item Product attribute map,
	 *                                   e.g. ['category' => 'Apparel', 'manufacturer' => 'Nike', ...].
	 * @param string $templateId The template identifier (e.g. RETAIL_APPAREL_TEMPLATE).
	 *
	 * @return string The generated SKU.
	 *
	 * @throws \InvalidArgumentException When the template is unknown or malformed.
	 *
	 * @spec openspec/changes/inventory-barcode-sku/tasks.md#task-11
	 */
	public function generate(array $item, string $templateId): string {
		$template = $this->findTemplate(templateId: $templateId);

		$values = [];
		foreach (($template['rules'] ?? []) as $rule) {
			$field = (string)($rule['field'] ?? '');
			$raw = (string)($item[$field] ?? '');
			$values[$field] = $this->applyRule(rule: $rule, value: $raw);
		}

		// Interpolate {field} placeholders in the pattern with transformed values.
		$pattern = (string)($template['pattern'] ?? '');
		return (string)preg_replace_callback(
			'/\{([a-zA-Z0-9_]+)\}/',
			static function (array $match) use ($values): string {
				return ($values[$match[1]] ?? '');
			},
			$pattern
		);

	}//end generate()

	/**
	 * Apply a single template rule's transform to a raw attribute value.
	 *
	 * Supported types:
	 *  - `mapping`: lookup in the rule's mapping table; unmapped values pass through.
	 *  - `passthrough`: value verbatim.
	 *  - `hex_first_3_chars`: named-colour lookup, else first 3 chars uppercased.
	 * Unknown rule types fall back to passthrough.
	 *
	 * @param array<string, mixed> $rule The rule definition (type, mapping, ...).
	 * @param string $value The raw attribute value.
	 *
	 * @return string The transformed value.
	 */
	private function applyRule(array $rule, string $value): string {
		$type = (string)($rule['type'] ?? 'passthrough');
		$mapping = ($rule['mapping'] ?? []);

		if ($type === 'mapping') {
			return (string)($mapping[$value] ?? $value);
		}

		if ($type === 'hex_first_3_chars') {
			if (isset($mapping[$value]) === true) {
				return (string)$mapping[$value];
			}

			return strtoupper(substr($value, 0, 3));
		}

		// Passthrough and any unknown type.
		return $value;
	}//end applyRule()

	/**
	 * Locate a template by id in sku-templates.json.
	 *
	 * @param string $templateId The template identifier to find.
	 *
	 * @return array<string, mixed> The template definition.
	 *
	 * @throws \InvalidArgumentException When the file is missing/malformed or the id is unknown.
	 */
	private function findTemplate(string $templateId): array {
		$path = __DIR__ . '/../Settings/sku-templates.json';
		if (file_exists($path) === false) {
			throw new InvalidArgumentException('SKU template file not found.');
		}

		$content = file_get_contents($path);
		if ($content === false) {
			throw new InvalidArgumentException('Failed to read SKU template file.');
		}

		$data = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($data) === false) {
			throw new InvalidArgumentException(
				'Failed to parse SKU template file: ' . json_last_error_msg()
			);
		}

		foreach (($data['templates'] ?? []) as $template) {
			if (($template['templateId'] ?? '') === $templateId) {
				return $template;
			}
		}

		throw new InvalidArgumentException('Unknown SKU template: ' . $templateId);
	}//end findTemplate()
}//end class
