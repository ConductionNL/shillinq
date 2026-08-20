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
	 *
	 * @SuppressWarnings(PHPMD.CountInLoopExpression) $months grows inside the
	 *     loop body — the loop bound is genuinely the running count, it
	 *     cannot be hoisted.
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

		$months = [];
		$index = (((int)$start->format('Y')) * 12) + ((int)$start->format('n') - 1);
		$endKey = $end->format('Y-m');
		while (count($months) < self::MAX_RANGE_MONTHS) {
			$key = $this->monthKeyFromIndex(index: $index);
			if ($key > $endKey) {
				break;
			}

			$months[] = $key;
			$index++;
		}

		return $months;
	}//end monthsInRange()

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
		$revenue = [];
		$expenses = [];
		$liquid = [];
		foreach ($accounts as $account) {
			$number = $this->toStringValue(value: $account['accountNumber'] ?? null);
			if ($number === '') {
				continue;
			}

			$type = ($account['accountType'] ?? null);
			$name = $this->toStringValue(value: $account['name'] ?? null);
			if ($type === 'revenue') {
				$revenue[$number] = true;
			} elseif ($type === 'expenses') {
				$expenses[$number] = true;
			} elseif ($type === 'assets'
				&& (preg_match('/^10/', $number) === 1
				|| preg_match('/\b(bank|kas|cash)\b/i', $name) === 1)
			) {
				$liquid[$number] = true;
			}
		}//end foreach

		return [
			'revenue' => $revenue,
			'expenses' => $expenses,
			'liquid' => $liquid,
		];

	}//end classifyAccounts()

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
		$byRef = [];
		foreach ($transactions as $transaction) {
			if (($transaction['state'] ?? null) !== 'posted') {
				continue;
			}

			$id = $this->toStringValue(value: $transaction['@self']['id'] ?? $transaction['id'] ?? null);
			if ($id !== '') {
				$byRef[$id] = $transaction;
			}

			$number = $this->toStringValue(value: $transaction['transactionNumber'] ?? null);
			if ($number !== '') {
				$byRef[$number] = $transaction;
			}
		}

		$buckets = [];
		foreach ($lines as $line) {
			$ref = $this->toStringValue(value: $line['transactionId'] ?? null);
			if (isset($byRef[$ref]) === false) {
				continue;
			}

			$key = $this->monthKey(dateStr: ($byRef[$ref]['postingDate'] ?? null));
			if ($key === null) {
				continue;
			}

			$buckets[$key][] = $line;
		}

		return $buckets;
	}//end postedLinesByMonth()

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
		$months = ($input['months'] ?? []);
		$classes = $this->classifyAccounts(accounts: ($input['accounts'] ?? []));
		$buckets = $this->postedLinesByMonth(
			transactions: ($input['transactions'] ?? []),
			lines: ($input['lines'] ?? [])
		);

		$result = [
			'months' => $months,
			'revenue' => [],
			'costs' => [],
			'margin' => [],
			'marginPct' => [],
			'cashIn' => [],
			'cashOut' => [],
			'cashNet' => [],
		];
		foreach ($months as $key) {
			$revenue = 0.0;
			$costs = 0.0;
			$cashIn = 0.0;
			$cashOut = 0.0;
			foreach (($buckets[$key] ?? []) as $line) {
				$number = $this->toStringValue(value: $line['accountNumber'] ?? null);
				if (isset($classes['revenue'][$number]) === true) {
					$revenue += $this->signedAmount(line: $line, kind: 'revenue');
				} elseif (isset($classes['expenses'][$number]) === true) {
					$costs += $this->signedAmount(line: $line, kind: 'expenses');
				}

				if (isset($classes['liquid'][$number]) === true) {
					$movement = $this->signedAmount(line: $line, kind: 'liquid');
					if ($movement >= 0) {
						$cashIn += $movement;
					} else {
						$cashOut += -$movement;
					}
				}
			}//end foreach

			$margin = ($revenue - $costs);

			$marginPct = null;
			if ($revenue > 0) {
				$marginPct = $this->round2(value: ($margin / $revenue) * 100);
			}

			$result['revenue'][] = $this->round2(value: $revenue);
			$result['costs'][] = $this->round2(value: $costs);
			$result['margin'][] = $this->round2(value: $margin);
			$result['marginPct'][] = $marginPct;
			$result['cashIn'][] = $this->round2(value: $cashIn);
			$result['cashOut'][] = $this->round2(value: $cashOut);
			$result['cashNet'][] = $this->round2(value: $cashIn - $cashOut);
		}//end foreach

		return $result;
	}//end monthlyFinancialSeries()

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
		$billableByMonth = [];
		$totalByMonth = [];
		foreach ($entries as $entry) {
			$key = $this->monthKey(dateStr: ($entry['date'] ?? null));
			if ($key === null) {
				continue;
			}

			$hours = $this->toFloat(value: $entry['hours'] ?? null);

			$totalByMonth[$key] = (($totalByMonth[$key] ?? 0.0) + $hours);
			if ($this->toFloat(value: $entry['recognisedRate'] ?? null) > 0) {
				$billableByMonth[$key] = (($billableByMonth[$key] ?? 0.0) + $hours);
			}
		}

		$billable = [];
		$nonBillable = [];
		$pct = [];
		foreach ($months as $key) {
			$total = ($totalByMonth[$key] ?? 0.0);
			$bill = ($billableByMonth[$key] ?? 0.0);
			$billable[] = $this->round2(value: $bill);
			$nonBillable[] = $this->round2(value: $total - $bill);
			$share = null;
			if ($total > 0) {
				$share = $this->round2(value: ($bill / $total) * 100);
			}

			$pct[] = $share;
		}

		return [
			'billable' => $billable,
			'nonBillable' => $nonBillable,
			'pct' => $pct,
		];

	}//end billableSeries()

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
		$names = [];
		foreach ($customers as $customer) {
			$key = $this->toStringValue(value: $customer['customerId'] ?? $customer['@self']['id'] ?? null);
			$name = $this->toStringValue(value: $customer['legalName'] ?? null);
			if ($name === '') {
				$name = $this->toStringValue(value: $customer['tradeName'] ?? null);
			}

			$names[$key] = $name;
		}

		$rows = [];
		foreach ($invoices as $invoice) {
			$state = $this->toStringValue(value: $invoice['lifecycleState'] ?? null);
			if (in_array($state, self::OPEN_AR_STATES, true) === false) {
				continue;
			}

			$customerId = $this->toStringValue(value: $invoice['customerId'] ?? null);
			$party = ($names[$customerId] ?? '');
			if ($party === '') {
				$party = $customerId;
			}

			$overdue = ($state === 'overdue');
			if ($overdue === false) {
				$overdue = $this->isPastDue(dueDate: ($invoice['dueDate'] ?? null), now: $now);
			}

			$rows[] = [
				'id' => ($invoice['@self']['id'] ?? $invoice['id'] ?? null),
				'invoiceNumber' => $this->toStringValue(value: $invoice['invoiceNumber'] ?? null),
				'party' => $party,
				'invoiceDate' => $this->toStringValue(value: $invoice['invoiceDate'] ?? null),
				'dueDate' => $this->toStringValue(value: $invoice['dueDate'] ?? null),
				'amount' => $this->toFloat(value: $invoice['grossAmount'] ?? $invoice['netAmount'] ?? null),
				'state' => $state,
				'overdue' => $overdue,
			];
		}//end foreach

		usort(
			$rows,
			static function (array $left, array $right): int {
				return strcmp((string)$left['dueDate'], (string)$right['dueDate']);
			}
		);

		return $rows;
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
		$names = [];
		foreach ($vendors as $vendor) {
			$key = $this->toStringValue(value: $vendor['vendorNumber'] ?? $vendor['@self']['id'] ?? null);
			$name = $this->toStringValue(value: $vendor['name'] ?? null);
			if ($name === '') {
				$name = $this->toStringValue(value: $vendor['tradingName'] ?? null);
			}

			$names[$key] = $name;
		}

		$rows = [];
		foreach ($transactions as $transaction) {
			$state = $this->toStringValue(value: $transaction['state'] ?? null);
			if (in_array($state, self::OPEN_AP_STATES, true) === false) {
				continue;
			}

			$vendorId = $this->toStringValue(value: $transaction['vendorId'] ?? null);
			$party = ($names[$vendorId] ?? '');
			if ($party === '') {
				$party = $vendorId;
			}

			$overdue = ($state === 'overdue');
			if ($overdue === false) {
				$overdue = $this->isPastDue(dueDate: ($transaction['dueDate'] ?? null), now: $now);
			}

			$rows[] = [
				'id' => ($transaction['@self']['id'] ?? $transaction['id'] ?? null),
				'invoiceNumber' => $this->toStringValue(value: $transaction['invoiceNumber'] ?? null),
				'party' => $party,
				'invoiceDate' => $this->toStringValue(value: $transaction['invoiceDate'] ?? null),
				'dueDate' => $this->toStringValue(value: $transaction['dueDate'] ?? null),
				'amount' => $this->toFloat(value: $transaction['totalAmount'] ?? null),
				'state' => $state,
				'overdue' => $overdue,
			];
		}//end foreach

		usort(
			$rows,
			static function (array $left, array $right): int {
				return strcmp((string)$left['dueDate'], (string)$right['dueDate']);
			}
		);

		return $rows;
	}//end openApRows()

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
		$year = (int)$now->format('Y');
		$ytdMonths = [];
		$current = (int)$now->format('n');
		for ($month = 1; $month <= $current; $month++) {
			$ytdMonths[] = sprintf('%04d-%02d', $year, $month);
		}

		$series = $this->monthlyFinancialSeries(
			input: [
				'accounts' => ($data['accounts'] ?? []),
				'transactions' => ($data['transactions'] ?? []),
				'lines' => ($data['lines'] ?? []),
				'months' => $ytdMonths,
			]
		);
		$turnoverYtd = $this->round2(value: $this->sumValues(values: $series['revenue']));
		$marginYtd = $this->round2(value: $this->sumValues(values: $series['margin']));

		// Cash position is all-time, not YTD: net of every posted liquid movement.
		$classes = $this->classifyAccounts(accounts: ($data['accounts'] ?? []));
		$buckets = $this->postedLinesByMonth(
			transactions: ($data['transactions'] ?? []),
			lines: ($data['lines'] ?? [])
		);

		$cashPosition = 0.0;
		foreach ($buckets as $monthLines) {
			foreach ($monthLines as $line) {
				$number = $this->toStringValue(value: $line['accountNumber'] ?? null);
				if (isset($classes['liquid'][$number]) === true) {
					$cashPosition += $this->signedAmount(line: $line, kind: 'liquid');
				}
			}
		}

		$openAr = $this->openArRows(invoices: ($data['arInvoices'] ?? []), customers: [], now: $now);
		$openAp = $this->openApRows(transactions: ($data['apTransactions'] ?? []), vendors: [], now: $now);

		$currentMonth = sprintf('%04d-%02d', $year, $current);
		$billable = $this->billableSeries(entries: ($data['hourEntries'] ?? []), months: [$currentMonth]);

		$marginPctYtd = null;
		if ($turnoverYtd > 0) {
			$marginPctYtd = $this->round2(value: ($marginYtd / $turnoverYtd) * 100);
		}

		return [
			'turnoverYtd' => $turnoverYtd,
			'marginYtd' => $marginYtd,
			'marginPctYtd' => $marginPctYtd,
			'openArAmount' => $this->round2(value: $this->sumValues(values: array_column($openAr, 'amount'))),
			'openArCount' => count($openAr),
			'openApAmount' => $this->round2(value: $this->sumValues(values: array_column($openAp, 'amount'))),
			'openApCount' => count($openAp),
			'billableHours' => $billable['billable'][0],
			'billablePct' => $billable['pct'][0],
			'cashPosition' => $this->round2(value: $cashPosition),
		];

	}//end computeKpis()

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
		$series = $this->monthlyFinancialSeries(
			input: [
				'accounts' => ($data['accounts'] ?? []),
				'transactions' => ($data['transactions'] ?? []),
				'lines' => ($data['lines'] ?? []),
				'months' => $months,
			]
		);
		$turnover = $this->round2(value: $this->sumValues(values: $series['revenue']));
		$margin = $this->round2(value: $this->sumValues(values: $series['margin']));

		$bill = $this->billableSeries(entries: ($data['hourEntries'] ?? []), months: $months);
		$billableHours = $this->sumValues(values: $bill['billable']);
		$totalHours = ($billableHours + $this->sumValues(values: $bill['nonBillable']));

		$marginPct = null;
		if ($turnover > 0) {
			$marginPct = $this->round2(value: ($margin / $turnover) * 100);
		}

		$billablePct = null;
		if ($totalHours > 0) {
			$billablePct = $this->round2(value: ($billableHours / $totalHours) * 100);
		}

		return [
			'turnover' => $turnover,
			'margin' => $margin,
			'marginPct' => $marginPct,
			'billableHours' => $this->round2(value: $billableHours),
			'billablePct' => $billablePct,
		];

	}//end computeRangeKpis()

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
