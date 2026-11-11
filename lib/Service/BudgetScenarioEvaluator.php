<?php

/**
 * Budget Scenario Evaluator
 *
 * Pure, non-destructive (REQ-BSC-005): computes a side-by-side
 * `(ledgerGroupId, month) => {base, scenario, delta}` comparison for one
 * `BudgetScenario`, never writing to any `BudgetLine` or `CashflowRecurring`
 * row. Generalises `BegrotingswijzigingStacker::currentStand()`'s exact
 * "base + stacked dated deltas, no I/O" shape
 * (`openspec/changes/budget-scenarios/design.md` §6a) to `BudgetLine`'s own
 * `LedgerGroup` x month grain. No constructor dependency on
 * `ObjectServiceInterface` or any OpenRegister type — mirrors
 * `BegrotingswijzigingStacker`'s and `BudgetVsActualsCalculator`'s own "reads
 * NOTHING" contract; {@see BudgetScenarioReader} is the only class that
 * talks to the store.
 *
 * ## `RECURRING_END`/`RECURRING_AMOUNT_CHANGE` — the shared expander, called
 * twice (or three times), never a second arithmetic (REQ-BSC-006)
 *
 * Every monthly amount for a `CashflowRecurring` row — real or hypothetical
 * — is produced by exactly one call: {@see KnownCostScheduleExpanderInterface
 * ::expand()}. This class NEVER computes a schedule itself; it only slices
 * the INPUT row (`validTo`/`validFrom`/`standardAmount`) before delegating:
 *
 *   - `RECURRING_END`: one hypothetical row with `validTo` capped at
 *     `effectiveDate` (never later than the row's own real `validTo`, if
 *     already set) — one `expand()` call for the hypothetical, one for the
 *     unmodified real row.
 *   - `RECURRING_AMOUNT_CHANGE`: the real amount still applies strictly
 *     BEFORE `effectiveDate` (design.md §4b) — a single `expand()` call
 *     cannot express "amount X until date D, amount Y from D forward" for
 *     one row, so this class slices the window into two rows — one capped
 *     at the day before `effectiveDate` at the OLD `standardAmount`, one
 *     starting at `effectiveDate` at `newStandardAmount` (both derived
 *     directly from the real row's own other fields, unmodified) — expands
 *     BOTH, and sums their monthly results. Three `expand()` calls total
 *     (before-slice + after-slice + the unmodified real row); every cent of
 *     arithmetic still comes from the shared expander.
 *
 * `targetRecurId` is resolved to a `LedgerGroup` via the row's own
 * `accountNumberExpense` matched against each `LedgerGroup`'s
 * `accountRanges`/`includedAccountNumbers`/`excludedAccountNumbers`
 * (`budget-core-schema design.md` §3a's already-published membership rule —
 * `accountNumberExpense` is an EXISTING `CashflowRecurring` field, already
 * present on this branch's schema; `budget-known-costs` reuses it rather
 * than adding it, per that change's own design.md §1a table). This is a
 * point-membership test (does one account number fall in one group's
 * ranges), not the full per-group member ENUMERATION
 * `BudgetVsActualsReader::resolveMembers()` performs for GL-actuals
 * bucketing — a different, narrower use of the same published rule, not a
 * copy of that reader.
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pure base-vs-scenario evaluator over a {@see BudgetScenarioReader} context
 * bundle (REQ-BSC-005, REQ-BSC-006, REQ-BSC-007).
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
 */
class BudgetScenarioEvaluator {
	/**
	 * The 12 monthly amount field names, in calendar order — matches
	 * BudgetLine's own month01Amount..month12Amount fields.
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
	 * The hypothetical-schedule arithmetic for `RECURRING_*` modifiers.
	 *
	 * Built here rather than injected so the constructor signature stays
	 * `(expander, logger)` for every existing caller and DI registration, and so
	 * it provably shares the ONE expander instance this evaluator was handed —
	 * which is what makes `design.md` §6a's "never a second arithmetic" rule
	 * observable through a single call log in tests.
	 *
	 * @var RecurringModifierExpander
	 */
	private readonly RecurringModifierExpander $recurringExpander;

