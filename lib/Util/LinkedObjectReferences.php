<?php

/**
 * Linked Object References
 *
 * A financial document names the objects it is about in `linkedObjects`. Only
 * register, schema and id identify an object; app, type and label describe it.
 * This class turns a list into comparable references and says whether every
 * item can be followed.
 *
 * @category Util
 * @package  OCA\Shillinq\Util
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

namespace OCA\Shillinq\Util;

/**
 * Pure functions over a list of linked objects. No constructor, no state.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
 */
final class LinkedObjectReferences {

	/**
	 * Refusal for an item that names no register, schema or id.
	 *
	 * @var string
	 */
	public const MALFORMED = 'Every linked object needs a register, a schema and an id.';

	/**
	 * The list as `register/schema/id` references, or null when an item names no register, schema or id.
	 *
	 * @param mixed $list A stored or incoming list.
	 *
	 * @return array<int, string>|null The references, in order.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
	 */
	public static function references(mixed $list): ?array {
		if (is_array($list) === false) {
			return null;
		}

		$references = [];
		foreach ($list as $item) {
			$reference = self::referenceOf(item: $item);
			if ($reference === null) {
				return null;
			}

			$references[] = $reference;
		}

		return $references;
	}//end references()

	/**
	 * Whether every item of a list names a register, a schema and an id.
	 *
	 * @param mixed $list A list of linked objects.
	 *
	 * @return bool True when the list can be stored.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-001)
	 */
	public static function isWellFormed(mixed $list): bool {
		return self::references(list: $list) !== null;
	}//end isWellFormed()

	/**
	 * One item as a reference, or null when it lacks a part.
	 *
	 * @param mixed $item One linked object.
	 *
	 * @return string|null The reference.
	 */
	private static function referenceOf(mixed $item): ?string {
		if (is_array($item) === false) {
			return null;
		}

		$parts = [];
		foreach (['register', 'schema', 'id'] as $key) {
			$value = trim((string)($item[$key] ?? ''));
			if ($value === '') {
				return null;
			}

			$parts[] = $value;
		}

		return implode('/', $parts);
	}//end referenceOf()
}//end class
