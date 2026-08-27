<?php

/**
 * Budget Projection Reader
 *
 * The OpenRegister half of the `budget-projection-engine` change
 * (REQ-BPE-003, REQ-BPE-009): every read the projection engine needs,
 * batched into a fixed, small query count independent of how many
 * accounts, `LedgerGroup`s or months are in scope. {@see BudgetProjectionService}
 * orchestrates, {@see BudgetProjectionCalculator} does the growth-rate
 * arithmetic; this class is the only one that talks to the store.
 *
 * ## Why GLTransaction + GLLine + Account, not TrialBalanceLine
 *
 * `TrialBalanceService`'s own docblock is explicit that no `TrialBalanceLine`
 * row is ever persisted — the schema exists for its OpenAPI shape and 5
 * illustrative seed rows, not as a queryable historical collection
 * (`design.md` §7a). This reader computes directly from `GLTransaction` +
 * `GLLine` + `Account`, exactly as `TrialBalanceService::compute()` does
 * internally, batched across the whole trailing window instead of once per
 * period.
 *
 * ## Query budget: at most 4 `findAll()` calls, independent of scope
 *
 *  1. `Account.findAll([administrationId])` — once.
 *  2. `GLTransaction.findAll([administrationId, state: posted])` — once,
 *     unfiltered by period/date. Builds an in-memory
 *     `transactionRef -> monthKey` index (from `postingDate`, never
 *     `GLLine.periodId` — REQ-BPE-003, `design.md` §2a), dual-keyed by
 *     BOTH the object id and `transactionNumber` — the
 *     {@see BbvProgrammeBudgetReader::transactionRefs()} precedent: keying
 *     only one silently drops every line written by the other writer.
 *  3. `GLLine.findAll([])` — once, unfiltered by period or account. Each
 *     line's month is resolved via (2)'s index; a line whose transaction is
 *     not in the index is skipped. Signed EUR-cent amounts are bucketed by
 *     `(accountNumber, monthKey)` in memory.
 *  4. `LedgerGroup.findAll([administrationId])` — once, only when the
 *     caller requests group-level projections. Each group's member
 *     accounts are resolved here (ranges + explicit include/exclude,
 *     `budget-core-schema design.md` §3a — reused, not redesigned, per
 *     `design.md` §5d).
 *
 * From (3)'s bucketed cents, this reader resolves, per account: its
 * earliest active month (the first month it has ANY line, even a zero-net
 * one) and its `lastActualMonth` (the most recent such month), then trims
 * the nominal trailing-12-month window to the intersection with
 * `[earliestMonth, lastActualMonth]` — dropping months before the earliest
 * active month rather than padding them with a synthetic `0`
 * (REQ-BPE-003, `design.md` §2c). A month WITHIN that active range with no
 * lines is a real `0` (a quiet month), not an absent one; only months
 * strictly before the account's first-ever activity are absent.
 *
 * The running-balance derivation for stock account types
 * (`closingBalance = priorMonth + netMovement`) is deliberately NOT done
 * here — it is pure arithmetic over the bucketed net-movement series this
 * reader already produces, so it lives in
 * {@see BudgetProjectionCalculator::metricSeries()} instead, keeping this
 * class limited to store access and month-bucketing.
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
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-009
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
 * Reads and batches every schema the projection engine needs
 * (REQ-BPE-003, REQ-BPE-009).
 *
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-009
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `loadContext()`'s
 * `$includeLedgerGroups` flag mirrors {@see BudgetVsActualsReader::loadContext()}'s
 * own identical parameter EXACTLY (`design.md` §5d: the same shape,
 * independently reimplemented rather than shared, precisely so this change
 * does not edit that sibling's file) — that reader carries the same
 * unsuppressed PHPMD finding; splitting this reader into two methods would
 * diverge from the reused precedent without fixing the shared root cause.
 */
