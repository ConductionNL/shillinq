<?php

/**
 * Fake KnownCostScheduleExpanderInterface implementation for
 * BudgetScenarioEvaluator tests.
 *
 * This fake implements the same interface as the real
 * `KnownCostScheduleExpander` with a small, deterministic, hand-verifiable
 * arithmetic (never budget-known-costs's own frequency/CPI/exact-occurrence-date
 * rules) so `BudgetScenarioEvaluatorTest` can assert on
 * `BudgetScenarioEvaluator`'s OWN logic (which months a modifier affects, which
 * LedgerGroup it lands in, that base is left unmutated) independent of that
 * arithmetic.
 *
 * ⚠️ A fake's job is to differ in ARITHMETIC, never in SHAPE. This one used to
 * return a flat `["01" => cents, …]` map because it was written against the
 * interface's docblock at a time when the real class was on an unmerged branch
 * and could not be consulted. The real class returns
 * `['kind' => 'amounts', 'monthlyCents' => [...]]`. Every evaluator test
 * therefore passed against a shape production never produced, while the real
 * code path silently read twelve missing keys and treated the whole feature as
 * zero. Keep this return value pinned to the interface's tagged union.
 *
 * Rule: every in-scope month (MONTHLY frequency only, the only frequency
 * these tests use) within `[validFrom, validTo] ∩ fiscalYear` books
 * `standardAmount` (rounded to the nearest cent, ×100 for EUR-to-cents)
 * once. A month outside that window books 0. `validTo: null` means every
 * month of `fiscalYear` from `validFrom` onward is in scope.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Support;

use OCA\Shillinq\Service\KnownCostScheduleExpanderInterface;

/**
 * Deterministic MONTHLY-only fake expander.
 */
final class FakeKnownCostScheduleExpander implements KnownCostScheduleExpanderInterface {
	/**
	 * Every `(recurId, fiscalYear)` pair this fake was asked to expand, in
	 * call order — lets a test assert the evaluator called the shared
	 * expander for BOTH the hypothetical and the real row (REQ-BSC-006).
	 *
	 * @var list<array{recurId:string,validFrom:?string,validTo:?string,standardAmount:mixed}>
	 */
	public array $calls = [];

	/**
	 * Expand a CashflowRecurring-shaped row's monthly amounts, per this
	 * fake's own MONTHLY-only rule (see class docblock).
	 *
	 * @param array<string,mixed> $recurring A CashflowRecurring-shaped array.
	 * @param int $fiscalYear The fiscal year to expand into.
	 * @param array<string,mixed>|null $contract Unused by this fake.
	 *
	 * @return array{kind:string,monthlyCents?:array<int|string,int>} The tagged
	 *         union the real class returns — see the interface docblock.
	 */
	public function expand(array $recurring, int $fiscalYear, ?array $contract): array {
		$this->calls[] = [
			'recurId' => (string)($recurring['recurId'] ?? ''),
			'validFrom' => ($recurring['validFrom'] ?? null),
			'validTo' => ($recurring['validTo'] ?? null),
			'standardAmount' => ($recurring['standardAmount'] ?? null),
		];

		// REQ-BKC-003, mirroring the real class: a CPI-indexed row with no rate
		// has no computable schedule. Previously absent from this fake, so no
		// evaluator test ever exercised the branch.
		if ((string)($recurring['indexationRule'] ?? 'FIXED') === 'CPI_PAST_YEAR'
			&& ($recurring['cpiRatePercent'] ?? null) === null
		) {
			return ['kind' => 'needsOperatorInput'];
		}

		$monthly = [];
		for ($month = 1; $month <= 12; $month++) {
			$monthly[str_pad((string)$month, 2, '0', STR_PAD_LEFT)] = 0;
		}

		$validFrom = (string)($recurring['validFrom'] ?? '');
		if ($validFrom === '') {
			return ['kind' => 'amounts', 'monthlyCents' => $monthly];
		}

		$validTo = null;
		if (($recurring['validTo'] ?? null) !== null) {
			$validTo = (string)$recurring['validTo'];
		}

		$standardAmount = (float)($recurring['standardAmount'] ?? 0);
		$cents = (int)round($standardAmount * 100);

		for ($month = 1; $month <= 12; $month++) {
			$monthStart = sprintf('%04d-%02d-01', $fiscalYear, $month);
			if ($monthStart < substr($validFrom, 0, 10)) {
				continue;
			}

			if ($validTo !== null && substr($validTo, 0, 10) < $monthStart) {
				continue;
			}

			$monthly[str_pad((string)$month, 2, '0', STR_PAD_LEFT)] = $cents;
		}

		return ['kind' => 'amounts', 'monthlyCents' => $monthly];

	}//end expand()
}//end class