	/**
	 * Construct the evaluator.
	 *
	 * @param KnownCostScheduleExpanderInterface $scheduleExpander The pure `budget-known-costs`
	 *                                                             schedule-expander port (see this
	 *                                                             interface's own docblock for the
	 *                                                             `budget-known-costs` integration
	 *                                                             point).
	 * @param LoggerInterface $logger Logger for skipped/unresolved-target diagnostics.
	 */
	public function __construct(
		KnownCostScheduleExpanderInterface $scheduleExpander,
		private readonly LoggerInterface $logger,
	) {
		$this->recurringExpander = new RecurringModifierExpander(scheduleExpander: $scheduleExpander);

	}//end __construct()

	/**
	 * Evaluate one scenario: base vs. scenario vs. delta, per
	 * `(ledgerGroupId, month)` cell, for one fiscal year.
	 *
	 * @param list<array<string,mixed>> $baseBudgetLines Real BudgetLine rows already scoped to the AnnualBudget(s) in view.
	 * @param list<array<string,mixed>> $ledgerGroups Real LedgerGroup rows for the administration.
	 * @param list<array<string,mixed>> $cashflowRecurringRows Real CashflowRecurring rows for the administration.
	 * @param list<array<string,mixed>> $modifiers This scenario's own BudgetScenarioModifier rows.
	 * @param int $fiscalYear The fiscal year to evaluate.
	 *
	 * @return array<string,array{month:string,ledgerGroupId:string,base:int,scenario:int,delta:int}> Keyed by `"{ledgerGroupId}:{YYYY-MM}"`.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
	 */
	public function evaluate(
		array $baseBudgetLines,
		array $ledgerGroups,
		array $cashflowRecurringRows,
		array $modifiers,
		int $fiscalYear
	): array {
		$index = $this->buildLedgerGroupIndex(ledgerGroups: $ledgerGroups);
		$run = new BudgetScenarioRun(
			index: $index,
			recurringByRecurId: $this->indexByRecurId(rows: $cashflowRecurringRows),
			fiscalYear: $fiscalYear
		);

		// "Own" monthly sums per group, straight from the real BudgetLines
		// (step 1: sum every BudgetLine targeting a node, regardless of
		// source — design.md §6a step 1). Also tracks which groups have at
		// least one own BudgetLine, so a group with an explicit (possibly
		// zero) own value is not silently overridden by a children rollup.
		$base = $this->ownMonthlySums(budgetLines: $baseBudgetLines, index: $index);

		// Modifier deltas, per group per month — added on top of the base
		// own sums to form each group's own scenario contribution before rollup.
		$modifierDeltas = $this->modifierDeltasByGroup(modifiers: $modifiers, run: $run);
		$scenario = self::withDeltas(own: $base, deltas: $modifierDeltas);

		return $this->scenarioCells(sums: ['base' => $base, 'scenario' => $scenario, 'index' => $index], run: $run);

	}//end evaluate()

	/**
	 * Own monthly sums with the modifier deltas added; every group a delta
	 * touches counts as having an own value.
	 *
	 * @param array{0: array<int,array<string,int>>, 1: array<int,bool>} $own    Own sums and has-own flags.
	 * @param array<int,array<string,int>>                               $deltas Deltas per group per month.
	 *
	 * @return array{0: array<int,array<string,int>>, 1: array<int,bool>}
	 */
	private static function withDeltas(array $own, array $deltas): array {
		[$sums, $hasOwn] = $own;
		foreach ($deltas as $groupIndex => $monthly) {
			foreach ($monthly as $month => $cents) {
				$sums[$groupIndex][$month] = (($sums[$groupIndex][$month] ?? 0) + $cents);
				$hasOwn[$groupIndex] = true;
			}
		}

		return [$sums, $hasOwn];

	}//end withDeltas()

