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
		const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
		months.push(
			`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
		)
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
	const start = new Date(`${from.slice(0, 10)}T00:00:00`)
	const end = new Date(`${to.slice(0, 10)}T00:00:00`)
	if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return []
	const months = []
	let d = new Date(start.getFullYear(), start.getMonth(), 1)
	while (d <= end && months.length < 60) {
		months.push(
			`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`,
		)
		d = new Date(d.getFullYear(), d.getMonth() + 1, 1)
	}
	return months
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
	const revenue = new Set()
	const expenses = new Set()
	const liquid = new Set()
	for (const account of accounts || []) {
		const number = String(account.accountNumber ?? '')
		if (!number) continue
		if (account.accountType === 'revenue') revenue.add(number)
		else if (account.accountType === 'expenses') expenses.add(number)
		else if (
			account.accountType === 'assets'
			&& (/^10/.test(number)
				|| /\b(bank|kas|cash)\b/i.test(String(account.name ?? '')))
		) {
			liquid.add(number)
		}
	}
	return { revenue, expenses, liquid }
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
	const byRef = new Map()
	for (const tx of transactions || []) {
		if (tx.state !== 'posted') continue
		const id = tx['@self']?.id ?? tx.id
		if (id) byRef.set(String(id), tx)
		if (tx.transactionNumber) byRef.set(String(tx.transactionNumber), tx)
	}
	const buckets = new Map()
	for (const line of lines || []) {
		const tx = byRef.get(String(line.transactionId ?? ''))
		if (!tx) continue
		const key = monthKey(tx.postingDate)
		if (!key) continue
		if (!buckets.has(key)) buckets.set(key, [])
		buckets.get(key).push(line)
	}
	return buckets
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
 * @param input.accounts
 * @param input.transactions
 * @param input.lines
 * @param input.months
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
		let revenue = 0
		let costs = 0
		let cashIn = 0
		let cashOut = 0
		for (const line of buckets.get(key) || []) {
			const number = String(line.accountNumber ?? '')
			if (classes.revenue.has(number)) revenue += signedAmount(line, 'revenue')
			else if (classes.expenses.has(number))
				costs += signedAmount(line, 'expenses')
			if (classes.liquid.has(number)) {
				const movement = signedAmount(line, 'liquid')
				if (movement >= 0) cashIn += movement
				else cashOut += -movement
			}
		}
		const margin = revenue - costs
		result.revenue.push(round2(revenue))
		result.costs.push(round2(costs))
		result.margin.push(round2(margin))
		result.marginPct.push(revenue > 0 ? round2((margin / revenue) * 100) : null)
		result.cashIn.push(round2(cashIn))
		result.cashOut.push(round2(cashOut))
		result.cashNet.push(round2(cashIn - cashOut))
	}
	return result
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
	const billableByMonth = new Map()
	const totalByMonth = new Map()
	for (const entry of entries || []) {
		const key = monthKey(entry.date)
		if (!key) continue
		const hours = Number(entry.hours) || 0
		totalByMonth.set(key, (totalByMonth.get(key) || 0) + hours)
		if (Number(entry.recognisedRate) > 0) {
			billableByMonth.set(key, (billableByMonth.get(key) || 0) + hours)
		}
	}
	const billable = []
	const nonBillable = []
	const pct = []
	for (const key of months) {
		const total = totalByMonth.get(key) || 0
		const bill = billableByMonth.get(key) || 0
		billable.push(round2(bill))
		nonBillable.push(round2(total - bill))
		pct.push(total > 0 ? round2((bill / total) * 100) : null)
	}
	return { billable, nonBillable, pct }
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
		const bucket = byMonth.get(key) || { cashIn: 0, cashOut: 0 }
		bucket.cashIn += Number(week.inflows_total) || 0
		bucket.cashOut += Number(week.outflows_total) || 0
		byMonth.set(key, bucket)
	}
	const months = [...byMonth.keys()].sort()
	return {
		months,
		cashIn: months.map((key) => round2(byMonth.get(key).cashIn)),
		cashOut: months.map((key) => round2(byMonth.get(key).cashOut)),
		cashNet: months.map((key) =>
			round2(byMonth.get(key).cashIn - byMonth.get(key).cashOut),
		),
	}
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
			id: inv['@self']?.id ?? inv.id,
			invoiceNumber: inv.invoiceNumber ?? '',
			party: names.get(String(inv.customerId ?? '')) || inv.customerId || '',
			invoiceDate: inv.invoiceDate ?? '',
			dueDate: inv.dueDate ?? '',
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
			id: tx['@self']?.id ?? tx.id,
			invoiceNumber: tx.invoiceNumber ?? '',
			party: names.get(String(tx.vendorId ?? '')) || tx.vendorId || '',
			invoiceDate: tx.invoiceDate ?? '',
			dueDate: tx.dueDate ?? '',
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
 * @param input.accounts
 * @param input.transactions
 * @param input.lines
 * @param input.arInvoices
 * @param input.apTransactions
 * @param input.hourEntries
 * @param {Date} now Reference date (YTD window + current month).
 * @return {object}
 */
export function computeKpis(
	{ accounts, transactions, lines, arInvoices, apTransactions, hourEntries },
	now = new Date(),
) {
	const year = now.getFullYear()
	const ytdMonths = []
	for (let m = 0; m <= now.getMonth(); m++) {
		ytdMonths.push(`${year}-${String(m + 1).padStart(2, '0')}`)
	}
	const series = monthlyFinancialSeries({
		accounts,
		transactions,
		lines,
		months: ytdMonths,
	})
	const turnoverYtd = round2(sum(series.revenue))
	const marginYtd = round2(sum(series.margin))

	// Cash position is all-time, not YTD: net of every posted liquid movement.
	const classes = classifyAccounts(accounts)
	const buckets = postedLinesByMonth(transactions, lines)
	let cashPosition = 0
	for (const monthLines of buckets.values()) {
		for (const line of monthLines) {
			if (classes.liquid.has(String(line.accountNumber ?? ''))) {
				cashPosition += signedAmount(line, 'liquid')
			}
		}
	}

	const openAr = openArRows(arInvoices, [], now)
	const openAp = openApRows(apTransactions, [], now)

	const currentMonth = `${year}-${String(now.getMonth() + 1).padStart(2, '0')}`
	const billable = billableSeries(hourEntries, [currentMonth])

	return {
		turnoverYtd,
		marginYtd,
		marginPctYtd:
			turnoverYtd > 0 ? round2((marginYtd / turnoverYtd) * 100) : null,
		openArAmount: round2(sum(openAr.map((r) => r.amount))),
		openArCount: openAr.length,
		openApAmount: round2(sum(openAp.map((r) => r.amount))),
		openApCount: openAp.length,
		billableHours: billable.billable[0],
		billablePct: billable.pct[0],
		cashPosition: round2(cashPosition),
	}
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
 * @param {string} dueDate @param {Date} now @return {boolean}
 * @param now
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
 * Range-driven KPI metrics: turnover, margin (€ + %) and billable
 * (hours + %) aggregated over an explicit list of month buckets
 * (the dashboard's selected date range) rather than the fixed
 * year-to-date / current-month windows `computeKpis` uses. The
 * point-in-time metrics (open debtors/creditors, cash balance) do
 * not vary by range and stay in `computeKpis`.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {object} input `{ accounts, transactions, lines, hourEntries }`.
 * @param input.accounts
 * @param input.transactions
 * @param input.lines
 * @param input.hourEntries
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
		marginPct: turnover > 0 ? round2((margin / turnover) * 100) : null,
		billableHours: round2(billableHours),
		billablePct:
			totalHours > 0 ? round2((billableHours / totalHours) * 100) : null,
	}
}
