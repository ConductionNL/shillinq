<?php

/**
 * BBV Budget Vocabulary Adapter
 *
 * ⚠️ THIS CLASS EXISTS BECAUSE `Budget` IS DECLARED TWICE, AND THE TWO
 * DECLARATIONS SHARE NO FIELD NAMES.
 *
 * `lib/Settings/register.d/` carries two `Budget` schemas that deep-merge into
 * one deployed schema:
 *
 * | concept        | bookkeeping-provincies-bbv-variant | bookkeeping-verplichtingenadministratie |
 * |----------------|------------------------------------|-----------------------------------------|
 * | fiscal year    | `fiscalYear`                       | `financialYear`                         |
 * | budgeted money | `totalAmount`                      | `authorised_amount`                     |
 * | programme      | `programmeStructure`               | `programmeCode`                         |
 *
 * The merge takes the union of the PROPERTIES but only one `required` list —
 * the later fragment's — so a live instance refuses a BBV-shaped Budget:
 *
 *     POST /apps/openregister/api/objects/shillinq/Budget  -> 400
 *     "The required properties (financialYear, authorised_amount) are missing."
 *
 * Measured on the rig, not inferred. The consequence for #866/#862 is the
 * quiet one: a dashboard reading only the BBV half reports **zero** for a
 * province whose budgets exist, and zero-because-nothing-matched looks exactly
 * like zero-because-there-is-no-budget.
 *
 * Adapting on READ is the narrow fix. Renaming either fragment's properties is
 * a DATA MIGRATION, and neither vocabulary belongs to this change — so the
 * collision is reported rather than silently resolved in one direction.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Reads a Budget record under either of the two colliding `Budget` schemas.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
class BbvBudgetVocabulary {
	/**
	 * The record's fiscal year.
	 *
	 * @param array<string,mixed> $budget The Budget record.
	 *
	 * @return integer The year, or 0 when neither vocabulary carries one.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function year(array $budget): int {
		$year = (int)($budget['fiscalYear'] ?? 0);
		if ($year > 0) {
			return $year;
		}

		return (int)($budget['financialYear'] ?? 0);

	}//end year()

	/**
	 * The record's BBV programme code.
	 *
	 * @param array<string,mixed> $budget The Budget record.
	 *
	 * @return string The programme code, or '' when unassigned.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function programme(array $budget): string {
		$programme = (string)($budget['programmeStructure'] ?? '');
		if ($programme !== '') {
			return $programme;
		}

		return (string)($budget['programmeCode'] ?? '');

	}//end programme()

	/**
	 * The record's budgeted amount.
	 *
	 * @param array<string,mixed> $budget The Budget record.
	 *
	 * @return float The amount, or 0.0 when neither vocabulary carries one.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function amount(array $budget): float {
		$amount = (float)($budget['totalAmount'] ?? 0);
		if ($amount !== 0.0) {
			return $amount;
		}

		return (float)($budget['authorised_amount'] ?? 0);

	}//end amount()
}//end class
