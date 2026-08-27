<?php

/**
 * Known Cost Reader
 *
 * The OpenRegister half of `budget-known-costs` (REQ-BKC-008): every read
 * {@see KnownCostBudgetWriter} needs to derive `BudgetLine` rows from
 * `CashflowRecurring` schedules, and nothing else.
 * {@see KnownCostScheduleExpander} does the pure schedule arithmetic; this
 * class is the only one that talks to the store.
 *
 * ## Query budget: exactly 6 `findAll()` calls, independent of scope
 *
 *  1. `CashflowRecurring.findAll([administrationId])` — once.
 *  2. `Account.findAll([administrationId])` — once. Resolves
 *     `accountNumberExpense` -> `LedgerGroup` membership, reimplementing
 *     `budget-core-schema design.md` §3a's range + explicit include/exclude
 *     algorithm — deliberately NOT shared with
 *     {@see BudgetVsActualsReader}, the identical decision
 *     `budget-projection-engine design.md` §5d already made and justified
 *     (editing a sibling's already-spec'd reader is out of scope; this is a
 *     small, low-risk algorithm to reimplement once more).
 *  3. `LedgerGroup.findAll([administrationId])` — once.
 *  4. `AnnualBudget.findAll([administrationId])` — once. Used to resolve,
 *     per fiscal year touched by any in-scope `CashflowRecurring` row,
 *     whether a default (`isDefault: true`) `AnnualBudget` exists for that
 *     year (`design.md` §7).
 *  5. `BudgetLine.findAll([annualBudgetId: {in: [...]}])` — once, scoped to
 *     the `AnnualBudget` ids resolved in (4) — the
 *     `SpendAnalyticsService.php:183` `in`-filter precedent, same as
 *     `budget-core-schema design.md` §1c / {@see BudgetVsActualsReader}.
 *  6. `BudgetLineDerivation.findAll([annualBudgetId: {in: [...]}])` — once,
 *     same scoping as (5).
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads every schema `budget-known-costs`' regeneration run needs, batched
 * to exactly 6 `findAll()` calls (REQ-BKC-008).
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-008
 */
class KnownCostReader {
	/**
	 * Recurring-cost schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CASHFLOW_RECURRING = 'CashflowRecurring';

	/**
	 * Chart-of-accounts schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ACCOUNT = 'Account';

	/**
	 * Ledger group schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_LEDGER_GROUP = 'LedgerGroup';

	/**
	 * Annual budget schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ANNUAL_BUDGET = 'AnnualBudget';

	/**
	 * Budget line schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET_LINE = 'BudgetLine';

	/**
	 * Budget line derivation schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET_LINE_DERIVATION = 'BudgetLineDerivation';

	/**
	 * Construct the reader.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Load every read one regeneration run needs for one administration,
	 * batched to exactly 6 `findAll()` calls total (REQ-BKC-008).
	 *
	 * @param string $administrationId The administration to scope every read to.
	 *
	 * @return array{
	 *     recurring: list<array<string,mixed>>,
	 *     ledgerGroupIdByAccount: array<string,string>,
	 *     annualBudgetIdByYear: array<int,string>,
	 *     budgetLines: list<array<string,mixed>>,
	 *     derivations: list<array<string,mixed>>,
	 * } The assembled context {@see KnownCostBudgetWriter} consumes.
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-008
	 */
	public function loadContext(string $administrationId): array {
		$recurring = $this->query(
			schema: self::SCHEMA_CASHFLOW_RECURRING,
			filters: ['administrationId' => $administrationId]
		);

		$accounts = $this->query(schema: self::SCHEMA_ACCOUNT, filters: ['administrationId' => $administrationId]);
		$ledgerGroups = $this->query(schema: self::SCHEMA_LEDGER_GROUP, filters: ['administrationId' => $administrationId]);
		$ledgerGroupIdByAccount = $this->buildLedgerGroupMembershipIndex(ledgerGroups: $ledgerGroups, accounts: $accounts);

		$annualBudgets = $this->query(schema: self::SCHEMA_ANNUAL_BUDGET, filters: ['administrationId' => $administrationId]);
		$annualBudgetIdByYear = $this->buildDefaultAnnualBudgetIndex(annualBudgets: $annualBudgets);

		$annualBudgetIds = array_values($annualBudgetIdByYear);

		$budgetLines = [];
		$derivations = [];
		if ($annualBudgetIds !== []) {
			$budgetLines = $this->query(
				schema: self::SCHEMA_BUDGET_LINE,
				filters: ['annualBudgetId' => ['in' => $annualBudgetIds]]
			);
			$derivations = $this->query(
				schema: self::SCHEMA_BUDGET_LINE_DERIVATION,
				filters: ['annualBudgetId' => ['in' => $annualBudgetIds]]
			);
		}

		return [
			'recurring' => $recurring,
			'ledgerGroupIdByAccount' => $ledgerGroupIdByAccount,
			'annualBudgetIdByYear' => $annualBudgetIdByYear,
			'budgetLines' => $budgetLines,
			'derivations' => $derivations,
		];

	}//end loadContext()

