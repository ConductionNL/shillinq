<?php

/**
 * Budget vs. Actuals Reader
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The OpenRegister half of `budget-core-schema`'s budget-vs-actuals roll-up
 * (REQ-BCS-008). {@see BudgetVsActualsCalculator} does the arithmetic (the
 * parent-rollup rule, budgeted-vs-actual per line/month); this class is the
 * only one that talks to the store.
 *
 * ## Why GLTransaction + GLLine + Account, not TrialBalanceLine
 *
 * `TrialBalanceService.php`'s own docblock states no `TrialBalanceLine` row
 * is ever persisted — the schema exists for its OpenAPI shape and 5
 * illustrative seed rows, not as a queryable historical collection. A reader
 * expecting real per-period rows there would get near-nothing and silently
 * report actual amounts near zero everywhere
 * (`openspec/changes/budget-core-schema/design.md` §6b). This reader instead
 * computes actuals directly from `GLTransaction` + `GLLine` + `Account`,
 * exactly as `TrialBalanceService::compute()` does internally, but batched
 * across the whole request instead of once per period.
 *
 * ## Query budget: at most 5 `findAll()` calls, independent of scope
 *
 *  1. `Account.findAll([administrationId])` — once.
 *  2. `GLTransaction.findAll([administrationId, state: posted])` — once,
 *     unfiltered by period/date. Builds an in-memory
 *     `transactionRef -> monthKey` index, dual-keyed by BOTH the object id
 *     and `transactionNumber` — the {@see BbvProgrammeBudgetReader
 *     ::postedTransactionMonths()}/`transactionRefs()` precedent: keying
 *     only one silently drops every line written by the other writer.
 *  3. `GLLine.findAll([])` — once, unfiltered by period or account. Each
 *     line's month is resolved via (2)'s index; a line whose transaction is
 *     not in the index is skipped. Amounts are bucketed by
 *     `(accountNumber, monthKey)` in memory, in EUR CENTS (GLLine.amount is
 *     a EUR float; converted here so the calculator can compare like-for-
 *     like against BudgetLine's integer-cents monthly fields).
 *  4. `LedgerGroup.findAll([administrationId])` — once, when any
 *     group-level figure is requested. Each group's member accounts are
 *     resolved here (ranges + explicit include/exclude, §3a), never via a
 *     declarative filter.
 *  5. `BudgetLine.findAll([annualBudgetId: {in: [...]}])` — once, the
 *     `SpendAnalyticsService.php:183` `in`-filter precedent.
 *
 * This is the same batched shape `budget-projection-engine design.md` §7b
 * independently arrived at for its own GL-based reader.
 *
 * ## Triple-keyed LedgerGroup lookups
 *
 * `LedgerGroup.parentLedgerGroupId` and `BudgetLine.ledgerGroupId` may each
 * reference a `LedgerGroup` by its OpenRegister object id, its `@self.slug`, or
 * its `code` (mirrors `GLLine.transactionId`'s own multi-key convention). Every
 * internal `LedgerGroup` lookup index here is keyed by ALL THREE values pointing
 * at the same entry, so a caller or seed using any convention resolves
 * correctly.
 *
 * The shipped seed stores the `code`, and the id/slug branches are deliberately
 * KEPT rather than dead: dropping either would silently reroot any hand-authored
 * row that still uses one. `code` is not merely a convenience — it is the only
 * parent identity the manifest's declarative filter engine can produce, because
 * `@object.<field>` reads a FLAT payload field and cannot reach `@self.slug`,
 * while `@objectId` yields a UUID no seed can know at authoring time. That is
 * what makes the LedgerGroupDetail `children` relatedCollection resolve in the
 * UI; slug-shaped stored values matched nothing and the page rendered
 * "No relations yet".
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads every schema the budget-vs-actuals roll-up needs, batched to at most
 * 5 `findAll()` calls (REQ-BCS-008).
 *
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 */
class BudgetVsActualsReader {
	/**
	 * Chart-of-accounts schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ACCOUNT = 'Account';

	/**
	 * GL transaction (header) schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * GL line schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Ledger group schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_LEDGER_GROUP = 'LedgerGroup';

	/**
	 * Budget line schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET_LINE = 'BudgetLine';

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
	 * Load every read the budget-vs-actuals roll-up needs for one
	 * administration, batched to at most 5 `findAll()` calls total
	 * (4 when `$includeLedgerGroups` is false).
	 *
	 * **Amended for `budget-grid-view` (REQ-BGV-004/REQ-BGV-007), additive
	 * only — no new `findAll()` call:** the raw `accounts` rows fetched by
	 * call (1) above are now also returned verbatim. This class already
	 * fetches them (to resolve `LedgerGroup` membership); previously only
	 * the derived `actualsByAccountMonth`/`ledgerGroupEntries.
	 * memberAccountNumbers` were exposed, dropping each account's own
	 * `accountType`/`name`/id. `BudgetGridReader` needs `Account.accountType`
	 * per resolved member for the deviation sign convention (REQ-BGV-004)
	 * and each account's id/name to render a grootboek leaf row that links to
	 * `ChartOfAccountsDetail` (REQ-BGV-007) — without this it would have to
	 * issue its own separate `Account.findAll()`, breaking the flat query
	 * bound (REQ-BGV-009) for no reason, since the data was already in hand.
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param list<string> $annualBudgetIds The AnnualBudget ids to load BudgetLines for; empty = none loaded.
	 * @param boolean $includeLedgerGroups Whether to load + resolve LedgerGroup membership (skips call 4 when false).
	 *
	 * @return array{
	 *     accounts: list<array<string,mixed>>,
	 *     actualsByAccountMonth: array<string,array<string,int>>,
	 *     ledgerGroupEntries: list<array{id:string,slug:string,parentRef:?string,memberAccountNumbers:list<string>}>,
	 *     ledgerGroupKeyToIndex: array<string,int>,
	 *     ledgerGroupChildrenByIndex: array<int,list<int>>,
	 *     budgetLines: list<array<string,mixed>>,
	 * } The assembled context the calculator consumes.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is REQ-BCS-008's own
	 * call-budget toggle (5 calls with LedgerGroups, 4 without) — splitting it
	 * into two public methods would duplicate the shared account/actuals/
	 * BudgetLine reads and fork the ≤5-call bound this method is tested against.
	 */
	public function loadContext(string $administrationId, array $annualBudgetIds, bool $includeLedgerGroups = true): array {
		$accounts = $this->query(schema: self::SCHEMA_ACCOUNT, filters: ['administrationId' => $administrationId]);

		$monthByRef = $this->postedTransactionMonths(administrationId: $administrationId);
		$actualsByAccountMonth = $this->actualsByAccountMonth(monthByRef: $monthByRef);

		$ledgerGroupContext = ['entries' => [], 'keyToIndex' => [], 'childrenByIndex' => []];
		if ($includeLedgerGroups === true) {
			$rows = $this->query(schema: self::SCHEMA_LEDGER_GROUP, filters: ['administrationId' => $administrationId]);
			$ledgerGroupContext = $this->buildLedgerGroupIndex(rows: $rows, accounts: $accounts);
		}

		$budgetLines = [];
		if ($annualBudgetIds !== []) {
			$budgetLines = $this->query(
				schema: self::SCHEMA_BUDGET_LINE,
				filters: ['annualBudgetId' => ['in' => $annualBudgetIds]]
			);
		}

		return [
			'accounts' => $accounts,
			'actualsByAccountMonth' => $actualsByAccountMonth,
			'ledgerGroupEntries' => $ledgerGroupContext['entries'],
			'ledgerGroupKeyToIndex' => $ledgerGroupContext['keyToIndex'],
			'ledgerGroupChildrenByIndex' => $ledgerGroupContext['childrenByIndex'],
			'budgetLines' => $budgetLines,
		];

	}//end loadContext()

