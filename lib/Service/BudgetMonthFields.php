<?php

/**
 * Budget Month Fields
 *
 * The twelve month amounts of a BudgetLine (month01Amount .. month12Amount)
 * and the field of one calendar month. The scenario evaluator and the
 * budget-vs-actuals calculator both address budget lines by these names.
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
 * Names the month amount fields of a BudgetLine.
 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 */
final class BudgetMonthFields {
	/**
	 * BudgetLine's own month01Amount..month12Amount fields, January first.
	 *
	 * @var list<string>
	 */
	public const FIELDS = [
		'month01Amount',
		'month02Amount',
		'month03Amount',
		'month04Amount',
		'month05Amount',
		'month06Amount',
		'month07Amount',
		'month08Amount',
		'month09Amount',
		'month10Amount',
		'month11Amount',
		'month12Amount',
	];

	/**
	 * Resolve the `monthNNAmount` field name for a 1-12 calendar month.
	 *
	 * @param int $monthNumber The calendar month, 1-12.
	 *
	 * @return string The field name; `month01Amount` for any out-of-range input.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 */
	public static function of(int $monthNumber): string {
		$index = ($monthNumber - 1);
		if ($index < 0 || $index > 11) {
			return self::FIELDS[0];
		}

		return self::FIELDS[$index];

	}//end of()
}//end class
