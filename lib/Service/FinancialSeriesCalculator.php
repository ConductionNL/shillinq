<?php

/**
 * Financial Series Calculator
 *
 * Server-side port of the Financial overview dashboard's pure computation
 * layer (src/components/dashboard/financial/financialSeries.js). Every method
 * mirrors its JS counterpart exactly — month bucketing, chart-of-accounts
 * classification (accountType + RGS ^10 heuristic + bank/kas/cash name
 * regex), the GLLine → posted-GLTransaction join (matched on the OR object
 * id OR the human transactionNumber, bucketed by the parent's postingDate),
 * debit/credit sign rules, billable-hours splitting, open AR/AP row mapping
 * and the KPI aggregations — so the numbers returned by the Wave-4 financial
 * endpoints match what the client-side widgets computed.
 *
 * The class is side-effect free: no OpenRegister dependency, every method
 * takes plain arrays and returns plain arrays/scalars so the logic is
 * unit-testable in isolation. FinancialDashboardService wires this helper to
 * live register data.
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
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Side-effect-free financial-dashboard arithmetic (port of financialSeries.js).
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * Pre-existing debt (issue #506): early-return refactor deferred pending
 * full behavioral verification of each branch.
 */
class FinancialSeriesCalculator {
	/**
	 * AR lifecycle states that count as an open receivable.
	 *
	 * @var array<int,string>
	 */
	public const OPEN_AR_STATES = ['issued', 'overdue'];

	/**
	 * AP states that count as an open payable.
	 *
	 * @var array<int,string>
	 */
	public const OPEN_AP_STATES = ['received', 'issued', 'partially-paid', 'overdue'];

	/**
	 * Maximum number of month buckets a from/to range may expand to.
	 *
	 * @var int
	 */
	private const MAX_RANGE_MONTHS = 60;

	/**
	 * Month bucket key (`YYYY-MM`) for a date-ish string, or null when the
	 * value does not start with a parsable year-month.
	 *
	 * @param mixed $dateStr Date string (ISO or `YYYY-MM-DD hh:mm:ss`).
	 *
	 * @return string|null The month key or null.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function monthKey(mixed $dateStr): ?string {
		if (is_string($dateStr) === false) {
			return null;
		}

		if (preg_match('/^(\d{4})-(\d{2})/', $dateStr, $matches) !== 1) {
			return null;
		}

		return $matches[1] . '-' . $matches[2];
	}//end monthKey()

	/**
	 * Trailing `count` month keys ending at `now`, ascending.
	 *
	 * @param int $count Number of months.
	 * @param DateTimeImmutable $now Reference date.
	 *
	 * @return array<int,string> Month keys, ascending.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function lastMonths(int $count, DateTimeImmutable $now): array {
		$months = [];
		$index = (((int)$now->format('Y')) * 12) + ((int)$now->format('n') - 1);
		for ($i = ($count - 1); $i >= 0; $i--) {
			$months[] = $this->monthKeyFromIndex(index: ($index - $i));
		}

		return $months;
	}//end lastMonths()

	/**
	 * All month keys (`YYYY-MM`) from the month containing `from` through the
	 * month containing `to`, ascending. Capped at 60 months. Invalid or
	 * missing bounds yield an empty list (the caller falls back to a trailing
	 * window), exactly like the client's monthsInRange().
	 *
	 * @param string $from ISO-8601 lower bound.
	 * @param string $to ISO-8601 upper bound.
	 *
	 * @return array<int,string> Month keys, ascending.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function monthsInRange(string $from, string $to): array {
		if ($from === '' || $to === '') {
			return [];
		}

		$start = $this->parseDay(value: substr($from, 0, 10));
		$end = $this->parseDay(value: substr($to, 0, 10));
		if ($start === null || $end === null) {
			return [];
		}

		return $this->monthKeysBetween(start: $start, endKey: $end->format('Y-m'));
	}//end monthsInRange()

	/**
	 * Month keys from a start day's month up to an end key, at most
	 * {@see MAX_RANGE_MONTHS}.
	 *
	 * @param DateTimeImmutable $start  The first day.
	 * @param string            $endKey The last month key.
	 *
	 * @return array<int,string>
	 *
	 * @SuppressWarnings(PHPMD.CountInLoopExpression) $months grows inside the
	 *     loop body — the loop bound is genuinely the running count, it
	 *     cannot be hoisted.
	 */
	private function monthKeysBetween(DateTimeImmutable $start, string $endKey): array {
		$months = [];
		$index  = (((int)$start->format('Y')) * 12) + ((int)$start->format('n') - 1);
		while (count($months) < self::MAX_RANGE_MONTHS) {
			$key = $this->monthKeyFromIndex(index: $index);
			if ($key > $endKey) {
				break;
			}

			$months[] = $key;
			$index++;
		}

		return $months;
	}//end monthKeysBetween()

