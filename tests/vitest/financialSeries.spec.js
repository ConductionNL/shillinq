/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the Financial overview dashboard's computation layer
 * (src/components/dashboard/financial/financialSeries.js) and the
 * fetch-once data layer (useFinancialData.js): month bucketing, GL
 * classification, margin %, cashflow forecast roll-up, open AR/AP row
 * mapping and the one-request-per-schema guarantee.
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	billableSeries,
	classifyAccounts,
	computeKpis,
	computeRangeKpis,
	forecastByMonth,
	lastMonths,
	monthKey,
	monthlyFinancialSeries,
	openApRows,
	openArRows,
	postedLinesByMonth,
	signedAmount,
} from '../../src/components/dashboard/financial/financialSeries.js'
import {
	resetFinancialData,
	useFinancialData,
} from '../../src/components/dashboard/financial/useFinancialData.js'

const ACCOUNTS = [
	{ accountNumber: '8000', name: 'Omzet consultancy', accountType: 'revenue' },
	{ accountNumber: '4000', name: 'Personeelskosten', accountType: 'expenses' },
	{ accountNumber: '1010', name: 'Zakelijke rekening', accountType: 'assets' },
	{ accountNumber: '1300', name: 'Debiteuren', accountType: 'assets' },
	{ accountNumber: '1600', name: 'Crediteuren', accountType: 'liabilities' },
	{ accountNumber: '2900', name: 'Kas reserve', accountType: 'assets' },
]

const TRANSACTIONS = [
	{
		id: 'tx-1',
		transactionNumber: 'TX-1',
		postingDate: '2026-03-05 00:00:00',
		state: 'posted',
	},
	{
		id: 'tx-2',
		transactionNumber: 'TX-2',
		postingDate: '2026-03-20 00:00:00',
		state: 'posted',
	},
	{
		id: 'tx-3',
		transactionNumber: 'TX-3',
		postingDate: '2026-04-02 00:00:00',
		state: 'posted',
	},
	{
		id: 'tx-draft',
		transactionNumber: 'TX-D',
		postingDate: '2026-03-09 00:00:00',
		state: 'draft',
	},
]

const LINES = [
	// March: revenue 1000 (credit 8000 / debit 1300)
	{ transactionId: 'tx-1', accountNumber: '8000', side: 'credit', amount: 1000 },
	{ transactionId: 'tx-1', accountNumber: '1300', side: 'debit', amount: 1000 },
	// March: costs 400 (debit 4000 / credit 1010 → cash out)
	{ transactionId: 'tx-2', accountNumber: '4000', side: 'debit', amount: 400 },
	{ transactionId: 'tx-2', accountNumber: '1010', side: 'credit', amount: 400 },
	// April: customer pays 1000 into bank (cash in)
	{ transactionId: 'TX-3', accountNumber: '1010', side: 'debit', amount: 1000 },
	{ transactionId: 'TX-3', accountNumber: '1300', side: 'credit', amount: 1000 },
	// Draft transaction must be ignored
	{
		transactionId: 'tx-draft',
		accountNumber: '8000',
		side: 'credit',
		amount: 9999,
	},
	// Orphan line (unknown transaction) must be ignored
	{
		transactionId: 'tx-missing',
		accountNumber: '8000',
		side: 'credit',
		amount: 5555,
	},
]

