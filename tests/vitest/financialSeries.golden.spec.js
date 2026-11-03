/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the financial dashboard computations over generated
 * ledgers: account classification, posted lines per month, the monthly
 * series, billable hours, the forecast roll-up, month ranges and both KPI
 * sets. Reference dates are local noon, so the record holds in any zone.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { describe, it } from 'vitest'
import {
	billableSeries,
	classifyAccounts,
	computeKpis,
	computeRangeKpis,
	forecastByMonth,
	monthlyFinancialSeries,
	monthsInRange,
	postedLinesByMonth,
} from '../../src/components/dashboard/financial/financialSeries.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('financialSeries')

const MONTHS = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06']
const DATES = [
	'2026-01-05',
	'2026-02-28 10:00:00',
	'2026-03-31T23:00:00Z',
	'2026-05-01',
	'2026-06-30',
	'2025-12-31',
	'bad',
	'',
	null,
	undefined,
	20260101,
]

/**
 * A generated ledger.
 *
 * @param {number} seed Seed.
 * @return {object} Accounts, transactions, lines, invoices, payables, hours, weeks.
 */
function ledger(seed) {
	const r = seeded(seed)
	const accounts = [
		{ accountNumber: '8000', accountType: 'revenue' },
		{ accountNumber: 8100, accountType: 'revenue' },
		{ accountNumber: '4000', accountType: 'expenses' },
		{ accountNumber: '1000', accountType: 'assets', name: 'Kas' },
		{ accountNumber: '1100', accountType: 'assets', name: 'ING Bank' },
		{ accountNumber: '2000', accountType: 'assets', name: 'Debiteuren' },
		{ accountNumber: '3000', accountType: 'assets', name: 'cash box' },
		{ accountNumber: '1500', accountType: 'assets', name: 'Voorraad' },
		{ accountNumber: '8000', accountType: 'expenses' },
		{ accountNumber: '', accountType: 'revenue' },
		{ accountType: 'expenses' },
		{ accountNumber: '9999', accountType: 'equity' },
	].filter(() => r.next() > 0.1)
	const transactions = []
	for (let i = 0; i < 12; i++) {
		transactions.push({
			id: r.next() > 0.2 ? `tx-${i}` : undefined,
			'@self': r.next() > 0.5 ? { id: `self-${i}` } : undefined,
			transactionNumber: r.next() > 0.4 ? `T${i}` : '',
			state: r.pick(['posted', 'posted', 'draft', 'reversed']),
			postingDate: r.pick(DATES),
		})
	}
	const refs = [
		'tx-1',
		'self-2',
		'T3',
		'T4',
		'tx-5',
		'self-6',
		'T7',
		'missing',
		'',
		'tx-9',
		'T10',
		'self-11',
	]
	const lines = []
	for (let i = 0; i < 40; i++) {
		lines.push({
			transactionId: r.pick(refs),
			accountNumber: r.pick([
				'8000',
				'8100',
				'4000',
				'1000',
				'1100',
				'1500',
				'2000',
				'3000',
				'9999',
				undefined,
			]),
			side: r.pick(['credit', 'debit', 'other']),
			amount: r.pick([100, 250.555, '75.10', 'x', 0, -20, undefined]),
		})
	}
	const arInvoices = [
		{
			lifecycleState: 'issued',
			dueDate: '2026-05-01',
			grossAmount: 121,
			invoiceNumber: 'A1',
		},
		{ lifecycleState: 'overdue', dueDate: '2026-07-01', netAmount: 100 },
		{ lifecycleState: 'paid', dueDate: '2026-01-01', grossAmount: 50 },
		{ lifecycleState: 'issued', dueDate: '2026-06-30', grossAmount: 'x' },
		{ lifecycleState: 'issued', dueDate: '2026-06-01', grossAmount: 0.1 },
		{ lifecycleState: 'issued', dueDate: '2026-06-02', grossAmount: 0.2 },
	].filter(() => r.next() > 0.2)
	const apTransactions = [
		{ state: 'received', dueDate: '2026-04-01', totalAmount: 80 },
		{ state: 'partially-paid', dueDate: '2026-08-01', totalAmount: '40.5' },
		{ state: 'paid', dueDate: '2026-04-01', totalAmount: 99 },
		{ state: 'overdue', dueDate: '2026-03-01', totalAmount: 33.333 },
		{ state: 'issued', dueDate: '2026-03-02', totalAmount: 0.001 },
	].filter(() => r.next() > 0.2)
	const hourEntries = []
	for (let i = 0; i < 10; i++) {
		hourEntries.push({
			date: r.pick(DATES),
			hours: r.pick([1, 2.5, '3', 'x', 0]),
			recognisedRate: r.pick([0, 50, '75', null, -1]),
		})
	}
	const weeks = []
	for (let i = 0; i < 8; i++) {
		weeks.push({
			weekStart: r.pick([
				'2026-05-04',
				'2026-06-01',
				'2026-06-29',
				'2026-07-06',
				'2026-08-03',
				'bad',
				null,
			]),
			inflows_total: r.pick([100, '20.5', 'x', undefined]),
			outflows_total: r.pick([50, '10.25', null]),
		})
	}
	return {
		accounts,
		transactions,
		lines,
		arInvoices,
		apTransactions,
		hourEntries,
		weeks,
	}
}