	/**
	 * Classify the chart of accounts into the sets the dashboard needs.
	 * Liquid assets are `assets` accounts that look like bank/cash: RGS-style
	 * account numbers starting with `10`, or a bank/kas/cash name.
	 *
	 * The sets are returned as maps keyed by account number (PHP's set
	 * idiom); numeric account numbers become integer keys, which membership
	 * lookups transparently cast back.
	 *
	 * @param array<int,array<string,mixed>> $accounts Account objects from the register.
	 *
	 * @return array{revenue: array<int|string,bool>, expenses: array<int|string,bool>, liquid: array<int|string,bool>}
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function classifyAccounts(array $accounts): array {
		$classes = ['revenue' => [], 'expenses' => [], 'liquid' => []];
		foreach ($accounts as $account) {
			$number = $this->toStringValue(value: $account['accountNumber'] ?? null);
			$class  = $this->accountClass(account: $account, number: $number);
			if ($class !== null) {
				$classes[$class][$number] = true;
			}
		}

		return $classes;

	}//end classifyAccounts()

	/**
	 * The dashboard class of one account: revenue, expenses, or liquid for an
	 * asset that looks like bank/cash (number starting with `10`, or a
	 * bank/kas/cash name); null otherwise or without a number.
	 *
	 * @param array<string,mixed> $account The Account object.
	 * @param string              $number  Its account number.
	 *
	 * @return string|null
	 */
	private function accountClass(array $account, string $number): ?string {
		$type = ($account['accountType'] ?? null);
		if ($number === '' || in_array($type, ['revenue', 'expenses', 'assets'], true) === false) {
			return null;
		}

		if ($type !== 'assets') {
			return $type;
		}

		if ($this->looksLiquid(number: $number, name: $this->toStringValue(value: $account['name'] ?? null)) === true) {
			return 'liquid';
		}

		return null;
	}//end accountClass()

	/**
	 * Whether an asset account looks like bank/cash: its number starts with
	 * `10`, or its name says bank, kas or cash.
	 *
	 * @param string $number The account number.
	 * @param string $name   The account name.
	 *
	 * @return bool
	 */
	private function looksLiquid(string $number, string $name): bool {
		return preg_match('/^10/', $number) === 1 || preg_match('/\b(bank|kas|cash)\b/i', $name) === 1;
	}//end looksLiquid()