class BudgetProjectionReader {
	/**
	 * The nominal trailing-window length in calendar months (REQ-BPE-003, `design.md` §2a).
	 *
	 * @var integer
	 */
	private const WINDOW_MONTHS = 12;

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
	 * Load every read the projection engine needs for one administration,
	 * batched to at most 4 `findAll()` calls total (3 when
	 * `$includeLedgerGroups` is false) — REQ-BPE-009.
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param boolean $includeLedgerGroups Whether to load + resolve `LedgerGroup` membership (skips call 4 when false).
	 *
	 * @return array{
	 *     accounts: array<string,array{accountNumber:string,accountType:string}>,
	 *     windowByAccount: array<string,array{months:list<string>,values:list<int>}>,
	 *     lastActualMonthByAccount: array<string,string>,
	 *     ledgerGroupEntries: list<array{id:string,slug:string,memberAccountNumbers:list<string>}>,
	 *     ledgerGroupKeyToIndex: array<string,int>,
	 * } The assembled context {@see BudgetProjectionService} feeds to the calculator.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function loadContext(string $administrationId, bool $includeLedgerGroups = false): array {
		$accountRows = $this->query(schema: self::SCHEMA_ACCOUNT, filters: ['administrationId' => $administrationId]);
		$accounts = [];
		foreach ($accountRows as $row) {
			$number = (string)($row['accountNumber'] ?? '');
			if ($number === '') {
				continue;
			}

			$accounts[$number] = [
				'accountNumber' => $number,
				'accountType' => (string)($row['accountType'] ?? ''),
			];
		}

		$monthByRef = $this->postedTransactionMonths(administrationId: $administrationId);
		$byAccountMonth = $this->bucketByAccountMonth(monthByRef: $monthByRef);
		[$windowByAccount, $lastActualMonthByAccount] = $this->resolveWindows(byAccountMonth: $byAccountMonth);

		$ledgerGroupEntries = [];
		$ledgerGroupKeyToIndex = [];
		if ($includeLedgerGroups === true) {
			$groupRows = $this->query(
				schema: self::SCHEMA_LEDGER_GROUP,
				filters: ['administrationId' => $administrationId]
			);
			// `array_keys()` on a map keyed by a purely-numeric account
			// number (e.g. "1000") hands back an INT, not the string every
			// caller compares account numbers as — cast back explicitly
			// (the same gotcha BudgetVsActualsReader::resolveMembers()
			// documents and guards against).
			[$ledgerGroupEntries, $ledgerGroupKeyToIndex] = $this->buildLedgerGroupIndex(
				rows: $groupRows,
				accountNumbers: array_map('strval', array_keys($accounts))
			);
		}

		return [
			'accounts' => $accounts,
			'windowByAccount' => $windowByAccount,
			'lastActualMonthByAccount' => $lastActualMonthByAccount,
			'ledgerGroupEntries' => $ledgerGroupEntries,
			'ledgerGroupKeyToIndex' => $ledgerGroupKeyToIndex,
		];

	}//end loadContext()

	/**
	 * Index the posting month of every POSTED transaction for this
	 * administration, dual-keyed by object id AND `transactionNumber`
	 * (REQ-BPE-009's `transactionRefs()` precedent) — bucketed from
	 * `postingDate`, never `GLLine.periodId` (REQ-BPE-003, `design.md` §2a:
	 * this repo's own seed data mixes monthly/quarterly/half-year
	 * `periodId` granularities under the same field).
	 *
	 * @param string $administrationId The administration to scope the read to.
	 *
	 * @return array<string,string> Transaction reference => `YYYY-MM` posting month.
	 */
	private function postedTransactionMonths(string $administrationId): array {
		$months = [];
		$transactions = $this->query(
			schema: self::SCHEMA_GL_TRANSACTION,
			filters: ['administrationId' => $administrationId, 'state' => 'posted']
		);

		foreach ($transactions as $transaction) {
			$postingDate = (string)($transaction['postingDate'] ?? '');
			if ($postingDate === '') {
				continue;
			}

			$month = substr($postingDate, 0, 7);
			$objectId = (string)($transaction['@self']['id'] ?? $transaction['id'] ?? '');
			if ($objectId !== '') {
				$months[$objectId] = $month;
			}

			$number = (string)($transaction['transactionNumber'] ?? '');
			if ($number !== '') {
				$months[$number] = $month;
			}
		}

		return $months;

	}//end postedTransactionMonths()