describe('financialSeries', () => {
	it('monthKey parses ISO and SQL-ish dates and rejects garbage', () => {
		expect(monthKey('2026-03-05')).toBe('2026-03')
		expect(monthKey('2026-03-05 12:30:00')).toBe('2026-03')
		expect(monthKey('2026-03-05T12:30:00Z')).toBe('2026-03')
		expect(monthKey('not a date')).toBeNull()
		expect(monthKey(null)).toBeNull()
	})

	it('lastMonths returns ascending trailing window crossing year boundaries', () => {
		expect(lastMonths(3, new Date(2026, 0, 15))).toEqual([
			'2025-11',
			'2025-12',
			'2026-01',
		])
	})

	it('classifyAccounts buckets revenue, expenses and liquid assets', () => {
		const classes = classifyAccounts(ACCOUNTS)
		expect([...classes.revenue]).toEqual(['8000'])
		expect([...classes.expenses]).toEqual(['4000'])
		// 1010 by number prefix, 2900 by "kas" name; 1300 (debtors) is NOT liquid
		expect([...classes.liquid].sort()).toEqual(['1010', '2900'])
	})

	it('postedLinesByMonth matches lines via object id and transactionNumber, posted only', () => {
		const buckets = postedLinesByMonth(TRANSACTIONS, LINES)
		expect(buckets.get('2026-03')).toHaveLength(4)
		expect(buckets.get('2026-04')).toHaveLength(2)
		const all = [...buckets.values()].flat()
		expect(all.some((line) => line.amount === 9999)).toBe(false)
		expect(all.some((line) => line.amount === 5555)).toBe(false)
	})

	it('signedAmount grows revenue on credit and expenses/liquid on debit', () => {
		expect(signedAmount({ side: 'credit', amount: 100 }, 'revenue')).toBe(100)
		expect(signedAmount({ side: 'debit', amount: 100 }, 'revenue')).toBe(-100)
		expect(signedAmount({ side: 'debit', amount: 100 }, 'expenses')).toBe(100)
		expect(signedAmount({ side: 'debit', amount: 100 }, 'liquid')).toBe(100)
		expect(signedAmount({ side: 'credit', amount: 100 }, 'liquid')).toBe(-100)
	})

	it('monthlyFinancialSeries computes turnover, margin, margin% and cashflow', () => {
		const series = monthlyFinancialSeries({
			accounts: ACCOUNTS,
			transactions: TRANSACTIONS,
			lines: LINES,
			months: ['2026-02', '2026-03', '2026-04'],
		})
		expect(series.revenue).toEqual([0, 1000, 0])
		expect(series.costs).toEqual([0, 400, 0])
		expect(series.margin).toEqual([0, 600, 0])
		expect(series.marginPct).toEqual([null, 60, null])
		expect(series.cashIn).toEqual([0, 0, 1000])
		expect(series.cashOut).toEqual([0, 400, 0])
		expect(series.cashNet).toEqual([0, -400, 1000])
	})

	it('billableSeries splits on recognisedRate and computes the share', () => {
		const entries = [
			{ date: '2026-03-02', hours: 6, recognisedRate: 95 },
			{ date: '2026-03-03', hours: 2, recognisedRate: 0 },
			{ date: '2026-03-04', hours: 2 },
			{ date: '2026-04-01', hours: 8, recognisedRate: 110 },
		]
		const series = billableSeries(entries, ['2026-03', '2026-04', '2026-05'])
		expect(series.billable).toEqual([6, 8, 0])
		expect(series.nonBillable).toEqual([4, 0, 0])
		expect(series.pct).toEqual([60, 100, null])
	})

	it('forecastByMonth rolls weeks up to months strictly after the realized window', () => {
		const weeks = [
			{ weekStart: '2026-06-15', inflows_total: 100, outflows_total: 60 },
			{ weekStart: '2026-07-06', inflows_total: 200, outflows_total: 80 },
			{ weekStart: '2026-07-13', inflows_total: 50, outflows_total: 20 },
		]
		const forecast = forecastByMonth(weeks, '2026-06')
		expect(forecast.months).toEqual(['2026-07'])
		expect(forecast.cashIn).toEqual([250])
		expect(forecast.cashOut).toEqual([100])
		expect(forecast.cashNet).toEqual([150])
	})

	it('openArRows filters open states, resolves names, flags overdue, sorts by due date', () => {
		const now = new Date(2026, 5, 12)
		const invoices = [
			{
				id: 'a',
				invoiceNumber: 'F-3',
				customerId: 'C1',
				dueDate: '2026-07-01',
				grossAmount: 300,
				lifecycleState: 'issued',
			},
			{
				id: 'b',
				invoiceNumber: 'F-1',
				customerId: 'C1',
				dueDate: '2026-05-01',
				grossAmount: 100,
				lifecycleState: 'overdue',
			},
			{
				id: 'c',
				invoiceNumber: 'F-2',
				customerId: 'C2',
				dueDate: '2026-06-01',
				grossAmount: 200,
				lifecycleState: 'issued',
			},
			{
				id: 'd',
				invoiceNumber: 'F-0',
				customerId: 'C1',
				dueDate: '2026-01-01',
				grossAmount: 999,
				lifecycleState: 'paid',
			},
		]
		const customers = [{ customerId: 'C1', legalName: 'Acme BV' }]
		const rows = openArRows(invoices, customers, now)
		expect(rows.map((r) => r.invoiceNumber)).toEqual(['F-1', 'F-2', 'F-3'])
		expect(rows[0].overdue).toBe(true)
		// issued but past due is flagged too
		expect(rows[1].overdue).toBe(true)
		expect(rows[2].overdue).toBe(false)
		expect(rows[0].party).toBe('Acme BV')
		expect(rows[1].party).toBe('C2')
	})

	it('openApRows filters the open AP states including partially-paid', () => {
		const now = new Date(2026, 5, 12)
		const txs = [
			{
				id: 'a',
				invoiceNumber: 'I-1',
				vendorId: 'V1',
				dueDate: '2026-06-20',
				totalAmount: 500,
				state: 'received',
			},
			{
				id: 'b',
				invoiceNumber: 'I-2',
				vendorId: 'V1',
				dueDate: '2026-06-01',
				totalAmount: 250,
				state: 'partially-paid',
			},
			{
				id: 'c',
				invoiceNumber: 'I-3',
				vendorId: 'V2',
				dueDate: '2026-05-01',
				totalAmount: 100,
				state: 'paid',
			},
			{
				id: 'd',
				invoiceNumber: 'I-4',
				vendorId: 'V2',
				dueDate: '2026-04-01',
				totalAmount: 100,
				state: 'voided',
			},
		]
		const rows = openApRows(
			txs,
			[{ vendorNumber: 'V1', name: 'Hosting BV' }],
			now,
		)
		expect(rows.map((r) => r.invoiceNumber)).toEqual(['I-2', 'I-1'])
		expect(rows[0].overdue).toBe(true)
		expect(rows[0].party).toBe('Hosting BV')
	})

	it('computeKpis aggregates YTD turnover/margin, open AR/AP, billable share and cash position', () => {
		const now = new Date(2026, 3, 15) // April 2026
		const kpis = computeKpis(
			{
				accounts: ACCOUNTS,
				transactions: TRANSACTIONS,
				lines: LINES,
				arInvoices: [
					{
						id: 'a',
						dueDate: '2026-05-01',
						grossAmount: 1210,
						lifecycleState: 'issued',
					},
				],
				apTransactions: [
					{
						id: 'b',
						dueDate: '2026-05-01',
						totalAmount: 484,
						state: 'received',
					},
				],
				hourEntries: [
					{ date: '2026-04-02', hours: 30, recognisedRate: 95 },
					{ date: '2026-04-03', hours: 10 },
				],
			},
			now,
		)
		expect(kpis.turnoverYtd).toBe(1000)
		expect(kpis.marginYtd).toBe(600)
		expect(kpis.marginPctYtd).toBe(60)
		expect(kpis.openArAmount).toBe(1210)
		expect(kpis.openArCount).toBe(1)
		expect(kpis.openApAmount).toBe(484)
		expect(kpis.openApCount).toBe(1)
		expect(kpis.billableHours).toBe(30)
		expect(kpis.billablePct).toBe(75)
		expect(kpis.cashPosition).toBe(600)
	})

	it('computeRangeKpis aggregates turnover/margin/billable over the given months and varies by range', () => {
		const hourEntries = [
			{ date: '2026-03-02', hours: 6, recognisedRate: 95 },
			{ date: '2026-03-03', hours: 2, recognisedRate: 0 },
			{ date: '2026-04-01', hours: 8, recognisedRate: 110 },
		]
		const data = {
			accounts: ACCOUNTS,
			transactions: TRANSACTIONS,
			lines: LINES,
			hourEntries,
		}

		// March + April: revenue 1000 (March), margin 600; billable 6+8 of 16 total.
		const wide = computeRangeKpis(data, ['2026-03', '2026-04'])
		expect(wide.turnover).toBe(1000)
		expect(wide.margin).toBe(600)
		expect(wide.marginPct).toBe(60)
		expect(wide.billableHours).toBe(14)
		expect(wide.billablePct).toBe(87.5)

		// April only: no posted revenue/cost → turnover 0, marginPct null;
		// proves the metrics shrink with a narrower range.
		const aprilOnly = computeRangeKpis(data, ['2026-04'])
		expect(aprilOnly.turnover).toBe(0)
		expect(aprilOnly.margin).toBe(0)
		expect(aprilOnly.marginPct).toBe(null)
		expect(aprilOnly.billableHours).toBe(8)
		expect(aprilOnly.billablePct).toBe(100)
	})
})

