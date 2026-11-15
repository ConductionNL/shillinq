// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure computation layer for the Financial overview dashboard.
// Everything in here is side-effect free and unit-tested; fetching
// lives in useFinancialData.js.

/** AR lifecycle states that count as an open receivable. */
export const OPEN_AR_STATES = ['issued', 'overdue']

/** AP states that count as an open payable. */
export const OPEN_AP_STATES = ['received', 'issued', 'partially-paid', 'overdue']

/**
 * Month bucket key (`YYYY-MM`) for a date-ish string, or null when
 * the value does not start with a parsable year-month.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {string|null|undefined} dateStr Date string (ISO or `YYYY-MM-DD hh:mm:ss`).
 * @return {string|null}
 */
export function monthKey(dateStr) {
	if (typeof dateStr !== 'string') return null
	const m = dateStr.match(/^(\d{4})-(\d{2})/)
	return m ? `${m[1]}-${m[2]}` : null
}

/**
 * Trailing `count` month keys ending at `now`, ascending.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {number} count Number of months.
 * @param {Date} now Reference date.
 * @return {string[]}
 */
export function lastMonths(count, now = new Date()) {
	const months = []
	for (let i = count - 1; i >= 0; i--) {
		months.push(monthKeyOf(new Date(now.getFullYear(), now.getMonth() - i, 1)))
	}
	return months
}

/**
 * All month keys (`YYYY-MM`) from the month containing `from` through
 * the month containing `to`, ascending. Capped at 60 months.
 *
 * @param {string} from ISO-8601 lower bound.
 * @param {string} to ISO-8601 upper bound.
 * @return {string[]}
 */
export function monthsInRange(from, to) {
	if (!from || !to) return []
	return monthSpan(rangeDay(from), rangeDay(to))
}

/**
 * Local midnight of the day an ISO-8601 bound starts with.
 *
 * @param {string} value ISO-8601 bound.
 * @return {Date}
 */
function rangeDay(value) {
	return new Date(`${value.slice(0, 10)}T00:00:00`)
}

/**
 * Month keys from the month of `start` through the month of `end`, at most 60.
 *
 * @param {Date} start Lower bound.
 * @param {Date} end Upper bound.
 * @return {string[]}
 */
function monthSpan(start, end) {
	if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return []
	const months = []
	let d = new Date(start.getFullYear(), start.getMonth(), 1)
	while (d <= end && months.length < 60) {
		months.push(monthKeyOf(d))
		d = new Date(d.getFullYear(), d.getMonth() + 1, 1)
	}
	return months
}

/**
 * Month key (`YYYY-MM`) of a date, by its local fields.
 *
 * @param {Date} date The date.
 * @return {string}
 */