	/**
	 * Bucket every GL line's signed net-movement cents by
	 * `(accountNumber, monthKey)`. A line whose transaction is not in
	 * `$monthByRef` (unposted, or a different administration) is skipped.
	 *
	 * @param array<string,string> $monthByRef Transaction reference => posting month.
	 *
	 * @return array<string,array<string,int>> accountNumber => monthKey => signed net-movement cents.
	 */
	private function bucketByAccountMonth(array $monthByRef): array {
		$buckets = [];
		if ($monthByRef === []) {
			return $buckets;
		}

		$lines = $this->query(schema: self::SCHEMA_GL_LINE, filters: []);
		foreach ($lines as $line) {
			$ref = (string)($line['transactionId'] ?? '');
			if ($ref === '' || isset($monthByRef[$ref]) === false) {
				continue;
			}

			$accountNumber = (string)($line['accountNumber'] ?? '');
			if ($accountNumber === '') {
				continue;
			}

			$month = $monthByRef[$ref];
			$cents = (int)round(((float)($line['amount'] ?? 0)) * 100);
			if ((string)($line['side'] ?? 'debit') === 'credit') {
				$cents = -$cents;
			}

			$buckets[$accountNumber][$month] = (($buckets[$accountNumber][$month] ?? 0) + $cents);
		}

		return $buckets;

	}//end bucketByAccountMonth()

	/**
	 * For each account with any bucketed activity, resolve its earliest and
	 * last active month, then build the trailing window as the
	 * intersection of the nominal 12-month trailing range (ending at the
	 * account's own `lastActualMonth`) with `[earliestMonth,
	 * lastActualMonth]` — shortening the window rather than padding it with
	 * zeros (REQ-BPE-003).
	 *
	 * @param array<string,array<string,int>> $byAccountMonth accountNumber => monthKey => signed net-movement cents.
	 *
	 * @return array{0: array<string,array{months:list<string>,values:list<int>}>, 1: array<string,string>}
	 *         The window per account, and each account's `lastActualMonth`.
	 */
	private function resolveWindows(array $byAccountMonth): array {
		$windowByAccount = [];
		$lastActualMonthByAccount = [];

		foreach ($byAccountMonth as $accountNumber => $monthly) {
			$monthKeys = array_keys($monthly);
			if ($monthKeys === []) {
				continue;
			}

			sort($monthKeys);
			$earliest = $monthKeys[0];
			$last = $monthKeys[count($monthKeys) - 1];
			$lastActualMonthByAccount[$accountNumber] = $last;

			$nominalStart = $this->subtractMonths(month: $last, count: (self::WINDOW_MONTHS - 1));
			$windowStart = max($nominalStart, $earliest);

			$months = [];
			$values = [];
			$cursor = $windowStart;
			$guard = 0;
			while ($cursor <= $last && $guard < self::WINDOW_MONTHS) {
				$months[] = $cursor;
				$values[] = (int)($monthly[$cursor] ?? 0);
				$cursor = $this->nextMonth(month: $cursor);
				$guard++;
			}

			$windowByAccount[$accountNumber] = ['months' => $months, 'values' => $values];
		}//end foreach

		return [$windowByAccount, $lastActualMonthByAccount];

	}//end resolveWindows()

