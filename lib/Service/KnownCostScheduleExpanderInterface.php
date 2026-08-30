<?php

/**
 * Known-cost schedule-expander port.
 *
 * `budget-scenarios` (REQ-BSC-006) MUST evaluate a `RECURRING_END`/
 * `RECURRING_AMOUNT_CHANGE` modifier by calling `budget-known-costs`'s own
 * pure `KnownCostScheduleExpander::expand()` — never a second, independent
 * schedule-expansion arithmetic (`openspec/changes/budget-scenarios/
 * design.md` §6a). That class lives on the sibling branch
 * `feat/budget-known-costs` (PR #967), not on this change's own base
 * (`feat/budget-core-schema`), so it does not exist in this checkout's
 * dependency tree at implementation time.
 *
 * This interface exists SOLELY as the integration seam: it restates
 * `budget-known-costs design.md` §6's own stated public surface
 * (`expand(recurring, fiscalYear, contract)`) so `BudgetScenarioEvaluator` (lib/Service/
 * BudgetScenarioEvaluator.php) can be built, DI-wired, and fully unit-tested
 * today against a fake implementing this interface, with ZERO schedule-math
 * reimplemented here — every arithmetic rule (frequency → months-in-scope,
 * validFrom/validTo bounding, CPI indexation, the WEEKLY/FORTNIGHTLY exact
 * occurrence-date enumeration) stays entirely `budget-known-costs`'s own,
 * undeclared and unduplicated by this interface.
 *
 * ## Integration point (once `budget-known-costs` lands)
 *
 * `budget-known-costs`'s own `KnownCostScheduleExpander` class MUST declare
 * `implements \OCA\Shillinq\Service\KnownCostScheduleExpanderInterface`, and
 *
 * ⚠️ The signature did NOT "already match this interface exactly", which is
 * what this docblock used to claim. This interface declared
 * `@return array<string,int>` ("01".."12" => cents), restating `design.md` §6.
 * The concrete class has always returned the TAGGED UNION below, because
 * REQ-BKC-003 needs a way to say "CPI rate missing, ask the operator" that a
 * flat month map has no room for. Nothing caught the divergence: the class did
 * not declare `implements`, so no compatibility check ran, and
 * {@see \OCA\Shillinq\Tests\Unit\Service\Support\FakeKnownCostScheduleExpander}
 * was written against THIS docblock rather than against the class — so the
 * evaluator's whole unit suite exercised a shape production never produces.
 *
 * The cost was silent and total: `BudgetScenarioEvaluator` read `$monthly["01"]`
 * off `['kind' => ..., 'monthlyCents' => [...]]`, every lookup missed, `?? 0`
 * absorbed it, and both `RECURRING_END` and `RECURRING_AMOUNT_CHANGE` computed
 * 0 − 0 for all twelve months. Those two modifier kinds did nothing at all,
 * with green tests. Adding `implements` is what let PHPStan finally report it
 * (`method.childReturnType`).
 *
 * The class is the authority here, not this docblock: the union is the designed
 * contract, so the interface has been corrected to it and the evaluator taught
 * to unwrap it. And
 * `lib/AppInfo/Application.php`'s `registerService(
 * KnownCostScheduleExpanderInterface::class, ...)` binding (registered by
 * this change, `budget-scenarios`) resolves the interface to that concrete
 * class via `class_exists()` at container-resolution time — see that
 * registration's own docblock for why it fails LOUD (throws) rather than
 * silently when the concrete class is still absent, instead of a permanent
 * `class_exists()` stub that would make a missing integration look like a
 * passing one.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Port for `budget-known-costs`'s pure `CashflowRecurring` schedule expander.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 */
interface KnownCostScheduleExpanderInterface {
	/**
	 * Expand one `CashflowRecurring`-shaped row into its monthly cent
	 * amounts for one fiscal year.
	 *
	 * @param array<string,mixed> $recurring A `CashflowRecurring`-shaped array (recurId, frequency,
	 *                                       standardAmount, validFrom, validTo, dagFromMonth,
	 *                                       monthOfYear, indexationRule, cpiRatePercent, …) — either a
	 *                                       real row or, for `budget-scenarios`' own hypothetical
	 *                                       evaluation, an in-memory copy with `validTo`/`standardAmount`
	 *                                       overridden, never a mutation of the real row.
	 * @param int $fiscalYear The fiscal year to expand into.
	 * @param array<string,mixed>|null $contract A `Contract`-shaped array when `recurring.contractReference`
	 *                                           is set, or null.
	 *
	 * @return array{kind:string,monthlyCents?:array<int|string,int>} Either
	 *         `['kind' => 'amounts', 'monthlyCents' => ["01" => cents, …, "12" => cents]]`,
	 *         or `['kind' => 'needsOperatorInput']` when `indexationRule` is
	 *         `CPI_PAST_YEAR` and `cpiRatePercent` is null (REQ-BKC-003) — the
	 *         schedule is unknowable until the operator supplies the rate, which
	 *         is why this is a tagged union and not a flat month map. Callers MUST
	 *         branch on `kind`; treating the result as a month map yields twelve
	 *         silent misses, not an error.
	 *
	 *         `monthlyCents` is keyed `int|string`, not `string`: the keys are
	 *         built from `['01' … '12']`, and PHP coerces a CANONICAL numeric
	 *         string array key to an integer, so `"01".."09"` stay strings while
	 *         `"10"`, `"11"`, `"12"` become ints. Lookups by `"10"` still work
	 *         (they coerce identically); only code testing `is_string()` on the
	 *         key is misled.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
	 */
	public function expand(array $recurring, int $fiscalYear, ?array $contract): array;
}//end interface