function monthKeyOf(date) {
	return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

/**
 * Human label for a `YYYY-MM` key (e.g. `Jan ’26`), localised via
 * Intl in the browser's language.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {string} key Month key.
 * @return {string}
 */
export function monthLabel(key) {
	const [y, m] = key.split('-').map(Number)
	return new Date(y, m - 1, 1).toLocaleDateString(undefined, {
		month: 'short',
		year: '2-digit',
	})
}

/**
 * Classify the chart of accounts into the sets the dashboard needs.
 * Liquid assets are `assets` accounts that look like bank/cash:
 * RGS-style account numbers starting with `10`, or a bank/kas/cash
 * name.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object[]} accounts Account objects from the register.
 * @return {{ revenue: Set<string>, expenses: Set<string>, liquid: Set<string> }}
 */
export function classifyAccounts(accounts) {
	const classes = { revenue: new Set(), expenses: new Set(), liquid: new Set() }
	for (const account of accounts || []) {
		const number = String(account.accountNumber ?? '')
		const kind = accountClass(account, number)
		if (kind) classes[kind].add(number)
	}
	return classes
}

/**
 * The dashboard class of one account: revenue, expenses, liquid or none.
 *
 * @param {object} account Account object.
 * @param {string} number Its account number as a string.
 * @return {string|null}
 */
function accountClass(account, number) {
	if (!number) return null
	if (account.accountType === 'revenue' || account.accountType === 'expenses') {
		return account.accountType
	}
	return isLiquidAccount(account, number) ? 'liquid' : null
}

/**
 * Whether an account is a bank or cash account.
 *
 * @param {object} account Account object.
 * @param {string} number Its account number as a string.
 * @return {boolean}
 */
function isLiquidAccount(account, number) {
	return (
		account.accountType === 'assets'
		&& (/^10/.test(number)
			|| /\b(bank|kas|cash)\b/i.test(String(account.name ?? '')))
	)
}

/**
 * Index posted GL lines by month. Lines are matched to their parent
 * transaction via `transactionId` against both the OR object id and
 * the human `transactionNumber`; only `state: "posted"` parents
 * contribute, and the parent's `postingDate` decides the bucket.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object[]} transactions GLTransaction objects.
 * @param {object[]} lines GLLine objects.
 * @return {Map<string, object[]>} month key → lines posted in that month.
 */
export function postedLinesByMonth(transactions, lines) {
	const byRef = postedByRef(transactions)
	const buckets = new Map()
	for (const line of lines || []) {
		const key = postingMonth(byRef, line)
		if (!key) continue
		if (!buckets.has(key)) buckets.set(key, [])
		buckets.get(key).push(line)
	}
	return buckets
}

/**
 * Posted transactions by every reference a line may use: the OR object id
 * and the transaction number.
 *
 * @param {object[]} transactions GLTransaction objects.
 * @return {Map<string, object>}
 */
function postedByRef(transactions) {
	const byRef = new Map()
	for (const tx of transactions || []) {
		if (tx.state !== 'posted') continue
		for (const ref of transactionRefs(tx)) byRef.set(String(ref), tx)
	}
	return byRef
}

/**
 * The references of a transaction that are set, id first.
 *
 * @param {object} tx GLTransaction object.
 * @return {Array<string|number>}
 */
function transactionRefs(tx) {
	return [tx['@self']?.id ?? tx.id, tx.transactionNumber].filter(Boolean)
}

/**
 * Month key of the posting date of a line's posted transaction.
 *
 * @param {Map<string, object>} byRef Posted transactions by reference.
 * @param {object} line GLLine object.
 * @return {string|null}
 */
function postingMonth(byRef, line) {
	const tx = byRef.get(String(line.transactionId ?? ''))
	return tx ? monthKey(tx.postingDate) : null
}

/**
 * Signed contribution of a GL line for an account class. Revenue
 * grows on credit; expenses grow on debit; liquid assets grow on
 * debit (money in).
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object} line GLLine object.
 * @param {'revenue'|'expenses'|'liquid'} kind Account class.
 * @return {number}
 */
export function signedAmount(line, kind) {
	const amount = Number(line.amount) || 0
	const sign = line.side === 'credit' ? 1 : -1
	return kind === 'revenue' ? sign * amount : -sign * amount
}

/**
 * Monthly turnover / cost / margin / cashflow series over `months`,
 * from posted GL lines classified by the chart of accounts.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object} input `{ accounts, transactions, lines, months }`.
 * @param {object[]} input.accounts Account objects.
 * @param {object[]} input.transactions GLTransaction objects.
 * @param {object[]} input.lines GLLine objects.
 * @param {string[]} input.months Month keys.
 * @return {{ months: string[], revenue: number[], costs: number[], margin: number[],
 *   marginPct: (number|null)[], cashIn: number[], cashOut: number[], cashNet: number[] }}
 */
export function monthlyFinancialSeries({ accounts, transactions, lines, months }) {
	const classes = classifyAccounts(accounts)
	const buckets = postedLinesByMonth(transactions, lines)
	const result = {
		months,
		revenue: [],
		costs: [],
		margin: [],
		marginPct: [],
		cashIn: [],
		cashOut: [],
		cashNet: [],
	}
	for (const key of months) {
		pushMonth(result, monthTotals(buckets.get(key) || [], classes))
	}
	return result
}

/** The series total each profit-and-loss class adds to. */
const PROFIT_TOTAL = { revenue: 'revenue', expenses: 'costs' }

/**
 * Revenue, costs, cash in and cash out of one month's posted lines.
 *
 * @param {object[]} monthLines GLLine objects posted in the month.
 * @param {object} classes Account classes from classifyAccounts().
 * @return {{ revenue: number, costs: number, cashIn: number, cashOut: number }}
 */
function monthTotals(monthLines, classes) {
	const totals = { revenue: 0, costs: 0, cashIn: 0, cashOut: 0 }
	for (const line of monthLines) {
		const number = String(line.accountNumber ?? '')
		const kind = profitClass(classes, number)
		if (kind) totals[PROFIT_TOTAL[kind]] += signedAmount(line, kind)
		if (classes.liquid.has(number)) addCash(totals, signedAmount(line, 'liquid'))
	}
	return totals
}

/**
 * The profit-and-loss class of an account number, revenue before expenses.
 *
 * @param {object} classes Account classes from classifyAccounts().
 * @param {string} number Account number.
 * @return {string|null}
 */
function profitClass(classes, number) {
	if (classes.revenue.has(number)) return 'revenue'
	return classes.expenses.has(number) ? 'expenses' : null
}

/**
 * Add a liquid movement to cash in when positive or zero, else to cash out.
 *
 * @param {object} totals Month totals.
 * @param {number} movement Signed liquid movement.
 * @return {void}
 */
function addCash(totals, movement) {
	if (movement >= 0) totals.cashIn += movement
	else totals.cashOut += -movement
}

/**
 * Append one month's totals to the series.
 *
 * @param {object} result The series.
 * @param {object} totals Month totals.
 * @return {void}
 */
function pushMonth(result, totals) {
	const margin = totals.revenue - totals.costs
	result.revenue.push(round2(totals.revenue))
	result.costs.push(round2(totals.costs))
	result.margin.push(round2(margin))
	result.marginPct.push(percentOf(margin, totals.revenue))
	result.cashIn.push(round2(totals.cashIn))
	result.cashOut.push(round2(totals.cashOut))
	result.cashNet.push(round2(totals.cashIn - totals.cashOut))
}

/**
 * Monthly billable vs non-billable hours from UrenRegistratie.
 * Billable = `recognisedRate` greater than zero.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object[]} entries UrenRegistratie objects.
 * @param {string[]} months Month keys to bucket into.
 * @return {{ billable: number[], nonBillable: number[], pct: (number|null)[] }}
 */
export function billableSeries(entries, months) {
	const byMonth = hoursByMonth(entries)
	const series = { billable: [], nonBillable: [], pct: [] }
	for (const key of months) {
		const { total, billable } = byMonth.get(key) || { total: 0, billable: 0 }
		series.billable.push(round2(billable))
		series.nonBillable.push(round2(total - billable))
		series.pct.push(percentOf(billable, total))
	}
	return series
}

/**
 * Total and billable hours per month key.
 *
 * @param {object[]} entries UrenRegistratie objects.
 * @return {Map<string, { total: number, billable: number }>}
 */
function hoursByMonth(entries) {
	const byMonth = new Map()
	for (const entry of entries || []) {
		const key = monthKey(entry.date)
		if (!key) continue
		const bucket = monthBucket(byMonth, key)
		const hours = entryHours(entry)
		bucket.total += hours
		if (Number(entry.recognisedRate) > 0) bucket.billable += hours
	}
	return byMonth
}

/**
 * The hours of an entry, 0 when not a number.
 *
 * @param {object} entry UrenRegistratie object.
 * @return {number}
 */
function entryHours(entry) {
	return Number(entry.hours) || 0
}

/**
 * The bucket of a month key, created empty on first use.
 *
 * @param {Map<string, object>} byMonth Buckets by month key.
 * @param {string} key Month key.
 * @return {{ total: number, billable: number, cashIn: number, cashOut: number }}
 */
function monthBucket(byMonth, key) {
	if (!byMonth.has(key))
		byMonth.set(key, { total: 0, billable: 0, cashIn: 0, cashOut: 0 })
	return byMonth.get(key)
}

/**
 * Roll the 13-week CashflowWeek forecast up to whole months after
 * `afterMonth`, so the cashflow chart can append dimmed projection
 * columns. Months overlapping realized data are dropped.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object[]} weeks CashflowWeek objects.
 * @param {string} afterMonth Last realized month key (`YYYY-MM`).
 * @return {{ months: string[], cashIn: number[], cashOut: number[], cashNet: number[] }}
 */
export function forecastByMonth(weeks, afterMonth) {
	const byMonth = new Map()
	for (const week of weeks || []) {
		const key = monthKey(week.weekStart)
		if (!key || key <= afterMonth) continue
		addWeek(monthBucket(byMonth, key), week)
	}
	const months = [...byMonth.keys()].sort()
	const buckets = months.map((key) => byMonth.get(key))
	return {
		months,
		cashIn: buckets.map((bucket) => round2(bucket.cashIn)),
		cashOut: buckets.map((bucket) => round2(bucket.cashOut)),
		cashNet: buckets.map((bucket) => round2(bucket.cashIn - bucket.cashOut)),
	}
}

/**
 * Add a forecast week's inflows and outflows to its month bucket.
 *
 * @param {object} bucket Month bucket.
 * @param {object} week CashflowWeek object.
 * @return {void}
 */
function addWeek(bucket, week) {
	bucket.cashIn += Number(week.inflows_total) || 0
	bucket.cashOut += Number(week.outflows_total) || 0
}

/**
 * Open-receivables table rows from ARInvoice objects, sorted by due
 * date ascending. `overdue` is set when the state says so or the
 * due date has passed.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object[]} invoices ARInvoice objects.
 * @param {object[]} customers CustomerMaster objects (name lookup).
 * @param {Date} now Reference date for the overdue flag.
 * @return {object[]}
 */
export function openArRows(invoices, customers, now = new Date()) {
	const names = new Map(
		(customers || []).map((c) => [
			String(c.customerId ?? c['@self']?.id ?? ''),
			c.legalName || c.tradeName || '',
		]),
	)
	return (invoices || [])
		.filter((inv) => OPEN_AR_STATES.includes(inv.lifecycleState))
		.map((inv) => ({
			...documentHead(inv, partyName(names, inv.customerId)),
			amount: Number(inv.grossAmount ?? inv.netAmount) || 0,
			state: inv.lifecycleState,
			overdue: inv.lifecycleState === 'overdue' || isPastDue(inv.dueDate, now),
		}))
		.sort((a, b) => String(a.dueDate).localeCompare(String(b.dueDate)))
}

/**
 * Open-payables table rows from APTransaction objects, sorted by
 * due date ascending.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object[]} transactions APTransaction objects.
 * @param {object[]} vendors Payee objects (name lookup).
 * @param {Date} now Reference date for the overdue flag.
 * @return {object[]}
 */
export function openApRows(transactions, vendors, now = new Date()) {
	const names = new Map(
		(vendors || []).map((v) => [
			String(v.vendorNumber ?? v['@self']?.id ?? ''),
			v.name || v.tradingName || '',
		]),
	)
	return (transactions || [])
		.filter((tx) => OPEN_AP_STATES.includes(tx.state))
		.map((tx) => ({
			...documentHead(tx, partyName(names, tx.vendorId)),
			amount: Number(tx.totalAmount) || 0,
			state: tx.state,
			overdue: tx.state === 'overdue' || isPastDue(tx.dueDate, now),
		}))
		.sort((a, b) => String(a.dueDate).localeCompare(String(b.dueDate)))
}

/**
 * The six KPI-strip metrics.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object} input `{ accounts, transactions, lines, arInvoices, apTransactions, hourEntries }`.
 * @param {object[]} input.accounts Account objects.
 * @param {object[]} input.transactions GLTransaction objects.
 * @param {object[]} input.lines GLLine objects.
 * @param {object[]} input.arInvoices ARInvoice objects.
 * @param {object[]} input.apTransactions APTransaction objects.
 * @param {object[]} input.hourEntries UrenRegistratie objects.
 * @param {Date} now Reference date (YTD window + current month).
 * @return {object}
 */
export function computeKpis(
	{ accounts, transactions, lines, arInvoices, apTransactions, hourEntries },
	now = new Date(),
) {
	return {
		...ledgerKpis({ accounts, transactions, lines }, now),
		...openItemKpis({ arInvoices, apTransactions }, now),
		...billableKpis(hourEntries, now),
		cashPosition: round2(cashPosition({ accounts, transactions, lines })),
	}
}

/**
 * The year-to-date turnover and margin KPIs.
 *
 * @param {object} ledger `{ accounts, transactions, lines }`.
 * @param {object[]} ledger.accounts Account objects.
 * @param {object[]} ledger.transactions GLTransaction objects.
 * @param {object[]} ledger.lines GLLine objects.
 * @param {Date} now Reference date.
 * @return {{turnoverYtd: number, marginYtd: number, marginPctYtd: number}}
 */
function ledgerKpis({ accounts, transactions, lines }, now) {
	const series = monthlyFinancialSeries({
		accounts,
		transactions,
		lines,
		months: yearToDateMonths(now),
	})
	const turnoverYtd = round2(sum(series.revenue))
	const marginYtd = round2(sum(series.margin))
	return { turnoverYtd, marginYtd, marginPctYtd: percentOf(marginYtd, turnoverYtd) }
}

/**
 * The open receivable and payable KPIs.
 *
 * @param {object} items `{ arInvoices, apTransactions }`.
 * @param {object[]} items.arInvoices ARInvoice objects.
 * @param {object[]} items.apTransactions APTransaction objects.
 * @param {Date} now Reference date.
 * @return {{openArAmount: number, openArCount: number, openApAmount: number, openApCount: number}}
 */
function openItemKpis({ arInvoices, apTransactions }, now) {
	const openAr = openArRows(arInvoices, [], now)
	const openAp = openApRows(apTransactions, [], now)
	return {
		openArAmount: totalAmount(openAr),
		openArCount: openAr.length,
		openApAmount: totalAmount(openAp),
		openApCount: openAp.length,
	}
}

/**
 * The current month's billable hours KPIs.
 *
 * @param {object[]} hourEntries UrenRegistratie objects.
 * @param {Date} now Reference date.
 * @return {{billableHours: number, billablePct: number}}
 */
function billableKpis(hourEntries, now) {
	const billable = billableSeries(hourEntries, [monthKeyOf(now)])
	return { billableHours: billable.billable[0], billablePct: billable.pct[0] }
}

/**
 * Month keys from January through the month of `now`.
 *
 * @param {Date} now Reference date.
 * @return {string[]}
 */
function yearToDateMonths(now) {
	const months = []
	for (let m = 0; m <= now.getMonth(); m++) {
		months.push(monthKeyOf(new Date(now.getFullYear(), m, 1)))
	}
	return months
}

/**
 * Cash position, all-time rather than YTD: the net of every posted liquid
 * movement.
 *
 * @param {object} input `{ accounts, transactions, lines }`.
 * @param {object[]} input.accounts Account objects.
 * @param {object[]} input.transactions GLTransaction objects.
 * @param {object[]} input.lines GLLine objects.
 * @return {number}
 */
function cashPosition({ accounts, transactions, lines }) {
	const liquid = classifyAccounts(accounts).liquid
	let position = 0
	for (const monthLines of postedLinesByMonth(transactions, lines).values()) {
		for (const line of monthLines) {
			if (liquid.has(String(line.accountNumber ?? ''))) {
				position += signedAmount(line, 'liquid')
			}
		}
	}
	return position
}

/**
 * Rounded sum of the amounts of table rows.
 *
 * @param {object[]} rows Rows with an `amount`.
 * @return {number}
 */
function totalAmount(rows) {
	return round2(sum(rows.map((r) => r.amount)))
}

/**
 * EUR formatter for KPI tiles, charts and tables.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {number|null|undefined} value Amount in euros.
 * @param {number} maximumFractionDigits Decimals to keep (default 0).
 * @return {string}
 */
export function formatEur(value, maximumFractionDigits = 0) {
	if (value === null || value === undefined || Number.isNaN(Number(value)))
		return '—'
	return new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency: 'EUR',
		maximumFractionDigits,
	}).format(Number(value))
}