	/**
	 * Build the dual-keyed `LedgerGroup` lookup index, resolving each
	 * group's member accounts against the given account numbers
	 * (`budget-core-schema design.md` §3a's ranges + explicit
	 * include/exclude resolution, reimplemented here rather than shared —
	 * `design.md` §5d records why).
	 *
	 * @param list<array<string,mixed>> $rows The `LedgerGroup` rows.
	 * @param list<string> $accountNumbers The account numbers to resolve membership against.
	 *
	 * @return array{0: list<array{id:string,slug:string,memberAccountNumbers:list<string>}>, 1: array<string,int>}
	 */
	private function buildLedgerGroupIndex(array $rows, array $accountNumbers): array {
		$entries = [];
		$keyToIndex = [];

		foreach ($rows as $row) {
			$id = (string)($row['@self']['id'] ?? $row['id'] ?? '');
			$slug = (string)($row['@self']['slug'] ?? $row['slug'] ?? '');
			$index = count($entries);

			$entries[] = [
				'id' => $id,
				'slug' => $slug,
				'memberAccountNumbers' => $this->resolveMembers(ledgerGroup: $row, accountNumbers: $accountNumbers),
			];

			if ($id !== '') {
				$keyToIndex[$id] = $index;
			}

			if ($slug !== '' && $slug !== $id) {
				$keyToIndex[$slug] = $index;
			}
		}

		return [$entries, $keyToIndex];

	}//end buildLedgerGroupIndex()

	/**
	 * Resolve one `LedgerGroup`'s member account numbers: every account
	 * number falling in an `accountRanges` pair, PLUS every
	 * `includedAccountNumbers` entry, MINUS every `excludedAccountNumbers`
	 * entry (`budget-core-schema design.md` §3a).
	 *
	 * @param array<string,mixed> $ledgerGroup The `LedgerGroup` row.
	 * @param list<string> $accountNumbers The account numbers to match ranges against.
	 *
	 * @return list<string> The resolved, deduplicated member account numbers.
	 */
	private function resolveMembers(array $ledgerGroup, array $accountNumbers): array {
		$ranges = [];
		if (is_array($ledgerGroup['accountRanges'] ?? null) === true) {
			$ranges = $ledgerGroup['accountRanges'];
		}

		$included = [];
		if (is_array($ledgerGroup['includedAccountNumbers'] ?? null) === true) {
			$included = $ledgerGroup['includedAccountNumbers'];
		}

		$excludedList = [];
		if (is_array($ledgerGroup['excludedAccountNumbers'] ?? null) === true) {
			$excludedList = $ledgerGroup['excludedAccountNumbers'];
		}

		$excluded = array_flip(array_map('strval', $excludedList));

		$members = [];
		foreach ($accountNumbers as $number) {
			if (isset($excluded[$number]) === true) {
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
	 * Whether an account number falls inside any of the given range pairs,
	 * compared numerically (not lexicographically) so a 5-digit account
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
	 * Advance a `YYYY-MM` bucket by one calendar month.
	 *
	 * @param string $month The bucket.
	 *
	 * @return string The next bucket.
	 */
	private function nextMonth(string $month): string {
		$year = (int)substr($month, 0, 4);
		$index = (int)substr($month, 5, 2);
		$index++;
		if ($index > 12) {
			$index = 1;
			$year++;
		}

		return sprintf('%04d-%02d', $year, $index);

	}//end nextMonth()

	/**
	 * Step a `YYYY-MM` bucket back by `$count` calendar months.
	 *
	 * @param string $month The bucket.
	 * @param integer $count The number of months to step back.
	 *
	 * @return string The resulting bucket.
	 */
	private function subtractMonths(string $month, int $count): string {
		$year = (int)substr($month, 0, 4);
		$index = (int)substr($month, 5, 2);
		$total = ((($year * 12) + ($index - 1)) - $count);
		$year = intdiv($total, 12);
		$index = (($total % 12) + 1);

		return sprintf('%04d-%02d', $year, $index);

	}//end subtractMonths()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * schema must not stop the engine from computing whatever it can.
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
				'BudgetProjectionReader: failed to query OpenRegister',
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