	/**
	 * Index posted GL lines by month. Lines are matched to their parent
	 * transaction via `transactionId` against both the OR object id and the
	 * human `transactionNumber`; only `state: "posted"` parents contribute,
	 * and the parent's `postingDate` decides the bucket.
	 *
	 * @param array<int,array<string,mixed>> $transactions GLTransaction objects.
	 * @param array<int,array<string,mixed>> $lines GLLine objects.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Month key => lines posted in that month.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function postedLinesByMonth(array $transactions, array $lines): array {
		$byRef   = $this->postedByReference(transactions: $transactions);
		$buckets = [];
		foreach ($lines as $line) {
			$ref = $this->toStringValue(value: $line['transactionId'] ?? null);
			$key = null;
			if (isset($byRef[$ref]) === true) {
				$key = $this->monthKey(dateStr: ($byRef[$ref]['postingDate'] ?? null));
			}

			if ($key !== null) {
				$buckets[$key][] = $line;
			}
		}

		return $buckets;
	}//end postedLinesByMonth()

	/**
	 * The posted transactions keyed by their OR object id and by their
	 * transactionNumber.
	 *
	 * @param array<int,array<string,mixed>> $transactions GLTransaction objects.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function postedByReference(array $transactions): array {
		$byRef = [];
		foreach ($transactions as $transaction) {
			if (($transaction['state'] ?? null) !== 'posted') {
				continue;
			}

			foreach ([$transaction['@self']['id'] ?? $transaction['id'] ?? null, $transaction['transactionNumber'] ?? null] as $reference) {
				$reference = $this->toStringValue(value: $reference);
				if ($reference !== '') {
					$byRef[$reference] = $transaction;
				}
			}
		}

		return $byRef;
	}//end postedByReference()

	/**
	 * Signed contribution of a GL line for an account class. Revenue grows on
	 * credit; expenses grow on debit; liquid assets grow on debit (money in).
	 *
	 * @param array<string,mixed> $line GLLine object.
	 * @param string $kind Account class ('revenue'|'expenses'|'liquid').
	 *
	 * @return float The signed amount.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function signedAmount(array $line, string $kind): float {
		$amount = $this->toFloat(value: $line['amount'] ?? null);
		$sign = -1.0;
		if (($line['side'] ?? null) === 'credit') {
			$sign = 1.0;
		}

		if ($kind === 'revenue') {
			return ($sign * $amount);
		}

		return (-$sign * $amount);
	}//end signedAmount()

	/**
	 * Monthly turnover / cost / margin / cashflow series over `months`, from
	 * posted GL lines classified by the chart of accounts.
	 *
	 * @param array<string,mixed> $input Bag with accounts, transactions, lines and months keys.
	 *
	 * @return array<string,mixed> The months plus the revenue / costs / margin /
	 *                             marginPct / cashIn / cashOut / cashNet parallel arrays.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function monthlyFinancialSeries(array $input): array {
		$months  = ($input['months'] ?? []);
		$classes = $this->classifyAccounts(accounts: ($input['accounts'] ?? []));
		$buckets = $this->postedLinesByMonth(
			transactions: ($input['transactions'] ?? []),
			lines: ($input['lines'] ?? [])
		);

		$result = ['months' => $months] + array_fill_keys(['revenue', 'costs', 'margin', 'marginPct', 'cashIn', 'cashOut', 'cashNet'], []);
		foreach ($months as $key) {
			foreach ($this->monthFigures(totals: $this->monthTotals(lines: ($buckets[$key] ?? []), classes: $classes)) as $series => $value) {
				$result[$series][] = $value;
			}
		}

		return $result;
	}//end monthlyFinancialSeries()

	/**
	 * One month's revenue, costs, cash in and cash out from its posted lines.
	 *
	 * @param array<int,array<string,mixed>>             $lines   The month's posted lines.
	 * @param array<string,array<int|string,bool>>       $classes The account classes (revenue, expenses, liquid).
	 *
	 * @return array{revenue: float, costs: float, cashIn: float, cashOut: float}
	 */
	private function monthTotals(array $lines, array $classes): array {
		$totals = ['revenue' => 0.0, 'costs' => 0.0, 'cashIn' => 0.0, 'cashOut' => 0.0];
		foreach ($lines as $line) {
			$number = $this->toStringValue(value: $line['accountNumber'] ?? null);
			if (isset($classes['revenue'][$number]) === true) {
				$totals['revenue'] += $this->signedAmount(line: $line, kind: 'revenue');
			} elseif (isset($classes['expenses'][$number]) === true) {
				$totals['costs'] += $this->signedAmount(line: $line, kind: 'expenses');
			}

			if (isset($classes['liquid'][$number]) === true) {
				$totals = $this->withCashMovement(totals: $totals, movement: $this->signedAmount(line: $line, kind: 'liquid'));
			}
		}

		return $totals;
	}//end monthTotals()

	/**
	 * The totals with a liquid movement booked as cash in (positive or zero)
	 * or cash out.
	 *
	 * @param array{revenue: float, costs: float, cashIn: float, cashOut: float} $totals   The month's totals.
	 * @param float                                                              $movement The signed liquid movement.
	 *
	 * @return array{revenue: float, costs: float, cashIn: float, cashOut: float}
	 */
	private function withCashMovement(array $totals, float $movement): array {
		if ($movement >= 0) {
			$totals['cashIn'] += $movement;
			return $totals;
		}

		$totals['cashOut'] += -$movement;
		return $totals;
	}//end withCashMovement()