describe('financialSeries golden', () => {
	it('matches the record for generated ledgers', () => {
		for (let seed = 1; seed <= 40; seed++) {
			const l = ledger(seed)
			check(
				`${seed} classifyAccounts`,
				outcome(() => classifyAccounts(l.accounts)),
			)
			check(
				`${seed} postedLinesByMonth`,
				outcome(() => postedLinesByMonth(l.transactions, l.lines)),
			)
			check(
				`${seed} monthlyFinancialSeries`,
				outcome(() => monthlyFinancialSeries({ ...l, months: MONTHS })),
			)
			check(
				`${seed} billableSeries`,
				outcome(() => billableSeries(l.hourEntries, MONTHS)),
			)
			check(
				`${seed} forecastByMonth`,
				outcome(() => forecastByMonth(l.weeks, '2026-05')),
			)
			check(
				`${seed} computeKpis`,
				outcome(() => computeKpis(l, new Date(2026, 5, 15, 12))),
			)
			check(
				`${seed} computeKpis january`,
				outcome(() => computeKpis(l, new Date(2026, 0, 2, 12))),
			)
			check(
				`${seed} computeRangeKpis`,
				outcome(() => computeRangeKpis(l, MONTHS)),
			)
		}
	})

	it('matches the record for edge inputs', () => {
		check(
			'classifyAccounts null',
			outcome(() => classifyAccounts(null)),
		)
		check(
			'postedLinesByMonth null',
			outcome(() => postedLinesByMonth(null, null)),
		)
		check(
			'billableSeries null',
			outcome(() => billableSeries(null, MONTHS)),
		)
		check(
			'forecastByMonth null',
			outcome(() => forecastByMonth(null, '2026-01')),
		)
		check(
			'computeKpis empty',
			outcome(() => computeKpis({}, new Date(2026, 2, 3, 12))),
		)
		check(
			'computeRangeKpis empty',
			outcome(() => computeRangeKpis({}, [])),
		)
		const ranges = [
			['2026-01-15', '2026-04-02'],
			['2025-11-30T10:00:00Z', '2026-02-01'],
			['2026-04-01', '2026-01-01'],
			['2020-01-01', '2030-12-31'],
			['', '2026-01-01'],
			['2026-01-01', null],
			['bad', '2026-01-01'],
			['2026-02-29', '2026-03-01'],
		]
		for (const [from, to] of ranges) {
			check(
				`monthsInRange ${from} ${to}`,
				outcome(() => monthsInRange(from, to)),
			)
		}
	})
})