	/**
	 * Resolve, per account number, the id of the `LedgerGroup` it is a
	 * member of (accountRanges + explicit include/exclude, the
	 * `BudgetVsActualsReader::resolveMembers()` precedent, reimplemented
	 * deliberately — `design.md` §5). An account belonging to more than one
	 * `LedgerGroup` resolves to the FIRST group encountered (declaration
	 * order) — `LedgerGroup` membership is not itself this change's concern
	 * to arbitrate; it reads the same shape every sibling reader already
	 * assumes is well-formed.
	 *
	 * @param list<array<string,mixed>> $ledgerGroups The LedgerGroup rows.
	 * @param list<array<string,mixed>> $accounts The Account rows.
	 *
	 * @return array<string,string> Account number => LedgerGroup id (or slug when id is absent).
	 */
	private function buildLedgerGroupMembershipIndex(array $ledgerGroups, array $accounts): array {
		$index = [];
		foreach ($ledgerGroups as $ledgerGroup) {
			$key = $this->ledgerGroupKey(ledgerGroup: $ledgerGroup);
			if ($key === '') {
				continue;
			}

			foreach ($this->resolveMembers(ledgerGroup: $ledgerGroup, accounts: $accounts) as $accountNumber) {
				if (isset($index[$accountNumber]) === false) {
					$index[$accountNumber] = $key;
				}
			}
		}

		return $index;

	}//end buildLedgerGroupMembershipIndex()

