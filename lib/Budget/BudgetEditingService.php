<?php

/**
 * Budget Editing Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Types a budget into the grid, spreads a yearly amount, sets several years
 * side by side and starts next year's budget from this one
 * (planning-budget-editing, REQ-PBE-001 to REQ-PBE-003):
 *
 * - lines(): every ledger group of an annual budget with its twelve month
 *   amounts; a group whose lines are all derived (contract, recurring,
 *   projected, scenario) is read-only and names its source;
 * - saveCell(): one month of the group's manual line, created when absent,
 *   refused when the amount the person started from is no longer the stored
 *   one (a stale save);
 * - spread(): twelve equal months, the last taking the rounding remainder;
 * - multiYear(): ledger groups against the fiscal years that have an annual
 *   budget, from a year up to four ahead, each cell the sum of the months;
 * - startNextYear(): a draft annual budget for the next fiscal year with the
 *   manual lines of the chosen one, raised by a percentage.
 *
 * Amounts are EUR cents, as BudgetLine stores them.
 *
 * @category Budget
 * @package  OCA\Shillinq\Budget
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Budget;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\BudgetEditingServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\Shillinq\Kernel\Exception\BudgetEditRefusedException;

/**
 * Budget entry over AnnualBudget, BudgetLine and LedgerGroup.
 *
 * @spec openspec/specs/budget-grid-view/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 * @SuppressWarnings(PHPMD.TooManyMethods) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class BudgetEditingService implements BudgetEditingServiceInterface {
	/**
	 * The only source a person types.
	 *
	 * @var string
	 */
	public const SOURCE_MANUAL = 'manual';

	/**
	 * How many years ahead the multi-year view reaches.
	 *
	 * @var int
	 */
	public const YEARS_AHEAD = 4;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param RegisterLocationInterface        $registerLocation Where the register lives (slug + OpenRegister availability).
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocationInterface $registerLocation,
	) {

	}//end __construct()

	/**
	 * The ledger groups of an annual budget with their month amounts.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 *
	 * @return array{budget:array<string,mixed>,rows:list<array<string,mixed>>}
	 *
	 * @throws BudgetEditRefusedException When the budget is not this administration's.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	public function lines(string $administrationId, string $annualBudgetId): array {
		$budget = $this->budget(administrationId: $administrationId, annualBudgetId: $annualBudgetId);
		$linesByGroup = $this->linesByGroup(annualBudgetId: $annualBudgetId);

		$rows = [];
		foreach ($this->groups(administrationId: $administrationId) as $group) {
			$rows[] = $this->groupRow(group: $group, lines: ($linesByGroup[$group['id']] ?? []), budget: $budget);
		}

		return ['budget' => $budget, 'rows' => $rows];

	}//end lines()

	/**
	 * One ledger group's grid row: its manual line, or else the sum of its derived lines.
	 *
	 * @param array{id:string,code:string,name:string,depth:int} $group  The ledger group.
	 * @param list<array<string,mixed>>                          $lines  The group's lines in the budget.
	 * @param array<string,mixed>                                $budget The annual budget.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	private function groupRow(array $group, array $lines, array $budget): array {
		$manual = $this->manualLine(lines: $lines);
		$derived = array_values(array_filter($lines, static fn (array $line): bool => ($line['source'] ?? '') !== self::SOURCE_MANUAL));

		[$source, $months] = $this->sourceAndMonths(manual: $manual, derived: $derived);

		return [
			'ledgerGroupId' => $group['id'],
			'code'          => $group['code'],
			'name'          => $group['name'],
			'depth'         => $group['depth'],
			'lineId'        => ($manual['id'] ?? null),
			'source'        => $source,
			'editable'      => ($source === self::SOURCE_MANUAL && ($budget['state'] ?? 'draft') !== 'closed'),
			'months'        => $months,
			'total'         => array_sum($months),
		];
	}//end groupRow()

	/**
	 * The source and month amounts of a group's row.
	 *
	 * @param array<string,mixed>|null  $manual  The group's manual line, if any.
	 * @param list<array<string,mixed>> $derived The group's derived lines.
	 *
	 * @return array{0:string,1:array<int,int>}
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	private function sourceAndMonths(array|null $manual, array $derived): array {
		$source = self::SOURCE_MANUAL;
		$months = $this->emptyMonths();
		if ($manual !== null) {
			$months = $this->months(line: $manual);
		}

		if ($manual === null && $derived !== []) {
			$source = (string)$derived[0]['source'];
			$months = $this->sumMonths(lines: $derived);
		}

		return [$source, $months];
	}//end sourceAndMonths()

	/**
	 * Save one month of a ledger group's manual line.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 * @param string $ledgerGroupId    The ledger group.
	 * @param int    $month            1 to 12.
	 * @param int    $amount           The new amount in cents.
	 * @param int    $expected         The amount in cents the person started from.
	 *
	 * @return array<string,mixed> The row as stored.
	 *
	 * @throws BudgetEditRefusedException When the budget is closed, the line derived or the save stale.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	public function saveCell(string $administrationId, string $annualBudgetId, string $ledgerGroupId, int $month, int $amount, int $expected): array {
		if ($month < 1 || $month > 12) {
			throw new BudgetEditRefusedException(template: 'Choose a month from 1 to 12.');
		}

		$line = $this->writableLine(administrationId: $administrationId, annualBudgetId: $annualBudgetId, ledgerGroupId: $ledgerGroupId);
		$field = $this->monthField(month: $month);
		$stored = (int)($line[$field] ?? 0);
		if ($stored !== $expected) {
			throw new BudgetEditRefusedException(
				template: 'Someone else changed this amount to %1$s. The cell now shows their value.',
				parameters: [$this->euro(cents: $stored)]
			);
		}

		$line[$field] = $amount;
		return $this->store(line: $line);

	}//end saveCell()

	/**
	 * Spread a yearly amount over twelve months, the last taking the remainder.
	 *
	 * @param string          $administrationId The administration.
	 * @param string          $annualBudgetId   The annual budget.
	 * @param string          $ledgerGroupId    The ledger group.
	 * @param int             $yearly           The yearly amount in cents.
	 * @param array<int,int>  $expected         The twelve amounts the person started from.
	 *
	 * @return array<string,mixed> The row as stored.
	 *
	 * @throws BudgetEditRefusedException When the budget is closed, the line derived or the save stale.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
	 */
	public function spread(string $administrationId, string $annualBudgetId, string $ledgerGroupId, int $yearly, array $expected): array {
		$line = $this->writableLine(administrationId: $administrationId, annualBudgetId: $annualBudgetId, ledgerGroupId: $ledgerGroupId);
		if ($this->months(line: $line) !== array_map('intval', array_values($expected))) {
			throw new BudgetEditRefusedException(template: 'Someone else changed this row. It now shows their values.');
		}

		foreach (self::spreadAmounts(yearly: $yearly) as $index => $amount) {
			$line[$this->monthField(month: $index + 1)] = $amount;
		}

		return $this->store(line: $line);

	}//end spread()

	/**
	 * Twelve equal amounts in cents adding up to the yearly amount.
	 *
	 * @param int $yearly The yearly amount in cents.
	 *
	 * @return list<int>
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
	 */
	public static function spreadAmounts(int $yearly): array {
		$month = intdiv($yearly, 12);
		$amounts = array_fill(0, 12, $month);
		$amounts[11] = $yearly - ($month * 11);
		return $amounts;

	}//end spreadAmounts()

	/**
	 * Ledger groups against the fiscal years that have an annual budget, and
	 * every budget of the administration (newest year first) to choose from.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The first year shown.
	 *
	 * @return array{years:list<array{fiscalYear:int,annualBudgetId:string,name:string,state:string}>,rows:list<array<string,mixed>>,budgets:list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	public function multiYear(string $administrationId, int $fromYear): array {
		$budgets = $this->budgets(administrationId: $administrationId);
		$all = array_map(fn (array $budget): array => $this->budgetChoice(budget: $budget), $budgets);
		$years = $this->yearsShown(budgets: $budgets, fromYear: $fromYear);

		$totals = $this->yearTotals(years: $years);

		$rows = [];
		foreach ($this->groups(administrationId: $administrationId) as $group) {
			$rows[] = [
				'ledgerGroupId' => $group['id'],
				'code'          => $group['code'],
				'name'          => $group['name'],
				'depth'         => $group['depth'],
				'amounts'       => $this->yearAmounts(groupId: $group['id'], years: $years, totals: $totals),
			];
		}

		usort($all, static fn (array $a, array $b): int => [$b['fiscalYear'], $a['name']] <=> [$a['fiscalYear'], $b['name']]);
		return ['years' => $years, 'rows' => $rows, 'budgets' => $all];

	}//end multiYear()

	/**
	 * A budget as one of the choices of the multi-year view.
	 *
	 * @param array<string,mixed> $budget The annual budget.
	 *
	 * @return array{annualBudgetId:string,fiscalYear:int,name:string,state:string}
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	private function budgetChoice(array $budget): array {
		return [
			'annualBudgetId' => (string)$budget['id'],
			'fiscalYear'     => (int)($budget['fiscalYear'] ?? 0),
			'name'           => (string)($budget['name'] ?? ''),
			'state'          => (string)($budget['state'] ?? 'draft'),
		];
	}//end budgetChoice()

	/**
	 * The fiscal years shown from a year on, one budget each, in year order.
	 *
	 * @param list<array<string,mixed>> $budgets  The administration's budgets.
	 * @param int                       $fromYear The first year shown.
	 *
	 * @return list<array{fiscalYear:int,annualBudgetId:string,name:string,state:string}>
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	private function yearsShown(array $budgets, int $fromYear): array {
		$years = [];
		foreach ($budgets as $budget) {
			$year = (int)($budget['fiscalYear'] ?? 0);
			if ($this->inYearWindow(year: $year, fromYear: $fromYear) === false) {
				continue;
			}

			// The default budget of a year wins; otherwise the first one found.
			if (isset($years[$year]) === true && ($budget['isDefault'] ?? false) !== true) {
				continue;
			}

			$years[$year] = [
				'fiscalYear'     => $year,
				'annualBudgetId' => (string)$budget['id'],
				'name'           => (string)($budget['name'] ?? ''),
				'state'          => (string)($budget['state'] ?? 'draft'),
			];
		}//end foreach

		ksort($years);
		return array_values($years);
	}//end yearsShown()

	/**
	 * Whether a fiscal year falls in the multi-year window.
	 *
	 * @param int $year     The fiscal year.
	 * @param int $fromYear The first year shown.
	 *
	 * @return bool
	 */
	private function inYearWindow(int $year, int $fromYear): bool {
		return ($year >= $fromYear && $year <= ($fromYear + self::YEARS_AHEAD));
	}//end inYearWindow()

	/**
	 * Each ledger group's yearly total per shown fiscal year.
	 *
	 * @param list<array{fiscalYear:int,annualBudgetId:string,name:string,state:string}> $years The shown years.
	 *
	 * @return array<string,array<int,int>> Totals by group id and fiscal year.
	 */
	private function yearTotals(array $years): array {
		$totals = [];
		foreach ($years as $year) {
			foreach ($this->linesByGroup(annualBudgetId: $year['annualBudgetId']) as $groupId => $lines) {
				$totals[$groupId][$year['fiscalYear']] = array_sum($this->sumMonths(lines: $lines));
			}
		}

		return $totals;
	}//end yearTotals()

	/**
	 * One group's amount per shown fiscal year, zero when it has none.
	 *
	 * @param string                                                                     $groupId The ledger group.
	 * @param list<array{fiscalYear:int,annualBudgetId:string,name:string,state:string}> $years   The shown years.
	 * @param array<string,array<int,int>>                                               $totals  Totals by group id and fiscal year.
	 *
	 * @return array<string,int>
	 */
	private function yearAmounts(string $groupId, array $years, array $totals): array {
		$amounts = [];
		foreach ($years as $year) {
			$amounts[(string)$year['fiscalYear']] = ($totals[$groupId][$year['fiscalYear']] ?? 0);
		}

		return $amounts;
	}//end yearAmounts()

	/**
	 * Start next year's budget from a chosen one, its manual lines raised by a percentage.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The budget to start from.
	 * @param float  $percentage       The change in percent, for example 3 or -2.5.
	 *
	 * @return array<string,mixed> The new annual budget.
	 *
	 * @throws BudgetEditRefusedException When next year already has a budget.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	public function startNextYear(string $administrationId, string $annualBudgetId, float $percentage): array {
		if ($percentage < -100.0 || $percentage > 1000.0) {
			throw new BudgetEditRefusedException(template: 'Enter a percentage from -100 to 1000.');
		}

		$source = $this->budget(administrationId: $administrationId, annualBudgetId: $annualBudgetId);
		$nextYear = ((int)$source['fiscalYear'] + 1);
		$this->assertNoBudgetFor(administrationId: $administrationId, fiscalYear: $nextYear);

		$saved = $this->createNextBudget(administrationId: $administrationId, nextYear: $nextYear);
		$created = ObjectIdentifier::recordWithId(candidate: $saved);
		$newId = ObjectIdentifier::resolve(saved: $saved);

		$this->copyManualLines(administrationId: $administrationId, source: $source, newId: $newId, percentage: $percentage);

		return ($created ?? ['id' => $newId, 'fiscalYear' => $nextYear]);

	}//end startNextYear()

	/**
	 * Refuse when a fiscal year already has a budget.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fiscalYear       The fiscal year.
	 *
	 * @return void
	 *
	 * @throws BudgetEditRefusedException When it has one.
	 */
	private function assertNoBudgetFor(string $administrationId, int $fiscalYear): void {
		foreach ($this->budgets(administrationId: $administrationId) as $budget) {
			if ((int)($budget['fiscalYear'] ?? 0) === $fiscalYear) {
				throw new BudgetEditRefusedException(
					template: '%1$s already has a budget: %2$s.',
					parameters: [(string)$fiscalYear, (string)($budget['name'] ?? '')]
				);
			}
		}
	}//end assertNoBudgetFor()

	/**
	 * Save next year's default draft budget.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $nextYear         The fiscal year.
	 *
	 * @return mixed The saveObject() result.
	 */
	private function createNextBudget(string $administrationId, int $nextYear): mixed {
		return $this->scoped(schema: 'AnnualBudget')->saveObject(
			[
				'administrationId' => $administrationId,
				'fiscalYear'       => $nextYear,
				'name'             => sprintf('Budget %d', $nextYear),
				'isDefault'        => true,
				'state'            => 'draft',
			]
		);
	}//end createNextBudget()

	/**
	 * Copy the source budget's manual lines onto the new budget, raised by a percentage.
	 *
	 * @param string              $administrationId The administration.
	 * @param array<string,mixed> $source           The budget started from.
	 * @param string              $newId            The new budget's id.
	 * @param float               $percentage       The change in percent.
	 *
	 * @return void
	 */
	private function copyManualLines(string $administrationId, array $source, string $newId, float $percentage): void {
		$factor = (1 + ($percentage / 100));

		foreach ($this->linesByGroup(annualBudgetId: (string)$source['id']) as $groupId => $lines) {
			$manual = $this->manualLine(lines: $lines);
			if ($manual === null) {
				continue;
			}

			$copy = [
				'administrationId' => $administrationId,
				'annualBudgetId'   => $newId,
				'ledgerGroupId'    => $groupId,
				'source'           => self::SOURCE_MANUAL,
				'notes'            => sprintf('Started from %s at %s%%', (string)($source['name'] ?? $source['fiscalYear']), $this->percent(value: $percentage)),
			];
			foreach ($this->months(line: $manual) as $index => $amount) {
				$copy[$this->monthField(month: $index + 1)] = (int)round($amount * $factor);
			}

			$this->scoped(schema: 'BudgetLine')->saveObject($copy);
		}
	}//end copyManualLines()

	/**
	 * The annual budget, when it is this administration's.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws BudgetEditRefusedException When absent or another administration's.
	 */
	private function budget(string $administrationId, string $annualBudgetId): array {
		$budget = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'AnnualBudget'), id: $annualBudgetId);
		if ($budget === null || (string)($budget['administrationId'] ?? '') !== $administrationId) {
			throw new BudgetEditRefusedException(template: 'This budget does not exist.');
		}

		$budget['id'] = $annualBudgetId;
		return $budget;

	}//end budget()

	/**
	 * The manual line of a group in a budget, or a new one, refused when not writable.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 * @param string $ledgerGroupId    The ledger group.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws BudgetEditRefusedException When the budget is closed or the group's lines are derived.
	 */
	private function writableLine(string $administrationId, string $annualBudgetId, string $ledgerGroupId): array {
		$budget = $this->budget(administrationId: $administrationId, annualBudgetId: $annualBudgetId);
		if (($budget['state'] ?? 'draft') === 'closed') {
			throw new BudgetEditRefusedException(template: 'This budget is closed. Its amounts can no longer change.');
		}

		$this->assertKnownGroup(administrationId: $administrationId, ledgerGroupId: $ledgerGroupId);

		$lines = ($this->linesByGroup(annualBudgetId: $annualBudgetId)[$ledgerGroupId] ?? []);
		$manual = $this->manualLine(lines: $lines);
		if ($manual !== null) {
			return $manual;
		}

		if ($lines !== []) {
			throw new BudgetEditRefusedException(
				template: 'This row comes from %1$s and cannot be typed over.',
				parameters: [(string)$lines[0]['source']]
			);
		}

		return $this->newManualLine(administrationId: $administrationId, annualBudgetId: $annualBudgetId, ledgerGroupId: $ledgerGroupId);

	}//end writableLine()

	/**
	 * Refuse a ledger group the administration does not have.
	 *
	 * @param string $administrationId The administration.
	 * @param string $ledgerGroupId    The ledger group.
	 *
	 * @return void
	 *
	 * @throws BudgetEditRefusedException When it does not exist.
	 */
	private function assertKnownGroup(string $administrationId, string $ledgerGroupId): void {
		$known = false;
		foreach ($this->groups(administrationId: $administrationId) as $group) {
			$known = ($known || $group['id'] === $ledgerGroupId);
		}

		if ($known === false) {
			throw new BudgetEditRefusedException(template: 'This ledger group does not exist.');
		}
	}//end assertKnownGroup()

	/**
	 * A new, empty manual line of a group in a budget.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 * @param string $ledgerGroupId    The ledger group.
	 *
	 * @return array<string,mixed>
	 */
	private function newManualLine(string $administrationId, string $annualBudgetId, string $ledgerGroupId): array {
		$line = [
			'administrationId' => $administrationId,
			'annualBudgetId'   => $annualBudgetId,
			'ledgerGroupId'    => $ledgerGroupId,
			'source'           => self::SOURCE_MANUAL,
		];
		foreach (array_keys($this->emptyMonths()) as $index) {
			$line[$this->monthField(month: $index + 1)] = 0;
		}

		return $line;
	}//end newManualLine()

	/**
	 * Save a line and answer it as a grid row: a new line is created, a stored one patched.
	 *
	 * @param array<string,mixed> $line The line.
	 *
	 * @return array<string,mixed>
	 */
	private function store(array $line): array {
		$lineId = (string)($line['id'] ?? '');
		if ($lineId !== '') {
			$patch = [];
			for ($month = 1; $month <= 12; $month++) {
				$patch[$this->monthField(month: $month)] = (int)($line[$this->monthField(month: $month)] ?? 0);
			}

			$this->scoped(schema: 'BudgetLine')->patchObject($lineId, $patch);
		}

		if ($lineId === '') {
			$saved = $this->scoped(schema: 'BudgetLine')->saveObject($line);
			$line['id'] = ObjectIdentifier::resolve(saved: $saved);
		}

		$months = $this->months(line: $line);

		return [
			'ledgerGroupId' => (string)$line['ledgerGroupId'],
			'lineId'        => $line['id'],
			'source'        => self::SOURCE_MANUAL,
			'editable'      => true,
			'months'        => $months,
			'total'         => array_sum($months),
		];

	}//end store()

	/**
	 * The administration's ledger groups in tree order, with their depth.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array{id:string,code:string,name:string,depth:int}>
	 */
	private function groups(string $administrationId): array {
		[$groups, $byKey] = $this->groupRecords(administrationId: $administrationId);

		usort($groups, static fn (array $a, array $b): int => [$a['order'], $a['code']] <=> [$b['order'], $b['code']]);

		[$roots, $children] = $this->groupTree(groups: $groups, byKey: $byKey);

		$flat = [];
		$this->flatten(groups: $roots, children: $children, depth: 0, flat: $flat);
		return $flat;

	}//end groups()

	/**
	 * The administration's ledger groups, and their ids by id and by code.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array{0:list<array{id:string,code:string,name:string,order:int,parent:string}>,1:array<string,string>}
	 */
	private function groupRecords(string $administrationId): array {
		$groups = [];
		$byKey = [];
		foreach ($this->records(schema: 'LedgerGroup', filters: ['administrationId' => $administrationId]) as $row) {
			$group = [
				'id'     => (string)$row['id'],
				'code'   => (string)($row['code'] ?? ''),
				'name'   => (string)($row['name'] ?? ''),
				'order'  => (int)($row['order'] ?? 0),
				'parent' => (string)($row['parentLedgerGroupId'] ?? ''),
			];
			$groups[] = $group;
			$byKey[$group['id']] = $group['id'];
			if ($group['code'] !== '') {
				$byKey[$group['code']] = $group['id'];
			}
		}

		return [$groups, $byKey];
	}//end groupRecords()

	/**
	 * The root groups, and the child groups by parent id.
	 *
	 * @param list<array{id:string,code:string,name:string,order:int,parent:string}> $groups The groups, sorted.
	 * @param array<string,string>                                                   $byKey  Group ids by id and by code.
	 *
	 * @return array{0:list<array{id:string,code:string,name:string,order:int,parent:string}>,1:array<string,list<array{id:string,code:string,name:string,order:int,parent:string}>>}
	 */
	private function groupTree(array $groups, array $byKey): array {
		$children = [];
		$roots = [];
		foreach ($groups as $group) {
			$parent = ($byKey[$group['parent']] ?? null);
			if ($group['parent'] === '' || $parent === null || $parent === $group['id']) {
				$roots[] = $group;
				continue;
			}

			$children[$parent][] = $group;
		}

		return [$roots, $children];
	}//end groupTree()

	/**
	 * Depth-first flattening of the group tree.
	 *
	 * @param list<array{id:string,code:string,name:string,order:int,parent:string}>               $groups   The groups on this level.
	 * @param array<string,list<array{id:string,code:string,name:string,order:int,parent:string}>> $children Children by parent id.
	 * @param int                                       $depth    This level's depth.
	 * @param list<array{id:string,code:string,name:string,depth:int}> $flat The result, appended to.
	 *
	 * @return void
	 */
	private function flatten(array $groups, array $children, int $depth, array &$flat): void {
		foreach ($groups as $group) {
			$flat[] = ['id' => $group['id'], 'code' => $group['code'], 'name' => $group['name'], 'depth' => $depth];
			if ($depth < 10) {
				$this->flatten(groups: ($children[$group['id']] ?? []), children: $children, depth: $depth + 1, flat: $flat);
			}
		}

	}//end flatten()

	/**
	 * The administration's annual budgets.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function budgets(string $administrationId): array {
		return $this->records(schema: 'AnnualBudget', filters: ['administrationId' => $administrationId]);

	}//end budgets()

	/**
	 * A budget's lines grouped by ledger group.
	 *
	 * @param string $annualBudgetId The annual budget.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function linesByGroup(string $annualBudgetId): array {
		$byGroup = [];
		foreach ($this->records(schema: 'BudgetLine', filters: ['annualBudgetId' => $annualBudgetId]) as $line) {
			$byGroup[(string)($line['ledgerGroupId'] ?? '')][] = $line;
		}

		return $byGroup;

	}//end linesByGroup()

	/**
	 * The manual line among a group's lines.
	 *
	 * @param list<array<string,mixed>> $lines The group's lines.
	 *
	 * @return array<string,mixed>|null
	 */
	private function manualLine(array $lines): ?array {
		foreach ($lines as $line) {
			if (($line['source'] ?? '') === self::SOURCE_MANUAL) {
				return $line;
			}
		}

		return null;

	}//end manualLine()

	/**
	 * A line's twelve month amounts in cents.
	 *
	 * @param array<string,mixed> $line The line.
	 *
	 * @return list<int>
	 */
	private function months(array $line): array {
		$months = [];
		for ($month = 1; $month <= 12; $month++) {
			$months[] = (int)($line[$this->monthField(month: $month)] ?? 0);
		}

		return $months;

	}//end months()

	/**
	 * The month amounts of several lines added up.
	 *
	 * @param list<array<string,mixed>> $lines The lines.
	 *
	 * @return list<int>
	 */
	private function sumMonths(array $lines): array {
		$sum = $this->emptyMonths();
		foreach ($lines as $line) {
			foreach ($this->months(line: $line) as $index => $amount) {
				$sum[$index] += $amount;
			}
		}

		return $sum;

	}//end sumMonths()

	/**
	 * Twelve zeros.
	 *
	 * @return list<int>
	 */
	private function emptyMonths(): array {
		return array_fill(0, 12, 0);

	}//end emptyMonths()

	/**
	 * The BudgetLine property of a month.
	 *
	 * @param int $month 1 to 12.
	 *
	 * @return string
	 */
	private function monthField(int $month): string {
		return sprintf('month%02dAmount', $month);

	}//end monthField()

	/**
	 * Cents as euros for a message.
	 *
	 * @param int $cents The amount.
	 *
	 * @return string
	 */
	private function euro(int $cents): string {
		return 'EUR ' . number_format($cents / 100, 2, ',', '.');

	}//end euro()

	/**
	 * A percentage without trailing zeros.
	 *
	 * @param float $value The percentage.
	 *
	 * @return string
	 */
	private function percent(float $value): string {
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

	}//end percent()

	/**
	 * Records of a schema with their ids.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function records(string $schema, array $filters): array {
		$records = [];
		foreach ($this->scoped(schema: $schema)->findAll(['filters' => $filters, 'limit' => 10000]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end records()

	/**
	 * The object service on a schema of the register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
