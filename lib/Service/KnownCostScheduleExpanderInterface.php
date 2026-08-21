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
 * `budget-known-costs design.md` §6's own stated public surface verbatim
 * (`expand(recurring, fiscalYear, contract): array<string,int>`, "01".."12"
 * keys, cents) so `BudgetScenarioEvaluator` (lib/Service/
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
 * `implements \OCA\Shillinq\Service\KnownCostScheduleExpanderInterface` (its
 * method signature already matches this interface exactly, by design — see
 * `openspec/changes/budget-known-costs/design.md` §6), and
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
	 * @return array<string,int> `"01".."12"` (two-digit calendar month) => EUR cents for that fiscal year.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
	 */
	public function expand(array $recurring, int $fiscalYear, ?array $contract): array;
}//end interface