	/**
	 * One month's series values, rounded: revenue, costs, margin, margin
	 * percentage (null without revenue) and cashflow.
	 *
	 * @param array{revenue: float, costs: float, cashIn: float, cashOut: float} $totals The month's totals.
	 *
	 * @return array<string,float|null>
	 */
	private function monthFigures(array $totals): array {
		$margin = ($totals['revenue'] - $totals['costs']);

		return [
			'revenue' => $this->round2(value: $totals['revenue']),
			'costs' => $this->round2(value: $totals['costs']),
			'margin' => $this->round2(value: $margin),
			'marginPct' => $this->percentOf(part: $margin, whole: $totals['revenue']),
			'cashIn' => $this->round2(value: $totals['cashIn']),
			'cashOut' => $this->round2(value: $totals['cashOut']),
			'cashNet' => $this->round2(value: $totals['cashIn'] - $totals['cashOut']),
		];
	}//end monthFigures()

	/**
	 * Monthly billable vs non-billable hours from UrenRegistratie.
	 * Billable = `recognisedRate` greater than zero.
	 *
	 * @param array<int,array<string,mixed>> $entries UrenRegistratie objects.
	 * @param array<int,string> $months Month keys to bucket into.
	 *
	 * @return array{billable: array<int,float>, nonBillable: array<int,float>, pct: array<int,float|null>}
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function billableSeries(array $entries, array $months): array {
		$hours = $this->hoursByMonth(entries: $entries);
		$totalByMonth    = $hours['total'];
		$billableByMonth = $hours['billable'];

		$billable = [];
		$nonBillable = [];
		$pct = [];
		foreach ($months as $key) {
			$total = ($totalByMonth[$key] ?? 0.0);
			$bill = ($billableByMonth[$key] ?? 0.0);
			$billable[] = $this->round2(value: $bill);
			$nonBillable[] = $this->round2(value: $total - $bill);
			$pct[] = $this->percentOf(part: $bill, whole: $total);
		}

		return [
			'billable' => $billable,
			'nonBillable' => $nonBillable,
			'pct' => $pct,
		];

	}//end billableSeries()

	/**
	 * Total and billable hours per month; billable = `recognisedRate` above
	 * zero, and an entry without a readable date is skipped.
	 *
	 * @param array<int,array<string,mixed>> $entries UrenRegistratie objects.
	 *
	 * @return array{total: array<string,float>, billable: array<string,float>}
	 */
	private function hoursByMonth(array $entries): array {
		$byMonth = ['total' => [], 'billable' => []];
		foreach ($entries as $entry) {
			$key = $this->monthKey(dateStr: ($entry['date'] ?? null));
			if ($key === null) {
				continue;
			}

			$hours = $this->toFloat(value: $entry['hours'] ?? null);
			$byMonth['total'][$key] = (($byMonth['total'][$key] ?? 0.0) + $hours);
			if ($this->toFloat(value: $entry['recognisedRate'] ?? null) > 0) {
				$byMonth['billable'][$key] = (($byMonth['billable'][$key] ?? 0.0) + $hours);
			}
		}

		return $byMonth;
	}//end hoursByMonth()

	/**
	 * Open-receivables table rows from ARInvoice objects, sorted by due date
	 * ascending. `overdue` is set when the state says so or the due date has
	 * passed.
	 *
	 * @param array<int,array<string,mixed>> $invoices ARInvoice objects.
	 * @param array<int,array<string,mixed>> $customers CustomerMaster objects (name lookup).
	 * @param DateTimeImmutable $now Reference date for the overdue flag.
	 *
	 * @return array<int,array<string,mixed>> The open AR rows.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function openArRows(array $invoices, array $customers, DateTimeImmutable $now): array {
		$table = new OpenItemsTable();
		$table->stateField   = 'lifecycleState';
		$table->openStates   = self::OPEN_AR_STATES;
		$table->partyField   = 'customerId';
		$table->amountFields = ['grossAmount', 'netAmount'];
		$table->names        = $this->partyNames(parties: $customers, fields: ['customerId', 'legalName', 'tradeName']);
		$table->now          = $now;

		return $this->openRows(table: $table, records: $invoices);
	}//end openArRows()

	/**
	 * Open-payables table rows from APTransaction objects, sorted by due date
	 * ascending.
	 *
	 * @param array<int,array<string,mixed>> $transactions APTransaction objects.
	 * @param array<int,array<string,mixed>> $vendors Payee objects (name lookup).
	 * @param DateTimeImmutable $now Reference date for the overdue flag.
	 *
	 * @return array<int,array<string,mixed>> The open AP rows.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function openApRows(array $transactions, array $vendors, DateTimeImmutable $now): array {
		$table = new OpenItemsTable();
		$table->stateField   = 'state';
		$table->openStates   = self::OPEN_AP_STATES;
		$table->partyField   = 'vendorId';
		$table->amountFields = ['totalAmount'];
		$table->names        = $this->partyNames(parties: $vendors, fields: ['vendorNumber', 'name', 'tradingName']);
		$table->now          = $now;

		return $this->openRows(table: $table, records: $transactions);
	}//end openApRows()

	/**
	 * Party id => name: the id field (else the object id), the first name
	 * field, falling back to the second when empty; a later party with the
	 * same id wins.
	 *
	 * @param array<int,array<string,mixed>> $parties The party objects.
	 * @param array{0:string,1:string,2:string}  $fields  The id, name and fallback-name fields.
	 *
	 * @return array<string,string>
	 */
	private function partyNames(array $parties, array $fields): array {
		$names = [];
		foreach ($parties as $party) {
			$key  = $this->toStringValue(value: $party[$fields[0]] ?? $party['@self']['id'] ?? null);
			$name = $this->toStringValue(value: $party[$fields[1]] ?? null);
			if ($name === '') {
				$name = $this->toStringValue(value: $party[$fields[2]] ?? null);
			}

			$names[$key] = $name;
		}

		return $names;
	}//end partyNames()