	/**
	 * Index the posting month of every POSTED transaction for this
	 * administration, dual-keyed by object id AND `transactionNumber`.
	 *
	 * @param string $administrationId The administration to scope the read to.
	 *
	 * @return array<string,string> Transaction reference => `YYYY-MM` posting month.
	 */
	private function postedTransactionMonths(string $administrationId): array {
		$transactions = $this->query(
			schema: self::SCHEMA_GL_TRANSACTION,
			filters: ['administrationId' => $administrationId, 'state' => 'posted']
		);

		return (new LedgerActuals())->postingMonths(transactions: $transactions);

	}//end postedTransactionMonths()

	/**
	 * Bucket every GL line's signed amount by `(accountNumber, monthKey)`, in
	 * EUR cents. A line whose transaction is not in `$monthByRef` (unposted,
	 * or a different administration) is skipped.
	 *
	 * @param array<string,string> $monthByRef Transaction reference => posting month.
	 *
	 * @return array<string,array<string,int>> accountNumber => monthKey => signed cents.
	 */
	private function actualsByAccountMonth(array $monthByRef): array {
		if ($monthByRef === []) {
			return [];
		}

		return (new LedgerActuals())->centsByAccountMonth(
			lines: $this->query(schema: self::SCHEMA_GL_LINE, filters: []),
			monthByRef: $monthByRef
		);

	}//end actualsByAccountMonth()