	/**
	 * One base/scenario/delta cell per keyed group per month.
	 *
	 * @param array<string,mixed> $sums Base and scenario own sums, and the index.
	 * @param BudgetScenarioRun   $run  The run.
	 *
	 * @return array<string,array<string,mixed>> Cells keyed `<group>:<yyyy-mm>`.
	 */
	private function scenarioCells(array $sums, BudgetScenarioRun $run): array {
		$result = [];
		foreach (array_keys($run->index['entries']) as $groupIndex) {
			$groupKey = $this->keyForIndex(index: $groupIndex, entries: $run->index['entries']);
			if ($groupKey === null) {
				continue;
			}

			foreach (self::MONTH_FIELDS as $month) {
				$cell = $this->scenarioCell(
					cellAt: ['groupIndex' => $groupIndex, 'groupKey' => $groupKey, 'month' => $month, 'fiscalYear' => $run->fiscalYear],
					sums: $sums
				);
				$result[$groupKey . ':' . $cell['month']] = $cell;
			}
		}

		return $result;

	}//end scenarioCells()

	/**
	 * The cell of one group in one month.
	 *
	 * @param array<string,mixed> $cellAt groupIndex, groupKey, month field and fiscal year.
	 * @param array<string,mixed> $sums Base and scenario own sums, and the index.
	 *
	 * @return array<string,mixed>
	 */
	private function scenarioCell(array $cellAt, array $sums): array {
		$base = $this->resolveNodeAmount(
			groupIndex: $cellAt['groupIndex'],
			month: $cellAt['month'],
			own: $sums['base'][0],
			hasOwn: $sums['base'][1],
			index: $sums['index']
		);
		$scenario = $this->resolveNodeAmount(
			groupIndex: $cellAt['groupIndex'],
			month: $cellAt['month'],
			own: $sums['scenario'][0],
			hasOwn: $sums['scenario'][1],
			index: $sums['index']
		);

		return [
			'month' => $cellAt['fiscalYear'] . '-' . substr($cellAt['month'], 5, 2),
			'ledgerGroupId' => $cellAt['groupKey'],
			'base' => $base,
			'scenario' => $scenario,
			'delta' => ($scenario - $base),
		];

	}//end scenarioCell()

	/**
	 * Own (directly-targeted) monthly sums per LedgerGroup index, keyed by
	 * `monthNNAmount` field — sum of every BudgetLine whose `ledgerGroupId`
	 * resolves (by id or slug) to that group, regardless of `source`
	 * (design.md §6a step 1).
	 *
	 * @param list<array<string,mixed>> $budgetLines The real BudgetLine rows.
	 * @param array{entries:list<array<string,mixed>>,keyToIndex:array<string,int>,childrenByIndex:array<int,list<int>>} $index The LedgerGroup index.
	 *
	 * @return array{0: array<int,array<string,int>>, 1: array<int,bool>} [ownSums keyed by groupIndex then month field, hasOwnLine keyed by groupIndex].
	 */
	private function ownMonthlySums(array $budgetLines, array $index): array {
		$sums = [];
		$hasOwn = [];

		foreach ($budgetLines as $line) {
			$ref = (string)($line['ledgerGroupId'] ?? '');
			if ($ref === '') {
				continue;
			}

			$groupIndex = ($index['keyToIndex'][$ref] ?? null);
			if ($groupIndex === null) {
				continue;
			}

			$hasOwn[$groupIndex] = true;
			foreach (self::MONTH_FIELDS as $month) {
				$sums[$groupIndex][$month] = (($sums[$groupIndex][$month] ?? 0) + (int)($line[$month] ?? 0));
			}
		}

		return [$sums, $hasOwn];

	}//end ownMonthlySums()