	/**
	 * Resolve a `LedgerGroup`'s stable lookup key: its object id, falling
	 * back to its `@self.slug` — the `BudgetVsActualsReader` dual-key
	 * convention.
	 *
	 * @param array<string,mixed> $ledgerGroup The LedgerGroup row.
	 *
	 * @return string The id or slug, or an empty string when neither is set.
	 */
	private function ledgerGroupKey(array $ledgerGroup): string {
		$id = (string)($ledgerGroup['@self']['id'] ?? $ledgerGroup['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		return (string)($ledgerGroup['@self']['slug'] ?? $ledgerGroup['slug'] ?? '');

	}//end ledgerGroupKey()

	/**
	 * Resolve one LedgerGroup's member account numbers: every account whose
	 * number falls in an `accountRanges` pair, PLUS every
	 * `includedAccountNumbers` entry, MINUS every `excludedAccountNumbers`
	 * entry — identical to `BudgetVsActualsReader::resolveMembers()`,
	 * deliberately reimplemented rather than shared (`design.md` §5).
	 *
	 * @param array<string,mixed> $ledgerGroup The LedgerGroup row.
	 * @param list<array<string,mixed>> $accounts The Account rows to match ranges against.
	 *
	 * @return list<string> The resolved, deduplicated member account numbers.
	 */
	private function resolveMembers(array $ledgerGroup, array $accounts): array {
		$ranges = [];
		if (is_array($ledgerGroup['accountRanges'] ?? null) === true) {
			$ranges = $ledgerGroup['accountRanges'];
		}

		$included = [];
		if (is_array($ledgerGroup['includedAccountNumbers'] ?? null) === true) {
			$included = $ledgerGroup['includedAccountNumbers'];
		}

		$excludedRaw = [];
		if (is_array($ledgerGroup['excludedAccountNumbers'] ?? null) === true) {
			$excludedRaw = $ledgerGroup['excludedAccountNumbers'];
		}

		$excluded = array_flip(array_map('strval', $excludedRaw));

		$members = [];
		foreach ($accounts as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			if ($number === '' || isset($excluded[$number]) === true) {
				continue;
			}

			if ($this->inAnyRange(accountNumber: $number, ranges: $ranges) === true) {
				$members[$number] = true;
			}
		}

		foreach ($included as $number) {
			$number = (string)$number;
			if ($number === '' || isset($excluded[$number]) === true) {
				continue;
			}

			$members[$number] = true;
		}

		// PHP casts a purely-numeric string array key to an int key, so
		// array_keys() would silently hand back integers here — cast back
		// to string explicitly, since every caller compares account
		// numbers as strings.
		return array_values(array_map('strval', array_keys($members)));

	}//end resolveMembers()

	/**
	 * Whether an account number falls inside any of the given range pairs.
	 * Compared numerically (not lexicographically) so a 5-digit account
	 * number cannot fall inside a 4-digit range by string-sort accident.
	 *
	 * @param string $accountNumber The account number to test.
	 * @param list<array{from?:string,to?:string}> $ranges The range pairs.
	 *
	 * @return boolean True when the account number is inside at least one range.
	 */
	private function inAnyRange(string $accountNumber, array $ranges): bool {
		if (is_numeric($accountNumber) === false) {
			return false;
		}

		$value = (int)$accountNumber;
		foreach ($ranges as $range) {
			$from = (string)($range['from'] ?? '');
			$to = (string)($range['to'] ?? '');
			if ($from === '' || $to === '' || is_numeric($from) === false || is_numeric($to) === false) {
				continue;
			}

			if ($value >= (int)$from && $value <= (int)$to) {
				return true;
			}
		}

		return false;

	}//end inAnyRange()

	/**
	 * Resolve, per fiscal year, the id of the default (`isDefault: true`)
	 * `AnnualBudget`, if one exists (`design.md` §7). A year with more than
	 * one row claiming `isDefault: true` resolves to the first encountered
	 * — `AnnualBudgetDefaultGuard` already enforces the one-default
	 * invariant on `activate`, so this is not this reader's own concern to
	 * arbitrate.
	 *
	 * @param list<array<string,mixed>> $annualBudgets The AnnualBudget rows.
	 *
	 * @return array<int,string> Fiscal year => AnnualBudget id (or slug when id is absent).
	 */
	private function buildDefaultAnnualBudgetIndex(array $annualBudgets): array {
		$index = [];
		foreach ($annualBudgets as $annualBudget) {
			if (($annualBudget['isDefault'] ?? false) !== true) {
				continue;
			}

			$year = (int)($annualBudget['fiscalYear'] ?? 0);
			if ($year <= 0 || isset($index[$year]) === true) {
				continue;
			}

			$id = (string)($annualBudget['@self']['id'] ?? $annualBudget['id'] ?? '');
			if ($id === '') {
				$id = (string)($annualBudget['@self']['slug'] ?? $annualBudget['slug'] ?? '');
			}

			if ($id !== '') {
				$index[$year] = $id;
			}
		}

		return $index;

	}//end buildDefaultAnnualBudgetIndex()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * register must not stop a regeneration run from computing whatever it
	 * can.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'KnownCostReader: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$payload = $row->getObject();
				if (is_array($payload) === true) {
					$result[] = $payload;
				}
			}
		}

		return $result;

	}//end query()

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