	/**
	 * The open rows of a table, sorted by due date ascending.
	 *
	 * @param OpenItemsTable                 $table   What the table reads.
	 * @param array<int,array<string,mixed>> $records The invoices or transactions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function openRows(OpenItemsTable $table, array $records): array {
		$rows = [];
		foreach ($records as $record) {
			$state = $this->toStringValue(value: $record[$table->stateField] ?? null);
			if (in_array($state, $table->openStates, true) === true) {
				$rows[] = $this->openRow(table: $table, record: $record);
			}
		}

		usort(
			$rows,
			static function (array $left, array $right): int {
				return strcmp((string)$left['dueDate'], (string)$right['dueDate']);
			}
		);

		return $rows;
	}//end openRows()

	/**
	 * One open row: the party's name (else its id), and `overdue` when the
	 * state says so or the due date has passed.
	 *
	 * @param OpenItemsTable      $table  What the table reads.
	 * @param array<string,mixed> $record One open invoice or transaction.
	 *
	 * @return array<string,mixed>
	 */
	private function openRow(OpenItemsTable $table, array $record): array {
		$state   = $this->toStringValue(value: $record[$table->stateField] ?? null);
		$partyId = $this->toStringValue(value: $record[$table->partyField] ?? null);
		$party   = ($table->names[$partyId] ?? '');
		if ($party === '') {
			$party = $partyId;
		}

		$amount = null;
		foreach ($table->amountFields as $field) {
			$amount = ($amount ?? $record[$field] ?? null);
		}

		return [
			'id' => ($record['@self']['id'] ?? $record['id'] ?? null),
			'invoiceNumber' => $this->toStringValue(value: $record['invoiceNumber'] ?? null),
			'party' => $party,
			'invoiceDate' => $this->toStringValue(value: $record['invoiceDate'] ?? null),
			'dueDate' => $this->toStringValue(value: $record['dueDate'] ?? null),
			'amount' => $this->toFloat(value: $amount),
			'state' => $state,
			'overdue' => ($state === 'overdue' || $this->isPastDue(dueDate: ($record['dueDate'] ?? null), now: $table->now)),
		];
	}//end openRow()