	/**
	 * Resolve one LedgerGroup's final displayed amount for one month: its
	 * own resolved value if one exists, otherwise the recursive sum of its
	 * children's own resolved values (budget-core-schema design.md §3d,
	 * reused identically for both base and scenario — design.md §6b).
	 *
	 * @param int $groupIndex The LedgerGroup's index in `$index['entries']`.
	 * @param string $month The `monthNNAmount` field name.
	 * @param array<int,array<string,int>> $own Own sums keyed by groupIndex then month field.
	 * @param array<int,bool> $hasOwn Which groupIndexes carry an own contribution.
	 * @param array{entries:list<array<string,mixed>>,keyToIndex:array<string,int>,childrenByIndex:array<int,list<int>>} $index The LedgerGroup index.
	 *
	 * @return int The resolved amount, in EUR cents.
	 */
	private function resolveNodeAmount(int $groupIndex, string $month, array $own, array $hasOwn, array $index): int {
		if (($hasOwn[$groupIndex] ?? false) === true) {
			return (int)($own[$groupIndex][$month] ?? 0);
		}

		$sum = 0;
		foreach (($index['childrenByIndex'][$groupIndex] ?? []) as $childIndex) {
			$sum += $this->resolveNodeAmount(groupIndex: $childIndex, month: $month, own: $own, hasOwn: $hasOwn, index: $index);
		}

		return $sum;

	}//end resolveNodeAmount()

	/**
	 * Modifier deltas per LedgerGroup index per month field, in modifier order.
	 *
	 * @param array<int,array<string,mixed>> $modifiers The scenario's modifiers.
	 * @param BudgetScenarioRun              $run       The run.
	 *
	 * @return array<int,array<string,int>>
	 */
	private function modifierDeltasByGroup(array $modifiers, BudgetScenarioRun $run): array {
		$deltas = [];

		foreach ($modifiers as $modifier) {
			$type = (string)($modifier['modifierType'] ?? '');

			if ($type === 'RECURRING_END' || $type === 'RECURRING_AMOUNT_CHANGE') {
				$deltas = self::addDeltas(deltas: $deltas, more: $this->recurringModifierDeltas(modifier: $modifier, run: $run));
				continue;
			}

			if ($type === 'LEDGER_AMOUNT_DELTA') {
				$this->applyLedgerAmountDelta(modifier: $modifier, index: $run->index, fiscalYear: $run->fiscalYear, deltas: $deltas);
			}
		}

		return $deltas;

	}//end modifierDeltasByGroup()

	/**
	 * Add one modifier's deltas onto the running deltas.
	 *
	 * @param array<int,array<string,int>> $deltas The running deltas.
	 * @param array<int,array<string,int>> $more   One modifier's deltas.
	 *
	 * @return array<int,array<string,int>>
	 */
	private static function addDeltas(array $deltas, array $more): array {
		foreach ($more as $groupIndex => $monthly) {
			foreach ($monthly as $monthField => $delta) {
				$deltas[$groupIndex][$monthField] = (($deltas[$groupIndex][$monthField] ?? 0) + $delta);
			}
		}

		return $deltas;

	}//end addDeltas()

	/**
	 * The deltas of one `RECURRING_*` modifier (hypothetical minus real
	 * schedule) on the LedgerGroup its row's expense account resolves to;
	 * none when the target cannot be resolved or expanded (each logged).
	 *
	 * @param array<string,mixed> $modifier The modifier.
	 * @param BudgetScenarioRun   $run      The run.
	 *
	 * @return array<int,array<string,int>>
	 */
	private function recurringModifierDeltas(array $modifier, BudgetScenarioRun $run): array {
		$groupIndex = $this->recurringTargetGroup(modifier: $modifier, run: $run);
		if ($groupIndex === null) {
			return [];
		}

		$monthlyDeltas = $this->expandRecurringModifier(modifier: $modifier, run: $run);
		if ($monthlyDeltas === null) {
			return [];
		}

		$deltas = [];
		foreach (self::MONTH_FIELDS as $index2 => $monthField) {
			$delta = ($monthlyDeltas[str_pad((string)($index2 + 1), 2, '0', STR_PAD_LEFT)] ?? 0);
			if ($delta !== 0) {
				$deltas[$groupIndex][$monthField] = $delta;
			}
		}

		return $deltas;

	}//end recurringModifierDeltas()

