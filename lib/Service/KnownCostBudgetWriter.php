<?php

/**
 * Known Cost Budget Writer
 *
 * The orchestrator half of `budget-known-costs` (REQ-BKC-004, REQ-BKC-005,
 * REQ-BKC-007): turns every in-scope `CashflowRecurring` row into
 * `BudgetLine(source: "contract"|"recurring")` rows, idempotently, via the
 * `BudgetLineDerivation` fingerprint ledger. {@see KnownCostReader} does
 * every read; {@see KnownCostScheduleExpander} does the pure schedule
 * arithmetic; this class groups, sums and upserts.
 *
 * ## Per-run algorithm (`design.md` §8a)
 *
 *  1. {@see KnownCostReader::loadContext()} loads everything (6 `findAll()`
 *     calls total).
 *  2. For every `CashflowRecurring` row: resolve its target `LedgerGroup`
 *     (via `accountNumberExpense`) and its `sourceType`
 *     (`contractReference` set => `contract`, else `recurring`). A row
 *     whose `accountNumberExpense` resolves to no `LedgerGroup` is skipped
 *     (logged) — nothing to sum it into.
 *  3. Group rows by `(ledgerGroupId, sourceType)`. For every fiscal year
 *     with a default `AnnualBudget` touched by any row in the group, expand
 *     every row for that year and SUM the returned monthly cents across the
 *     group's rows (§1c/§4b — multiple recurring costs targeting the same
 *     `LedgerGroup` sum into one derived line). A fiscal year with no
 *     default `AnnualBudget` is skipped entirely — no `BudgetLine`, no
 *     `AnnualBudget` created (REQ-BKC-007).
 *  4. For every `(annualBudgetId, ledgerGroupId, sourceType)` combination
 *     produced by step 3, upsert per §8b/§8c below.
 *
 * ## Upsert (`design.md` §8b/§8c)
 *
 *  - **No existing `BudgetLineDerivation`**: create a new `BudgetLine` and
 *    a fresh `BudgetLineDerivation` fingerprinting it.
 *  - **Existing derivation, `overridden: true`**: skip entirely — no
 *    read-back, no write. Left alone until the `BudgetLine` is deleted.
 *  - **Existing derivation, `overridden: false`, `BudgetLine` missing**
 *    (deleted by an operator): treated as "target gone" — recreate fresh,
 *    same as the no-existing-derivation path. This is the stated reset
 *    path (REQ-BKC-005).
 *  - **Existing derivation, `overridden: false`, `BudgetLine` present, its
 *    12 amounts equal `lastGeneratedMonthlyAmounts`**: not overridden since
 *    the last run — overwrite with the freshly computed sum, refresh the
 *    fingerprint. Running this twice in a row with no upstream change
 *    produces byte-identical output both times (REQ-BKC-004) — the
 *    idempotency property this class exists for.
 *  - **Existing derivation, `overridden: false`, `BudgetLine` present, its
 *    12 amounts DIFFER from `lastGeneratedMonthlyAmounts`**: an operator
 *    hand-edited the derived line since the last run. Mark the derivation
 *    `overridden: true`; do NOT touch the `BudgetLine`'s amounts; do NOT
 *    refresh the fingerprint (REQ-BKC-005) — a direct edit to a derived
 *    line, once made, always wins over regeneration until the line is
 *    deleted.
 *
 * `KnownCostScheduleExpander::expand()` returning `{kind:
 * "needsOperatorInput"}` for a row/year contributes `0` to that group's sum
 * for that year (logged) — never a fabricated amount (REQ-BKC-003); the row
 * still appears in `contributingRecurIds` since it IS a member of the
 * group, its contribution for that specific year is simply not yet
 * computable.
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-004
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-005
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotent, override-aware regeneration of `contract`/`recurring`-sourced
 * `BudgetLine` rows from `CashflowRecurring` schedules.
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-004
 */