/**
 * The id, number, party and dates of an open-items table row.
 *
 * @param {object} doc ARInvoice or APTransaction object.
 * @param {string} party The counterparty's display name.
 * @return {object} id, invoiceNumber, party, invoiceDate and dueDate.
 */
function documentHead(doc, party) {
	return {
		id: doc['@self']?.id ?? doc.id,
		invoiceNumber: doc.invoiceNumber ?? '',
		party,
		invoiceDate: doc.invoiceDate ?? '',
		dueDate: doc.dueDate ?? '',
	}
}

/**
 * The display name of a counterparty, else its id, else an empty string.
 *
 * @param {Map<string, string>} names Names by id.
 * @param {string|undefined} id The counterparty id.
 * @return {string}
 */
function partyName(names, id) {
	return names.get(String(id ?? '')) || id || ''
}

/**
 * Whether a due date lies before the UTC day of `now`.
 *
 * @param {string} dueDate The due date.
 * @param {Date} now Reference date.
 * @return {boolean}
 */
function isPastDue(dueDate, now) {
	const key = typeof dueDate === 'string' ? dueDate.slice(0, 10) : ''
	return !!key && key < now.toISOString().slice(0, 10)
}

/** @param {number[]} values @return {number} */
function sum(values) {
	return values.reduce((acc, v) => acc + (Number(v) || 0), 0)
}