	/**
	 * The six KPI-strip metrics: YTD turnover/margin, open AR/AP, the current
	 * month's billable share, and the all-time cash position (net of every
	 * posted liquid movement).
	 *
	 * @param array<string,mixed> $data Bag with accounts, transactions, lines, arInvoices, apTransactions, hourEntries.
	 * @param DateTimeImmutable $now Reference date (YTD window + current month).
	 *
	 * @return array<string,mixed> The KPI bag.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function computeKpis(array $data, DateTimeImmutable $now): array {
		$ytd    = $this->turnoverAndMargin(data: $data, months: $this->yearToDate(now: $now));
		$openAr = $this->openArRows(invoices: ($data['arInvoices'] ?? []), customers: [], now: $now);
		$openAp = $this->openApRows(transactions: ($data['apTransactions'] ?? []), vendors: [], now: $now);
		$billable = $this->billableSeries(entries: ($data['hourEntries'] ?? []), months: [$now->format('Y-m')]);

		return [
			'turnoverYtd' => $ytd['turnover'],
			'marginYtd' => $ytd['margin'],
			'marginPctYtd' => $ytd['marginPct'],
			'openArAmount' => $this->round2(value: $this->sumValues(values: array_column($openAr, 'amount'))),
			'openArCount' => count($openAr),
			'openApAmount' => $this->round2(value: $this->sumValues(values: array_column($openAp, 'amount'))),
			'openApCount' => count($openAp),
			'billableHours' => $billable['billable'][0],
			'billablePct' => $billable['pct'][0],
			'cashPosition' => $this->round2(value: $this->allTimeCash(data: $data)),
		];

	}//end computeKpis()

	/**
	 * The months of the year up to and including `now`'s month.
	 *
	 * @param DateTimeImmutable $now Reference date.
	 *
	 * @return list<string> Month keys, ascending.
	 */
	private function yearToDate(DateTimeImmutable $now): array {
		$year   = (int)$now->format('Y');
		$months = [];
		for ($month = 1; $month <= (int)$now->format('n'); $month++) {
			$months[] = sprintf('%04d-%02d', $year, $month);
		}

		return $months;
	}//end yearToDate()

	/**
	 * The all-time cash position: net of every posted liquid movement.
	 *
	 * @param array<string,mixed> $data Bag with accounts, transactions and lines.
	 *
	 * @return float
	 */
	private function allTimeCash(array $data): float {
		$liquid = $this->classifyAccounts(accounts: ($data['accounts'] ?? []))['liquid'];
		$cash   = 0.0;
		foreach ($this->postedLinesByMonth(transactions: ($data['transactions'] ?? []), lines: ($data['lines'] ?? [])) as $monthLines) {
			foreach ($monthLines as $line) {
				if (isset($liquid[$this->toStringValue(value: $line['accountNumber'] ?? null)]) === true) {
					$cash += $this->signedAmount(line: $line, kind: 'liquid');
				}
			}
		}

		return $cash;
	}//end allTimeCash()

	/**
	 * The cash position per bank account and combined (REQ-BCON-004).
	 *
	 * Uses the same liquid classification and sign as computeKpis(), so with
	 * every bank account on a liquid ledger account the total equals its
	 * `cashPosition`. A ledger account a bank account names counts as liquid
	 * too. Liquid ledger accounts no bank account names are summed on one
	 * `other` line. The bank balance is the closing balance of the account's
	 * most recent statement that carries one.
	 *
	 * @param array<string,mixed>            $data         `accounts`, `transactions`, `lines`.
	 * @param array<int,array<string,mixed>> $bankAccounts BankAccount records.
	 * @param array<int,array<string,mixed>> $statements   BankStatement records.
	 *
	 * @return array{accounts:array<int,array<string,mixed>>,other:float,total:float}
	 *
	 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
	 */
	public function cashPositionByAccount(array $data, array $bankAccounts, array $statements): array {
		$liquid = $this->classifyAccounts(accounts: ($data['accounts'] ?? []))['liquid'];
		foreach ($bankAccounts as $bankAccount) {
			$named = $this->toStringValue(value: $bankAccount['ledgerAccountNumber'] ?? null);
			if ($named !== '') {
				$liquid[$named] = true;
			}
		}

		$balances = $this->liquidBalances(data: $data, liquid: $liquid);
		$rows     = $this->withBankBalances(rows: $this->bankAccountRows(bankAccounts: $bankAccounts, balances: $balances), statements: $statements);
		$other    = $this->sumValues(values: array_diff_key($balances, array_flip(array_column($rows, 'ledgerAccountNumber'))));

		$total = $this->sumValues(values: array_column($rows, 'ledgerBalance')) + $other;
		return ['accounts' => $rows, 'other' => $this->round2(value: $other), 'total' => $this->round2(value: $total)];

	}//end cashPositionByAccount()