	/**
	 * Build the dual-keyed LedgerGroup lookup index, resolving each group's
	 * member accounts against the given account list (§3a: ranges + explicit
	 * include/exclude, never a declarative filter).
	 *
	 * @param list<array<string,mixed>> $rows The LedgerGroup rows.
	 * @param list<array<string,mixed>> $accounts The Account rows to resolve membership against.
	 *
	 * @return array{entries: list<array{id:string,slug:string,parentRef:?string,memberAccountNumbers:list<string>}>,
	 *         keyToIndex: array<string,int>, childrenByIndex: array<int,list<int>>}
	 */
	private function buildLedgerGroupIndex(array $rows, array $accounts): array {
		$entries = [];
		$keyToIndex = [];
		foreach ($rows as $row) {
			$entry = self::ledgerGroupEntry(row: $row);
			$entry['memberAccountNumbers'] = $this->resolveMembers(ledgerGroup: $row, accounts: $accounts);
			$keyToIndex = self::withEntryKeys(
				keyToIndex: $keyToIndex,
				keys: ['index' => count($entries), 'id' => $entry['id'], 'slug' => $entry['slug'], 'code' => (string)($row['code'] ?? '')]
			);
			$entries[] = $entry;
		}

		return [
			'entries' => $entries,
			'keyToIndex' => $keyToIndex,
			'childrenByIndex' => self::childrenByIndex(entries: $entries, keyToIndex: $keyToIndex),
		];

	}//end buildLedgerGroupIndex()

	/**
	 * The id, slug and parent reference of one LedgerGroup row.
	 *
	 * @param array<string,mixed> $row The LedgerGroup row.
	 *
	 * @return array{id: string, slug: string, parentRef: ?string}
	 */
	private static function ledgerGroupEntry(array $row): array {
		$parentRef = null;
		if (($row['parentLedgerGroupId'] ?? null) !== null) {
			$parentRef = (string)$row['parentLedgerGroupId'];
		}

		return [
			'id' => (string)($row['@self']['id'] ?? $row['id'] ?? ''),
			'slug' => (string)($row['@self']['slug'] ?? $row['slug'] ?? ''),
			'parentRef' => $parentRef,
		];

	}//end ledgerGroupEntry()

	/**
	 * Register an entry under its id, its slug (when distinct) and its code.
	 *
	 * `code` is the THIRD accepted parent key, and the one the shipped seed
	 * actually stores (see the register fragment's parentLedgerGroupId
	 * description). `code` is unique within an administration, which is
	 * exactly the scope the rows cover — LedgerGroup rows are loaded per
	 * administrationId. First writer wins so an id/slug key is never shadowed
	 * by a colliding code.
	 *
	 * @param array<string,int>                                      $keyToIndex The keys so far.
	 * @param array{index: int, id: string, slug: string, code: string} $keys       The entry's index and keys.
	 *
	 * @return array<string,int>
	 */
	private static function withEntryKeys(array $keyToIndex, array $keys): array {
		if ($keys['id'] !== '') {
			$keyToIndex[$keys['id']] = $keys['index'];
		}

		if ($keys['slug'] !== '' && $keys['slug'] !== $keys['id']) {
			$keyToIndex[$keys['slug']] = $keys['index'];
		}

		if ($keys['code'] !== '' && isset($keyToIndex[$keys['code']]) === false) {
			$keyToIndex[$keys['code']] = $keys['index'];
		}

		return $keyToIndex;

	}//end withEntryKeys()

	/**
	 * Child entry indexes per parent index. An unresolved parent reference
	 * leaves the entry a root rather than silently dropping it from the tree.
	 *
	 * @param list<array<string,mixed>> $entries    The entries.
	 * @param array<string,int>         $keyToIndex Key => entry index.
	 *
	 * @return array<int,list<int>>
	 */
	private static function childrenByIndex(array $entries, array $keyToIndex): array {
		$childrenByIndex = [];
		foreach ($entries as $index => $entry) {
			$parentIndex = ($keyToIndex[(string)$entry['parentRef']] ?? null);
			if ($entry['parentRef'] !== null && $entry['parentRef'] !== '' && $parentIndex !== null) {
				$childrenByIndex[$parentIndex][] = $index;
			}
		}

		return $childrenByIndex;

	}//end childrenByIndex()

	/**
	 * Resolve one LedgerGroup's member account numbers: every account whose
	 * number falls in an `accountRanges` pair, PLUS every
	 * `includedAccountNumbers` entry, MINUS every `excludedAccountNumbers`
	 * entry (§3a).
	 *
	 * @param array<string,mixed> $ledgerGroup The LedgerGroup row.
	 * @param list<array<string,mixed>> $accounts The Account rows to match ranges against.
	 *
	 * @return list<string> The resolved, deduplicated member account numbers.
	 */
	private function resolveMembers(array $ledgerGroup, array $accounts): array {
		$accountNumbers = array_map(
			static fn (array $account): string => (string)($account['accountNumber'] ?? ''),
			$accounts
		);

		return (new LedgerGroupMembers(ledgerGroup: $ledgerGroup))->resolve(accountNumbers: $accountNumbers);

	}//end resolveMembers()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * schema must not stop the reader from computing whatever it can.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		$query = new RegisterQuery(
			register: new ShillinqRegister(appConfig: $this->appConfig, objectService: $this->objectService),
			failureLog: new QueryFailureLog(logger: $this->logger, component: 'BudgetVsActualsReader'),
		);

		return $query->rows(schema: $schema, filters: $filters);

	}//end query()
}//end class
