<?php

/**
 * Ledger Group Entry Key
 *
 * The stable lookup key of a LedgerGroup entry: its id, falling back to its
 * slug. The scenario evaluator and the budget-vs-actuals calculator both key
 * their per-group figures this way.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Resolves the lookup key of one LedgerGroup entry.
 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 */
final class LedgerGroupEntryKey {
	/**
	 * The entry's id, or its slug when it has no id; null for no entry or neither set.
	 *
	 * @param mixed $entry The LedgerGroup entry, or null when there is none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 */
	public static function of(mixed $entry): ?string {
		if ($entry === null) {
			return null;
		}

		$id = (string)($entry['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		$slug = (string)($entry['slug'] ?? '');
		if ($slug !== '') {
			return $slug;
		}

		return null;

	}//end of()
}//end class