	/**
	 * The posted balance per liquid ledger account, in first-posted order.
	 *
	 * @param array<string,mixed>       $data   Bag with transactions and lines.
	 * @param array<int|string,bool>    $liquid The liquid account numbers.
	 *
	 * @return array<int|string,float>
	 */
	private function liquidBalances(array $data, array $liquid): array {
		$balances = [];
		foreach ($this->postedLinesByMonth(transactions: ($data['transactions'] ?? []), lines: ($data['lines'] ?? [])) as $monthLines) {
			foreach ($monthLines as $line) {
				$number = $this->toStringValue(value: $line['accountNumber'] ?? null);
				if (isset($liquid[$number]) === true) {
					$balances[$number] = (($balances[$number] ?? 0.0) + $this->signedAmount(line: $line, kind: 'liquid'));
				}
			}
		}

		return $balances;
	}//end liquidBalances()

	/**
	 * One row per bank account with its ledger balance.
	 *
	 * @param array<int,array<string,mixed>> $bankAccounts BankAccount records.
	 * @param array<int|string,float>        $balances     Posted balance per ledger account.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function bankAccountRows(array $bankAccounts, array $balances): array {
		$rows = [];
		foreach ($bankAccounts as $bankAccount) {
			$ledger = $this->toStringValue(value: $bankAccount['ledgerAccountNumber'] ?? null);
			$rows[] = [
				'bankAccountId' => $this->toStringValue(value: $bankAccount['id'] ?? ($bankAccount['@self']['id'] ?? null)),
				'accountName' => $this->toStringValue(value: $bankAccount['accountName'] ?? null),
				'iban' => strtoupper(str_replace(' ', '', $this->toStringValue(value: $bankAccount['iban'] ?? null))),
				'ledgerAccountNumber' => $ledger,
				'ledgerBalance' => $this->round2(value: ($balances[$ledger] ?? 0.0)),
			];
		}

		return $rows;
	}//end bankAccountRows()

	/**
	 * The rows with the closing balance and date of each IBAN's most recent
	 * statement that carries one.
	 *
	 * @param array<int,array<string,mixed>> $rows       The bank account rows.
	 * @param array<int,array<string,mixed>> $statements BankStatement records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function withBankBalances(array $rows, array $statements): array {
		$latest = $this->latestClosingBalances(statements: $statements);
		foreach ($rows as $index => $row) {
			$rows[$index]['bankBalance']     = ($latest[$row['iban']]['balance'] ?? null);
			$rows[$index]['bankBalanceDate'] = ($latest[$row['iban']]['date'] ?? null);
		}

		return $rows;
	}//end withBankBalances()

	/**
	 * The most recent closing balance per IBAN.
	 *
	 * @param array<int,array<string,mixed>> $statements BankStatement records.
	 *
	 * @return array<string,array{balance:float,date:string}>
	 */
	private function latestClosingBalances(array $statements): array {
		$latest = [];
		foreach ($statements as $statement) {
			if (is_numeric($statement['closingBalance'] ?? null) === false) {
				continue;
			}

			$iban = strtoupper(str_replace(' ', '', $this->toStringValue(value: $statement['bankAccountIban'] ?? null)));
			$date = $this->toStringValue(value: $statement['statementDate'] ?? null);
			if ($iban === '' || (isset($latest[$iban]) === true && strcmp($latest[$iban]['date'], $date) >= 0)) {
				continue;
			}

			$latest[$iban] = ['balance' => (float)$statement['closingBalance'], 'date' => $date];
		}

		return $latest;

	}//end latestClosingBalances()