describe('useFinancialData', () => {
	beforeEach(() => {
		resetFinancialData()
		axios.get = vi.fn().mockResolvedValue({ data: { results: [] } })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		resetFinancialData()
	})

	it('fetches each schema exactly once no matter how many widgets load', async () => {
		const first = useFinancialData()
		const second = useFinancialData()
		await Promise.all([first.load(), second.load(), first.load()])
		expect(axios.get).toHaveBeenCalledTimes(9)
		const urls = axios.get.mock.calls.map(([url]) => url)
		expect(new Set(urls).size).toBe(9)
		expect(
			urls.every((url) =>
				url.includes('/apps/openregister/api/objects/shillinq/'),
			),
		).toBe(true)
		expect(first.data.value).toBeTruthy()
		expect(first.data.value.accounts).toEqual([])
	})

	it('a failing schema yields an empty list and records the error', async () => {
		axios.get = vi.fn().mockImplementation(async (url) => {
			if (url.endsWith('/GLLine')) throw new Error('boom')
			return { data: { results: [{ ok: true }] } }
		})
		const { load, data, error } = useFinancialData()
		await load()
		expect(data.value.lines).toEqual([])
		expect(data.value.accounts).toEqual([{ ok: true }])
		expect(error.value).toBeInstanceOf(Error)
	})

	it('reload drops the cache and refetches', async () => {
		const { load, reload } = useFinancialData()
		await load()
		await reload()
		expect(axios.get).toHaveBeenCalledTimes(18)
	})
})