class KnownCostBudgetWriter {
	/**
	 * The 12 monthly BudgetLine field names, in calendar order.
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
	 * Zero-padded month keys, `"01"` through `"12"`, matching
	 * {@see KnownCostScheduleExpander::expand()}'s own return keys.
	 *
	 * @var list<string>
	 */
	private const MONTH_KEYS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];

	/**
	 * Construct the writer.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 * @param KnownCostReader $reader Every OpenRegister read this run needs.
	 * @param KnownCostScheduleExpander $expander The pure schedule arithmetic.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly KnownCostReader $reader,
		private readonly KnownCostScheduleExpander $expander = new KnownCostScheduleExpander(),
	) {
	}//end __construct()

	/**
	 * Run one regeneration pass for `$administrationId`.
	 *
	 * @param string $administrationId The administration to regenerate known-cost BudgetLines for.
	 *
	 * @return array{created:int,updated:int,overridden:int,skippedFiscalYears:list<int>} A run summary.
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-004
	 */
	public function run(string $administrationId): array {
		$context = $this->reader->loadContext(administrationId: $administrationId);

		$groups = $this->groupByLedgerGroupAndSourceType(
			recurring: $context['recurring'],
			ledgerGroupIdByAccount: $context['ledgerGroupIdByAccount']
		);

		$summary = ['created' => 0, 'updated' => 0, 'overridden' => 0, 'skippedFiscalYears' => []];
		$touchedYears = [];

		foreach ($groups as $group) {
			foreach ($this->fiscalYearsTouched(rows: $group['rows']) as $fiscalYear) {
				$touchedYears[$fiscalYear] = true;

				$annualBudgetId = ($context['annualBudgetIdByYear'][$fiscalYear] ?? null);
				if ($annualBudgetId === null) {
					$summary['skippedFiscalYears'][$fiscalYear] = true;
					continue;
				}

				$this->upsertOne(
					administrationId: $administrationId,
					annualBudgetId: $annualBudgetId,
					ledgerGroupId: $group['ledgerGroupId'],
					sourceType: $group['sourceType'],
					fiscalYear: $fiscalYear,
					rows: $group['rows'],
					context: $context,
					summary: $summary
				);
			}
		}

		// `array_keys()` already returns a list.
		$summary['skippedFiscalYears'] = array_keys($summary['skippedFiscalYears']);

		return $summary;

	}//end run()

	/**
	 * Group `CashflowRecurring` rows by `(ledgerGroupId, sourceType)`
	 * (`design.md` §8a step 2/3). A row whose `accountNumberExpense`
	 * resolves to no `LedgerGroup` is skipped (logged) — nothing to sum it
	 * into.
	 *
	 * @param list<array<string,mixed>> $recurring The CashflowRecurring rows.
	 * @param array<string,string> $ledgerGroupIdByAccount Account number => LedgerGroup id/slug.
	 *
	 * @return list<array{ledgerGroupId:string,sourceType:string,rows:list<array<string,mixed>>}> The groups.
	 */
	private function groupByLedgerGroupAndSourceType(array $recurring, array $ledgerGroupIdByAccount): array {
		$byKey = [];
		foreach ($recurring as $row) {
			$accountNumber = (string)($row['accountNumberExpense'] ?? '');
			$ledgerGroupId = ($ledgerGroupIdByAccount[$accountNumber] ?? null);
			if ($ledgerGroupId === null) {
				$this->logger->info(
					'KnownCostBudgetWriter: accountNumberExpense resolves to no LedgerGroup — skipping row',
					['recurId' => ($row['recurId'] ?? 'unknown'), 'accountNumberExpense' => $accountNumber]
				);
				continue;
			}

			$sourceType = 'recurring';
			if ((string)($row['contractReference'] ?? '') !== '') {
				$sourceType = 'contract';
			}

			$key = ($ledgerGroupId . '::' . $sourceType);

			if (isset($byKey[$key]) === false) {
				$byKey[$key] = ['ledgerGroupId' => $ledgerGroupId, 'sourceType' => $sourceType, 'rows' => []];
			}

			$byKey[$key]['rows'][] = $row;
		}

		return array_values($byKey);

	}//end groupByLedgerGroupAndSourceType()

	/**
	 * Every calendar year a group's rows' `[validFrom, validTo]` spans
	 * touch — the fiscal years `run()` must consider for this group.
	 * Indefinite rows (`validTo: null`) are bounded to `validFrom`'s year
	 * through the current calendar year, so an indefinitely-recurring cost
	 * does not enumerate an unbounded number of future fiscal years on
	 * every run; a later year is picked up automatically once its
	 * `AnnualBudget` exists and this method's own "current year" horizon
	 * reaches it (`design.md` §7 — a year with no default AnnualBudget is
	 * simply skipped, not an error).
	 *
	 * @param list<array<string,mixed>> $rows The group's CashflowRecurring rows.
	 *
	 * @return list<int> The distinct fiscal years, ascending.
	 */
	private function fiscalYearsTouched(array $rows): array {
		$currentYear = (int)gmdate('Y');
		$years = [];
		foreach ($rows as $row) {
			$fromYear = $this->yearOf(value: (string)($row['validFrom'] ?? ''));
			if ($fromYear === null) {
				continue;
			}

			$toYear = $this->yearOf(value: (string)($row['validTo'] ?? ''));
			$endYear = ($toYear ?? max($fromYear, $currentYear));

			for ($year = $fromYear; $year <= $endYear; $year++) {
				$years[$year] = true;
			}
		}

		ksort($years);

		return array_keys($years);

	}//end fiscalYearsTouched()

	/**
	 * Parse the calendar year out of an ISO date string.
	 *
	 * @param string $value The date string.
	 *
	 * @return integer|null The year, or null when unparseable/empty.
	 */
	private function yearOf(string $value): ?int {
		if ($value === '') {
			return null;
		}

		try {
			return (int)(new DateTimeImmutable($value))->format('Y');
		} catch (Throwable) {
			return null;
		}

	}//end yearOf()

	/**
	 * Compute the summed monthly cents for one group/fiscal-year, then
	 * upsert per `design.md` §8b/§8c.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $annualBudgetId The resolved default AnnualBudget id for this fiscal year.
	 * @param string $ledgerGroupId The group's LedgerGroup id/slug.
	 * @param string $sourceType `contract` or `recurring`.
	 * @param integer $fiscalYear The fiscal year being written.
	 * @param list<array<string,mixed>> $rows The group's CashflowRecurring rows.
	 * @param array<string,mixed> $context The {@see KnownCostReader::loadContext()} bundle.
	 * @param array{created:int,updated:int,overridden:int,skippedFiscalYears:array<int,bool>} $summary Run summary, mutated in place.
	 *
	 * @return void
	 */
	private function upsertOne(
		string $administrationId,
		string $annualBudgetId,
		string $ledgerGroupId,
		string $sourceType,
		int $fiscalYear,
		array $rows,
		array $context,
		array &$summary
	): void {
		$monthlyCents = $this->sumMonthlyCents(rows: $rows, fiscalYear: $fiscalYear);
		$contributingRecurIds = array_values(
			array_map(static fn (array $row): string => (string)($row['recurId'] ?? ''), $rows)
		);

		$derivation = $this->findDerivation(
			derivations: $context['derivations'],
			annualBudgetId: $annualBudgetId,
			ledgerGroupId: $ledgerGroupId,
			sourceType: $sourceType
		);

		if ($derivation === null) {
			$this->createFresh(
				administrationId: $administrationId,
				annualBudgetId: $annualBudgetId,
				ledgerGroupId: $ledgerGroupId,
				sourceType: $sourceType,
				monthlyCents: $monthlyCents,
				contributingRecurIds: $contributingRecurIds,
				existingDerivation: null
			);
			$summary['created']++;
			return;
		}

		// The "target gone" reset path (REQ-BKC-005) is checked BEFORE the
		// overridden flag, deliberately: an operator's override is scoped to
		// the BudgetLine that existed when they made it. Once that specific
		// line is deleted there is nothing left for the override to apply
		// to, and design.md §8c's own "deleting a derived line resets it to
		// fully machine-generated" scenario is explicit that this fires
		// regardless of the derivation's prior overridden value.
		$budgetLine = $this->findBudgetLine(budgetLines: $context['budgetLines'], id: (string)($derivation['budgetLineId'] ?? ''));
		if ($budgetLine === null) {
			$this->createFresh(
				administrationId: $administrationId,
				annualBudgetId: $annualBudgetId,
				ledgerGroupId: $ledgerGroupId,
				sourceType: $sourceType,
				monthlyCents: $monthlyCents,
				contributingRecurIds: $contributingRecurIds,
				existingDerivation: $derivation
			);
			$summary['created']++;
			return;
		}

		if (($derivation['overridden'] ?? false) === true) {
			// Already flagged, and its target BudgetLine still exists —
			// left alone until the reset path above fires. No read-back
			// comparison, no write (design.md §8c).
			return;
		}

		$liveAmounts = $this->extractMonthlyAmounts(budgetLine: $budgetLine);
		$fingerprint = $this->normaliseFingerprint(value: ($derivation['lastGeneratedMonthlyAmounts'] ?? []));

		if ($liveAmounts !== $fingerprint) {
			// An operator's own edit since the last run — flag, never
			// overwrite (REQ-BKC-005).
			$this->updateDerivationOverridden(derivation: $derivation);
			$summary['overridden']++;
			return;
		}

		$this->overwriteWithFreshSum(
			budgetLine: $budgetLine,
			derivation: $derivation,
			monthlyCents: $monthlyCents,
			contributingRecurIds: $contributingRecurIds
		);
		$summary['updated']++;

	}//end upsertOne()

	/**
	 * Sum every row's `KnownCostScheduleExpander::expand()` output for
	 * `$fiscalYear`. A row whose result is `needsOperatorInput` contributes
	 * `0` for that year (logged) — never a fabricated amount (REQ-BKC-003).
	 *
	 * @param list<array<string,mixed>> $rows The group's CashflowRecurring rows.
	 * @param integer $fiscalYear The fiscal year to compute.
	 *
	 * Keyed by `int|string` — see KnownCostScheduleExpander::zeroMonths(): PHP
	 * coerces `"10".."12"` to integer array keys while `"01".."09"` stay
	 * strings.
	 *
	 * @return array<int|string,int> `"01".."12" => summed cents`.
	 */
	private function sumMonthlyCents(array $rows, int $fiscalYear): array {
		$sum = array_fill_keys(self::MONTH_KEYS, 0);
		foreach ($rows as $row) {
			$result = $this->expander->expand(recurring: $row, fiscalYear: $fiscalYear);
			if (($result['kind'] ?? '') !== 'amounts') {
				$this->logger->info(
					'KnownCostBudgetWriter: row needs operator input for this fiscal year — contributing 0',
					['recurId' => ($row['recurId'] ?? 'unknown'), 'fiscalYear' => $fiscalYear]
				);
				continue;
			}

			foreach (self::MONTH_KEYS as $key) {
				$sum[$key] += (int)($result['monthlyCents'][$key] ?? 0);
			}
		}

		return $sum;

	}//end sumMonthlyCents()

	/**
	 * Find the `BudgetLineDerivation` for one `(annualBudgetId, ledgerGroupId, sourceType)` triple.
	 *
	 * @param list<array<string,mixed>> $derivations The loaded BudgetLineDerivation rows.
	 * @param string $annualBudgetId The AnnualBudget id.
	 * @param string $ledgerGroupId The LedgerGroup id/slug.
	 * @param string $sourceType `contract` or `recurring`.
	 *
	 * @return array<string,mixed>|null The matching derivation, or null when none exists.
	 */
	private function findDerivation(array $derivations, string $annualBudgetId, string $ledgerGroupId, string $sourceType): ?array {
		foreach ($derivations as $derivation) {
			if ((string)($derivation['annualBudgetId'] ?? '') === $annualBudgetId
				&& (string)($derivation['ledgerGroupId'] ?? '') === $ledgerGroupId
				&& (string)($derivation['sourceType'] ?? '') === $sourceType
			) {
				return $derivation;
			}
		}

		return null;

	}//end findDerivation()

	/**
	 * Find a `BudgetLine` in the already-loaded context by id.
	 *
	 * @param list<array<string,mixed>> $budgetLines The loaded BudgetLine rows.
	 * @param string $id The BudgetLine id to find.
	 *
	 * @return array<string,mixed>|null The matching BudgetLine, or null when not found.
	 */
	private function findBudgetLine(array $budgetLines, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($budgetLines as $budgetLine) {
			$candidate = (string)($budgetLine['@self']['id'] ?? $budgetLine['id'] ?? '');
			if ($candidate === $id) {
				return $budgetLine;
			}
		}

		return null;

	}//end findBudgetLine()

	/**
	 * Extract a BudgetLine's 12 monthly amounts, in calendar order.
	 *
	 * @param array<string,mixed> $budgetLine The BudgetLine row.
	 *
	 * @return list<int> The 12 monthly cent amounts.
	 */
	private function extractMonthlyAmounts(array $budgetLine): array {
		$amounts = [];
		foreach (self::MONTH_FIELDS as $field) {
			$amounts[] = (int)($budgetLine[$field] ?? 0);
		}

		return $amounts;

	}//end extractMonthlyAmounts()

	/**
	 * Normalise a derivation's stored fingerprint into a 12-element list of
	 * ints, defensively (a malformed/short array never matches a real
	 * amounts list by coincidence).
	 *
	 * @param mixed $value The `lastGeneratedMonthlyAmounts` value as stored.
	 *
	 * @return list<int> Exactly 12 integers.
	 */
	private function normaliseFingerprint(mixed $value): array {
		$list = [];
		if (is_array($value) === true) {
			$list = array_values($value);
		}

		$normalised = [];
		for ($i = 0; $i < 12; $i++) {
			$normalised[] = (int)($list[$i] ?? 0);
		}

		return $normalised;

	}//end normaliseFingerprint()

	/**
	 * Convert a `"01".."12" => cents` map (calendar order) into a 12-element list.
	 *
	 * @param array<string,int> $monthlyCents The expander/writer's own month-key map.
	 *
	 * @return list<int> The 12 amounts, in calendar order.
	 */
	private function monthlyCentsAsList(array $monthlyCents): array {
		$list = [];
		foreach (self::MONTH_KEYS as $key) {
			$list[] = (int)($monthlyCents[$key] ?? 0);
		}

		return $list;

	}//end monthlyCentsAsList()

	/**
	 * Create a fresh `BudgetLine`, and either create a fresh
	 * `BudgetLineDerivation` (§8b, `$existingDerivation === null`) or reset
	 * an existing one's fields in place (§8c's reset path,
	 * `$existingDerivation` given). The reset path deliberately reuses the
	 * existing derivation row rather than inserting a second one: exactly
	 * one `BudgetLineDerivation` row exists per `(annualBudgetId,
	 * ledgerGroupId, sourceType)` triple (`design.md` §4b) — a fresh
	 * BudgetLine always gets a fresh id (the deleted one cannot be reused),
	 * but the SAME derivation row is repointed at it and its fields reset
	 * to a fully machine-generated, `overridden: false` state.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $annualBudgetId The AnnualBudget id.
	 * @param string $ledgerGroupId The LedgerGroup id/slug.
	 * @param string $sourceType `contract` or `recurring`.
	 * @param array<string,int> $monthlyCents The summed `"01".."12" => cents` map.
	 * @param list<string> $contributingRecurIds Every CashflowRecurring.recurId in the group.
	 * @param array<string,mixed>|null $existingDerivation The existing, now-orphaned derivation row
	 *                                                     to reset in place, or null to create a brand-new one.
	 *
	 * @return void
	 */
	private function createFresh(
		string $administrationId,
		string $annualBudgetId,
		string $ledgerGroupId,
		string $sourceType,
		array $monthlyCents,
		array $contributingRecurIds,
		?array $existingDerivation
	): void {
		$budgetLinePayload = array_merge(
			[
				'administrationId' => $administrationId,
				'annualBudgetId' => $annualBudgetId,
				'ledgerGroupId' => $ledgerGroupId,
				'source' => $sourceType,
			],
			$this->monthFields(monthlyCents: $monthlyCents)
		);

		$savedBudgetLine = $this->serialize(
			result: $this->objectService
				->setRegister($this->register())
				->setSchema('BudgetLine')
				->saveObject($budgetLinePayload)
		);
		$budgetLineId = (string)($savedBudgetLine['@self']['id'] ?? $savedBudgetLine['id'] ?? '');

		$freshFields = [
			'administrationId' => $administrationId,
			'annualBudgetId' => $annualBudgetId,
			'ledgerGroupId' => $ledgerGroupId,
			'sourceType' => $sourceType,
			'budgetLineId' => $budgetLineId,
			'contributingRecurIds' => $contributingRecurIds,
			'lastGeneratedMonthlyAmounts' => $this->monthlyCentsAsList(monthlyCents: $monthlyCents),
			'lastGeneratedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'overridden' => false,
		];

		if ($existingDerivation === null) {
			$this->objectService
				->setRegister($this->register())
				->setSchema('BudgetLineDerivation')
				->saveObject($freshFields);
			return;
		}

		$derivationId = (string)($existingDerivation['@self']['id'] ?? $existingDerivation['id'] ?? '');
		$this->objectService
			->setRegister($this->register())
			->setSchema('BudgetLineDerivation')
			->updateObject($derivationId, array_merge($existingDerivation, $freshFields));

	}//end createFresh()

	/**
	 * Overwrite an un-overridden derived `BudgetLine`'s amounts with the
	 * freshly computed sum, and refresh the derivation's fingerprint
	 * (§8c, the not-overridden-since-last-run path).
	 *
	 * `ObjectServiceInterface::updateObject()` REPLACES the stored object
	 * with exactly the fields given — it does not merge — so every write
	 * here starts from the FULL already-loaded row and only overwrites the
	 * fields this run actually changes, never a partial delta. A partial
	 * delta would silently erase every other field on the object
	 * (`InMemoryObjectServiceStub::patchObject()`'s own docblock records
	 * this same real-service behaviour).
	 *
	 * @param array<string,mixed> $budgetLine The current, full BudgetLine row.
	 * @param array<string,mixed> $derivation The current, full BudgetLineDerivation row.
	 * @param array<string,int> $monthlyCents The summed `"01".."12" => cents` map.
	 * @param list<string> $contributingRecurIds Every CashflowRecurring.recurId in the group.
	 *
	 * @return void
	 */
	private function overwriteWithFreshSum(
		array $budgetLine,
		array $derivation,
		array $monthlyCents,
		array $contributingRecurIds
	): void {
		$budgetLineId = (string)($budgetLine['@self']['id'] ?? $budgetLine['id'] ?? '');
		$updatedBudgetLine = array_merge($budgetLine, $this->monthFields(monthlyCents: $monthlyCents));
		$this->objectService
			->setRegister($this->register())
			->setSchema('BudgetLine')
			->updateObject($budgetLineId, $updatedBudgetLine);

		$derivationId = (string)($derivation['@self']['id'] ?? $derivation['id'] ?? '');
		$updatedDerivation = array_merge(
			$derivation,
			[
				'contributingRecurIds' => $contributingRecurIds,
				'lastGeneratedMonthlyAmounts' => $this->monthlyCentsAsList(monthlyCents: $monthlyCents),
				'lastGeneratedAt' => gmdate('Y-m-d\TH:i:s\Z'),
				'overridden' => false,
			]
		);
		$this->objectService
			->setRegister($this->register())
			->setSchema('BudgetLineDerivation')
			->updateObject($derivationId, $updatedDerivation);

	}//end overwriteWithFreshSum()

	/**
	 * Flag a derivation `overridden: true` without touching its `BudgetLine`
	 * or its fingerprint (§8c — a direct edit, once detected, always wins
	 * until the line is deleted). Starts from the full already-loaded
	 * derivation row for the same REPLACE-not-merge reason
	 * {@see overwriteWithFreshSum()} documents.
	 *
	 * @param array<string,mixed> $derivation The current, full BudgetLineDerivation row.
	 *
	 * @return void
	 */
	private function updateDerivationOverridden(array $derivation): void {
		$derivationId = (string)($derivation['@self']['id'] ?? $derivation['id'] ?? '');
		$updatedDerivation = array_merge($derivation, ['overridden' => true]);
		$this->objectService
			->setRegister($this->register())
			->setSchema('BudgetLineDerivation')
			->updateObject($derivationId, $updatedDerivation);

	}//end updateDerivationOverridden()

	/**
	 * Map a `"01".."12" => cents` map onto `BudgetLine`'s `monthNNAmount` field names.
	 *
	 * @param array<string,int> $monthlyCents The `"01".."12" => cents` map.
	 *
	 * @return array<string,int> `month01Amount..month12Amount => cents`.
	 */
	private function monthFields(array $monthlyCents): array {
		$fields = [];
		foreach (self::MONTH_KEYS as $index => $key) {
			$fields[self::MONTH_FIELDS[$index]] = (int)($monthlyCents[$key] ?? 0);
		}

		return $fields;

	}//end monthFields()

	/**
	 * Normalise a `saveObject()`/`updateObject()` result into a plain array
	 * — `jsonSerialize()` first, `getObject()` as a fallback (ADR-084;
	 * `CogsPosterService`'s own precedent).
	 *
	 * @param mixed $result The `ObjectEntityInterface` (or, defensively, array) result.
	 *
	 * @return array<string,mixed> The serialised object, or an empty array when neither shape applies.
	 */
	private function serialize(mixed $result): array {
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			$out = $result->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}
		}

		if (is_object($result) === true && method_exists($result, 'getObject') === true) {
			$out = $result->getObject();
			if (is_array($out) === true) {
				return $out;
			}
		}

		return [];

	}//end serialize()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()
}//end class