	/**
	 * Range-driven KPI metrics: turnover, margin (EUR + %) and billable
	 * (hours + %) aggregated over an explicit list of month buckets rather
	 * than the fixed year-to-date / current-month windows computeKpis() uses.
	 * The point-in-time metrics (open debtors/creditors, cash balance) do not
	 * vary by range and stay in computeKpis().
	 *
	 * @param array<string,mixed> $data Bag with accounts, transactions, lines, hourEntries.
	 * @param array<int,string> $months Month keys (`YYYY-MM`) to aggregate over.
	 *
	 * @return array{turnover: float, margin: float, marginPct: float|null, billableHours: float, billablePct: float|null}
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function computeRangeKpis(array $data, array $months): array {
		$figures = $this->turnoverAndMargin(data: $data, months: $months);

		$bill          = $this->billableSeries(entries: ($data['hourEntries'] ?? []), months: $months);
		$billableHours = $this->sumValues(values: $bill['billable']);
		$totalHours    = ($billableHours + $this->sumValues(values: $bill['nonBillable']));

		return $figures + [
			'billableHours' => $this->round2(value: $billableHours),
			'billablePct' => $this->percentOf(part: $billableHours, whole: $totalHours),
		];

	}//end computeRangeKpis()

	/**
	 * Turnover and margin (EUR, rounded) over months, and the margin
	 * percentage of the rounded figures (null without turnover).
	 *
	 * @param array<string,mixed> $data   Bag with accounts, transactions and lines.
	 * @param array<int,string>   $months Month keys to aggregate over.
	 *
	 * @return array{turnover: float, margin: float, marginPct: float|null}
	 */
	private function turnoverAndMargin(array $data, array $months): array {
		$series = $this->monthlyFinancialSeries(
			input: [
				'accounts' => ($data['accounts'] ?? []),
				'transactions' => ($data['transactions'] ?? []),
				'lines' => ($data['lines'] ?? []),
				'months' => $months,
			]
		);
		$turnover = $this->round2(value: $this->sumValues(values: $series['revenue']));
		$margin   = $this->round2(value: $this->sumValues(values: $series['margin']));

		return ['turnover' => $turnover, 'margin' => $margin, 'marginPct' => $this->percentOf(part: $margin, whole: $turnover)];
	}//end turnoverAndMargin()

	/**
	 * A share as a rounded percentage, or null when the whole is not positive.
	 *
	 * @param float $part  The part.
	 * @param float $whole The whole.
	 *
	 * @return float|null
	 */
	private function percentOf(float $part, float $whole): ?float {
		if ($whole > 0) {
			return $this->round2(value: ($part / $whole) * 100);
		}

		return null;
	}//end percentOf()

	/**
	 * Whether a due date lies strictly before `now`'s UTC calendar date
	 * (mirrors the client's `dueDate.slice(0,10) < now.toISOString().slice(0,10)`).
	 *
	 * @param mixed $dueDate Due date string.
	 * @param DateTimeImmutable $now Reference date.
	 *
	 * @return bool True when past due.
	 */
	private function isPastDue(mixed $dueDate, DateTimeImmutable $now): bool {
		if (is_string($dueDate) === false) {
			return false;
		}

		$key = substr($dueDate, 0, 10);
		if ($key === '') {
			return false;
		}

		$today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');

		return ($key < $today);
	}//end isPastDue()

	/**
	 * Month key for a zero-based absolute month index (year * 12 + month - 1).
	 *
	 * @param int $index Absolute month index.
	 *
	 * @return string `YYYY-MM` key.
	 */
	private function monthKeyFromIndex(int $index): string {
		return sprintf('%04d-%02d', intdiv($index, 12), (($index % 12) + 1));
	}//end monthKeyFromIndex()

	/**
	 * Strict `Y-m-d` day parser; null for malformed or impossible dates
	 * (mirrors the client's Invalid Date guard).
	 *
	 * @param string $value Candidate day string.
	 *
	 * @return DateTimeImmutable|null Parsed day or null.
	 */
	private function parseDay(string $value): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) {
			return null;
		}

		return $date;
	}//end parseDay()

	/**
	 * Numeric coercion mirroring the client's `Number(x) || 0`.
	 *
	 * @param mixed $value Candidate numeric value.
	 *
	 * @return float The float value, 0.0 for non-numerics.
	 */
	private function toFloat(mixed $value): float {
		if (is_numeric($value) === true) {
			return (float)$value;
		}

		return 0.0;
	}//end toFloat()

	/**
	 * Scalar-to-string coercion mirroring the client's `String(x ?? '')`.
	 *
	 * @param mixed $value Candidate scalar value.
	 *
	 * @return string The string value, '' for null/non-scalars.
	 */
	private function toStringValue(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end toStringValue()

	/**
	 * Sum a list of numeric values, coercing non-numerics to zero.
	 *
	 * @param array<int,mixed> $values The values.
	 *
	 * @return float The sum.
	 */
	private function sumValues(array $values): float {
		$total = 0.0;
		foreach ($values as $value) {
			$total += $this->toFloat(value: $value);
		}

		return $total;
	}//end sumValues()

	/**
	 * Round to two decimals (mirrors the client's Math.round(v*100)/100).
	 *
	 * @param float $value The value.
	 *
	 * @return float The rounded value.
	 */
	private function round2(float $value): float {
		return (round($value * 100) / 100);
	}//end round2()
}//end class