/** @param {number} value @return {number} */
function round2(value) {
	return Math.round(value * 100) / 100
}

/**
 * A part as a rounded percentage of a whole, null when the whole is not positive.
 *
 * @param {number} part The part.
 * @param {number} whole The whole.
 * @return {number|null}
 */
function percentOf(part, whole) {
	return whole > 0 ? round2((part / whole) * 100) : null
}

/**
 * Range-driven KPI metrics: turnover, margin (€ + %) and billable
 * (hours + %) aggregated over an explicit list of month buckets
 * (the dashboard's selected date range) rather than the fixed
 * year-to-date / current-month windows `computeKpis` uses. The
 * point-in-time metrics (open debtors/creditors, cash balance) do
 * not vary by range and stay in `computeKpis`.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object} input `{ accounts, transactions, lines, hourEntries }`.
 * @param {object[]} input.accounts Account objects.
 * @param {object[]} input.transactions GLTransaction objects.
 * @param {object[]} input.lines GLLine objects.
 * @param {object[]} input.hourEntries UrenRegistratie objects.
 * @param {string[]} months Month keys (`YYYY-MM`) to aggregate over.
 * @return {{ turnover: number, margin: number, marginPct: (number|null),
 *   billableHours: number, billablePct: (number|null) }}
 */
export function computeRangeKpis(
	{ accounts, transactions, lines, hourEntries },
	months,
) {
	const series = monthlyFinancialSeries({ accounts, transactions, lines, months })
	const turnover = round2(sum(series.revenue))
	const margin = round2(sum(series.margin))

	const bill = billableSeries(hourEntries, months)
	const billableHours = sum(bill.billable)
	const totalHours = billableHours + sum(bill.nonBillable)

	return {
		turnover,
		margin,
		marginPct: percentOf(margin, turnover),
		billableHours: round2(billableHours),
		billablePct: percentOf(billableHours, totalHours),
	}
}
