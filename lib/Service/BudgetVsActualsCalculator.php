<?php

/**
 * Budget vs. Actuals Calculator
 *
 * The arithmetic half of `budget-core-schema`'s budget-vs-actuals roll-up
 * (REQ-BCS-008), mirroring the {@see BbvProgrammeBudgetReader}/
 * {@see BbvProgrammeBudgetCalculator} split: {@see BudgetVsActualsReader}
 * does every OpenRegister read; this class reads NOTHING — every input is
 * the context bundle the reader assembled, which is what makes the
 * parent-rollup rule testable without an OpenRegister at all.
 *
 * ## The parent-rollup rule (design.md §3d) — and why budgeted and actual
 * amounts resolve differently
 *
 * A parent `LedgerGroup` with children (e.g. seeded `Personeel`, parent of
 * `Lonen en salarissen`/`Sociale lasten en pensioenlasten`) needs its own
 * resolved value even though it may carry no `accountRanges` of its own.
 * The two sides of the roll-up are NOT symmetric, deliberately:
 *
 *  - **Budgeted**: an operator's own `BudgetLine` against the parent WINS
 *    over the recursive sum of the children's own `BudgetLine`s — an
 *    operator may budget "Personeel" as one typed-in figure OR budget its
 *    two children separately, and summing BOTH when both exist would
 *    double-count (design.md §3d). This is a CHOICE between two mutually
 *    exclusive operator inputs, so "own wins" is the only rule that avoids
 *    double counting.
 *  - **Actual**: GL money is an objective fact, not an operator choice — a
 *    parent's own directly-assigned accounts (if any) and its descendants'
 *    resolved accounts are disjoint by construction (an account belongs to
 *    exactly one `LedgerGroup`'s explicit membership), so summing BOTH
 *    unconditionally is correct and cannot double-count. There is no
 *    "own wins" branch on the actual side.
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
 * Pure budget-vs-actuals arithmetic over a {@see BudgetVsActualsReader}
 * context bundle (REQ-BCS-008).
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 */
class BudgetVsActualsCalculator {
	/**
	 * The 12 monthly amount field names, in calendar order.
	 *
	 * @var list<string>
	 */
	private const MONTH_FIELDS = [
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
	 * Resolve a `LedgerGroup`'s budgeted amount for one calendar month: its
	 * own `BudgetLine`'s amount for that month if one exists against it,
	 * otherwise the recursive sum of its children's own resolved budgeted
	 * amounts (design.md §3d — "own wins over roll-up").
	 *
	 * @param string $ledgerGroupKey The LedgerGroup's id or slug (as it appears in `$context['ledgerGroupKeyToIndex']`).
	 * @param integer $monthNumber The calendar month, 1-12.
	 * @param array<string,mixed> $context The {@see BudgetVsActualsReader::loadContext()} bundle.
	 *
	 * @return integer The budgeted amount, in EUR cents.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 */
	public function budgetedAmount(string $ledgerGroupKey, int $monthNumber, array $context): int {
		$field = $this->monthField(monthNumber: $monthNumber);
		$index = ($context['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			return 0;
		}

		$ownLine = $this->ownBudgetLine(ledgerGroupIndex: $index, context: $context);
		if ($ownLine !== null) {
			return (int)($ownLine[$field] ?? 0);
		}

		$sum = 0;
		foreach (($context['ledgerGroupChildrenByIndex'][$index] ?? []) as $childIndex) {
			$childKey = $this->keyForIndex(index: $childIndex, context: $context);
			if ($childKey === null) {
				continue;
			}

			$sum += $this->budgetedAmount(ledgerGroupKey: $childKey, monthNumber: $monthNumber, context: $context);
		}

		return $sum;

	}//end budgetedAmount()

	/**
	 * Resolve a `LedgerGroup`'s actual (GL-derived) amount for one calendar
	 * month: the sum of its own resolved member accounts' GL activity PLUS
	 * the recursive sum of its children's own resolved actual amounts — an
	 * unconditional roll-up, since GL money cannot double-count across
	 * disjoint account memberships (design.md §3d, class docblock).
	 *
	 * @param string $ledgerGroupKey The LedgerGroup's id or slug.
	 * @param string $monthKey The `YYYY-MM` month key.
	 * @param array<string,mixed> $context The {@see BudgetVsActualsReader::loadContext()} bundle.
	 *
	 * @return integer The actual amount, in EUR cents.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 */
	public function actualAmount(string $ledgerGroupKey, string $monthKey, array $context): int {
		$index = ($context['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			return 0;
		}

		$entry = ($context['ledgerGroupEntries'][$index] ?? null);
		$own = 0;
		foreach (($entry['memberAccountNumbers'] ?? []) as $accountNumber) {
			$own += (int)($context['actualsByAccountMonth'][$accountNumber][$monthKey] ?? 0);
		}

		$childrenSum = 0;
		foreach (($context['ledgerGroupChildrenByIndex'][$index] ?? []) as $childIndex) {
			$childKey = $this->keyForIndex(index: $childIndex, context: $context);
			if ($childKey === null) {
				continue;
			}

			$childrenSum += $this->actualAmount(ledgerGroupKey: $childKey, monthKey: $monthKey, context: $context);
		}

		return ($own + $childrenSum);

	}//end actualAmount()

	/**
	 * Find the `BudgetLine` in the context bundle whose `ledgerGroupId`
	 * resolves (by id or slug) to this LedgerGroup index, if any.
	 *
	 * @param integer $ledgerGroupIndex The LedgerGroup's index in `$context['ledgerGroupEntries']`.
	 * @param array<string,mixed> $context The context bundle.
	 *
	 * @return array<string,mixed>|null The matching BudgetLine, or null when none exists.
	 */
	private function ownBudgetLine(int $ledgerGroupIndex, array $context): ?array {
		foreach (($context['budgetLines'] ?? []) as $line) {
			$ref = (string)($line['ledgerGroupId'] ?? '');
			if ($ref === '') {
				continue;
			}

			$refIndex = ($context['ledgerGroupKeyToIndex'][$ref] ?? null);
			if ($refIndex === $ledgerGroupIndex) {
				return $line;
			}
		}

		return null;

	}//end ownBudgetLine()

	/**
	 * Resolve a stable lookup key (preferring id, falling back to slug) for
	 * a LedgerGroup entry by its index — used to recurse into
	 * `budgetedAmount()`/`actualAmount()`, which key by id-or-slug string.
	 *
	 * @param integer $index The entry's index in `$context['ledgerGroupEntries']`.
	 * @param array<string,mixed> $context The context bundle.
	 *
	 * @return string|null The entry's id or slug, or null when neither is set.
	 */
	private function keyForIndex(int $index, array $context): ?string {
		$entry = ($context['ledgerGroupEntries'][$index] ?? null);
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

	}//end keyForIndex()

	/**
	 * Resolve the `monthNNAmount` field name for a 1-12 calendar month.
	 *
	 * @param integer $monthNumber The calendar month, 1-12.
	 *
	 * @return string The field name; `month01Amount` for any out-of-range input.
	 */
	private function monthField(int $monthNumber): string {
		$index = ($monthNumber - 1);
		if ($index < 0 || $index > 11) {
			return self::MONTH_FIELDS[0];
		}

		return self::MONTH_FIELDS[$index];

	}//end monthField()
}//end class