	/**
	 * The LedgerGroup index a `RECURRING_*` modifier's real row resolves to,
	 * or null (logged) for an unknown recurId or an unresolved account.
	 *
	 * @param array<string,mixed> $modifier The modifier.
	 * @param BudgetScenarioRun   $run      The run.
	 *
	 * @return int|null
	 */
	private function recurringTargetGroup(array $modifier, BudgetScenarioRun $run): ?int {
		$recurId = (string)($modifier['targetRecurId'] ?? '');
		$real = ($run->recurringByRecurId[$recurId] ?? null);
		if ($real === null) {
			$this->logger->info(
				'BudgetScenarioEvaluator: modifier targets an unknown CashflowRecurring recurId — skipping',
				['targetRecurId' => $recurId, 'modifierType' => (string)($modifier['modifierType'] ?? '')]
			);
			return null;
		}

		$accountNumber = (string)($real['accountNumberExpense'] ?? '');
		$groupIndex = $this->resolveLedgerGroupIndexForAccount(accountNumber: $accountNumber, index: $run->index);
		if ($groupIndex === null) {
			$this->logger->info(
				'BudgetScenarioEvaluator: modifier targets a CashflowRecurring row whose '
				. 'accountNumberExpense resolves to no LedgerGroup — skipping',
				['targetRecurId' => $recurId, 'accountNumberExpense' => $accountNumber]
			);
		}

		return $groupIndex;

	}//end recurringTargetGroup()

	/**
	 * The expander's monthly deltas for a resolved `RECURRING_*` modifier, or
	 * null (logged) when the expander fails or needs operator input.
	 *
	 * REQ-BKC-003: the expander answers `needsOperatorInput` when the row is
	 * CPI-indexed and no rate has been supplied. There is no schedule to
	 * difference against in that case, and inventing one (treating it as all
	 * zeros) would post a delta equal to the whole hypothetical amount.
	 *
	 * @param array<string,mixed> $modifier The modifier (its recurId is known).
	 * @param BudgetScenarioRun   $run      The run.
	 *
	 * @return array<string,int>|null Deltas by two-digit month, or null.
	 */
	private function expandRecurringModifier(array $modifier, BudgetScenarioRun $run): ?array {
		$recurId = (string)($modifier['targetRecurId'] ?? '');
		$type = (string)($modifier['modifierType'] ?? '');
		try {
			$monthlyDeltas = $this->recurringExpander->monthlyDeltas(
				real: $run->recurringByRecurId[$recurId],
				modifier: $modifier,
				type: $type,
				fiscalYear: $run->fiscalYear
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetScenarioEvaluator: KnownCostScheduleExpander::expand() failed — skipping this modifier',
				['targetRecurId' => $recurId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($monthlyDeltas === null) {
			$this->logger->info(
				'BudgetScenarioEvaluator: the expander needs operator input (missing CPI rate) '
				. 'for this CashflowRecurring row — skipping this modifier',
				['targetRecurId' => $recurId, 'modifierType' => $type]
			);
		}

		return $monthlyDeltas;

	}//end expandRecurringModifier()

	/**
	 * Apply one `LEDGER_AMOUNT_DELTA` modifier into `$deltas` (passed by
	 * reference) — a signed one-off adjustment to `effectiveDate`'s own
	 * calendar month only (design.md §4b).
	 *
	 * @param array<string,mixed> $modifier The modifier row.
	 * @param array{entries:list<array<string,mixed>>,keyToIndex:array<string,int>,childrenByIndex:array<int,list<int>>} $index The LedgerGroup index.
	 * @param int $fiscalYear The fiscal year being evaluated.
	 * @param array<int,array<string,int>> $deltas The accumulating delta map (mutated in place).
	 *
	 * @return void
	 */
	private function applyLedgerAmountDelta(array $modifier, array $index, int $fiscalYear, array &$deltas): void {
		$effectiveDate = (string)($modifier['effectiveDate'] ?? '');
		if (strlen($effectiveDate) < 7) {
			return;
		}

		$effectiveYear = (int)substr($effectiveDate, 0, 4);
		if ($effectiveYear !== $fiscalYear) {
			// Outside the fiscal year being evaluated — no contribution.
			return;
		}

		$targetLedgerGroupId = (string)($modifier['targetLedgerGroupId'] ?? '');
		$groupIndex = ($index['keyToIndex'][$targetLedgerGroupId] ?? null);
		if ($groupIndex === null) {
			$this->logger->info(
				'BudgetScenarioEvaluator: LEDGER_AMOUNT_DELTA targets an unknown LedgerGroup — skipping',
				['targetLedgerGroupId' => $targetLedgerGroupId]
			);
			return;
		}

		$monthNumber = (int)substr($effectiveDate, 5, 2);
		$monthField = $this->monthField(monthNumber: $monthNumber);
		$amountDeltaCents = (int)($modifier['amountDeltaCents'] ?? 0);

		$deltas[$groupIndex][$monthField] = (($deltas[$groupIndex][$monthField] ?? 0) + $amountDeltaCents);

	}//end applyLedgerAmountDelta()

	/**
	 * Resolve which LedgerGroup index one account number belongs to, via
	 * `accountRanges`/`includedAccountNumbers` minus `excludedAccountNumbers`
	 * (budget-core-schema design.md §3a). First match wins; ranges are
	 * expected to be disjoint per that rule's own convention.
	 *
	 * @param string $accountNumber The account number to resolve.
	 * @param array{entries:list<array<string,mixed>>,keyToIndex:array<string,int>,childrenByIndex:array<int,list<int>>} $index The LedgerGroup index.
	 *
	 * @return int|null The matching LedgerGroup's index, or null when none matches.
	 */
	private function resolveLedgerGroupIndexForAccount(string $accountNumber, array $index): ?int {
		if ($accountNumber === '' || is_numeric($accountNumber) === false) {
			return null;
		}

		foreach ($index['entries'] as $groupIndex => $entry) {
			$excluded = array_map('strval', ($entry['excludedAccountNumbers'] ?? []));
			if (in_array($accountNumber, $excluded, true) === true) {
				continue;
			}

			$included = array_map('strval', ($entry['includedAccountNumbers'] ?? []));
			if (in_array($accountNumber, $included, true) === true) {
				return $groupIndex;
			}

			foreach (($entry['accountRanges'] ?? []) as $range) {
				if ($this->inRange(accountNumber: $accountNumber, range: $range) === true) {
					return $groupIndex;
				}
			}
		}

		return null;

	}//end resolveLedgerGroupIndexForAccount()

	/**
	 * Whether an account number falls inside one range pair, compared
	 * numerically.
	 *
	 * @param string $accountNumber The account number to test.
	 * @param array{from?:string,to?:string} $range The range pair.
	 *
	 * @return bool True when the account number is inside the range.
	 */
	private function inRange(string $accountNumber, array $range): bool {
		$from = (string)($range['from'] ?? '');
		$to = (string)($range['to'] ?? '');
		if ($from === '' || $to === '' || is_numeric($from) === false || is_numeric($to) === false) {
			return false;
		}

		$value = (int)$accountNumber;
		return ($value >= (int)$from && $value <= (int)$to);

	}//end inRange()

	/**
	 * Index CashflowRecurring rows by their `recurId`.
	 *
	 * @param list<array<string,mixed>> $rows The CashflowRecurring rows.
	 *
	 * @return array<string,array<string,mixed>> recurId => row.
	 */
	private function indexByRecurId(array $rows): array {
		$byRecurId = [];
		foreach ($rows as $row) {
			$recurId = (string)($row['recurId'] ?? '');
			if ($recurId !== '') {
				$byRecurId[$recurId] = $row;
			}
		}

		return $byRecurId;

	}//end indexByRecurId()

	/**
	 * Build the dual-keyed LedgerGroup lookup index (by id and by
	 * `@self.slug`), with parent/child relationships resolved — the same
	 * dual-key convention {@see BudgetVsActualsReader::buildLedgerGroupIndex()}
	 * already establishes, reimplemented here without accounts (this class
	 * needs point membership only, never full member enumeration).
	 *
	 * @param list<array<string,mixed>> $ledgerGroups The LedgerGroup rows.
	 *
	 * @return array{entries:list<array<string,mixed>>,keyToIndex:array<string,int>,childrenByIndex:array<int,list<int>>}
	 */
	private function buildLedgerGroupIndex(array $ledgerGroups): array {
		$entries = [];
		$keyToIndex = [];

		foreach ($ledgerGroups as $row) {
			$id = (string)($row['@self']['id'] ?? $row['id'] ?? '');
			$slug = (string)($row['@self']['slug'] ?? $row['slug'] ?? '');
			$index = count($entries);

			$parentRef = null;
			if (($row['parentLedgerGroupId'] ?? null) !== null) {
				$parentRef = (string)$row['parentLedgerGroupId'];
			}

			$accountRanges = [];
			if (is_array($row['accountRanges'] ?? null) === true) {
				$accountRanges = $row['accountRanges'];
			}

			$includedAccountNumbers = [];
			if (is_array($row['includedAccountNumbers'] ?? null) === true) {
				$includedAccountNumbers = $row['includedAccountNumbers'];
			}

			$excludedAccountNumbers = [];
			if (is_array($row['excludedAccountNumbers'] ?? null) === true) {
				$excludedAccountNumbers = $row['excludedAccountNumbers'];
			}

			$entries[] = [
				'id' => $id,
				'slug' => $slug,
				'parentRef' => $parentRef,
				'accountRanges' => $accountRanges,
				'includedAccountNumbers' => $includedAccountNumbers,
				'excludedAccountNumbers' => $excludedAccountNumbers,
			];

			if ($id !== '') {
				$keyToIndex[$id] = $index;
			}

			if ($slug !== '' && $slug !== $id) {
				$keyToIndex[$slug] = $index;
			}
		}

		$childrenByIndex = [];
		foreach ($entries as $index => $entry) {
			$parentRef = $entry['parentRef'];
			if ($parentRef === null || $parentRef === '') {
				continue;
			}

			$parentIndex = ($keyToIndex[$parentRef] ?? null);
			if ($parentIndex === null) {
				continue;
			}

			$childrenByIndex[$parentIndex][] = $index;
		}

		return ['entries' => $entries, 'keyToIndex' => $keyToIndex, 'childrenByIndex' => $childrenByIndex];

	}//end buildLedgerGroupIndex()

	/**
	 * Resolve a stable lookup key (preferring id, falling back to slug) for
	 * a LedgerGroup entry by its index.
	 *
	 * @param int $index The entry's index.
	 * @param list<array<string,mixed>> $entries The LedgerGroup entries.
	 *
	 * @return string|null The entry's id or slug, or null when neither is set.
	 */
	private function keyForIndex(int $index, array $entries): ?string {
		$entry = ($entries[$index] ?? null);
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
	 * @param int $monthNumber The calendar month, 1-12.
	 *
	 * @return string The field name; `month01Amount` for any out-of-range input.
	 */
	private function monthField(int $monthNumber): string {
		$fieldIndex = ($monthNumber - 1);
		if ($fieldIndex < 0 || $fieldIndex > 11) {
			return self::MONTH_FIELDS[0];
		}

		return self::MONTH_FIELDS[$fieldIndex];

	}//end monthField()

}//end class
