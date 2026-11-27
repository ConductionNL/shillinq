/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InvoiceGenerator tick lists (sales-time-and-expense-billing REQ-STEB-001..003):
 * the unbilled hours and pass-through expenses come from the billable-work
 * endpoint, the request carries the ticked rows (an expense as a schema, id
 * and disbursement flag), and without an hours source the page says so.
 *
 * @spec openspec/changes/archive/2026-11-25-sales-time-and-expense-billing/tasks.md#task-4.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import Generator from '../../src/components/invoice/InvoiceGenerator.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const API = '/index.php/apps/shillinq/api/v1/invoices'

/** The Het Anker work: 12.5 hours at EUR 95 and three receipts. */
const WORK = {
	hours: {
		available: true,
		reason: '',
		entries: [
			{ id: 'h-1', person: 'S. de Vries', date: '2026-10-05', hours: 7.5, rate: 95, amount: 712.5 },
			{ id: 'h-2', person: 'S. de Vries', date: '2026-10-06', hours: 5, rate: 95, amount: 475 },
		],
	},
	expenses: [
		{ id: 'r-train', kind: 'Receipt', date: '2026-10-03', description: 'Treinreis Utrecht-Zwolle v.v.', cost: 42.8, markupRate: 0.1, markup: 4.28, amount: 47.08 },
		{ id: 'r-parking', kind: 'Receipt', date: '2026-10-03', description: 'Parkeren Zwolle', cost: 12.5, markupRate: 0, markup: 0, amount: 12.5 },
		{ id: 'r-kadaster', kind: 'Receipt', date: '2026-10-04', description: 'Uittreksel Kadaster Kade 12', cost: 3.7, markupRate: 0, markup: 0, amount: 3.7 },
	],
}

/**
 * A stand-in instance with data, computed and methods.
 *
 * @param {object} [fields] Fields to override (form is merged).
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const base = Generator.data()
	const self = { $emit: vi.fn(), ...base, ...fields, form: { ...base.form, ...(fields.form || {}) } }
	for (const [name, fn] of Object.entries(Generator.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(Generator.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

/**
 * Render the generator with the given state.
 *
 * @param {object} state Data to start from (form is merged).
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	const component = {
		...Generator,
		data() {
			const base = Generator.data()
			return { ...base, ...state, form: { ...base.form, ...(state.form || {}) } }
		},
	}
	return renderToString(createSSRApp(component))
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('loading the billable work', () => {
	it('asks the endpoint for the customer, project, period and rate card, and ticks nothing', async () => {
		http.get.mockResolvedValue({ data: WORK })
		const self = instance({
			form: { customerId: 'anker', projectId: 'p-kade', fromDate: '2026-10-01', toDate: '2026-10-31', rateCardId: 'rc-2026' },
			timeEntryIds: ['stale'],
		})
		await self.loadWork()
		expect(http.get).toHaveBeenCalledWith(`${API}/billable-work`, {
			params: { customerId: 'anker', projectId: 'p-kade', from: '2026-10-01', to: '2026-10-31', rateCardId: 'rc-2026' },
		})
		expect(self.work.hours.entries).toHaveLength(2)
		expect(self.work.expenses).toHaveLength(3)
		expect(self.timeEntryIds).toEqual([])
		expect(self.tickedHoursAmount).toBe(0)
	})

	it('loads nothing without a customer or for usage billing', async () => {
		await instance().loadWork()
		await instance({ form: { customerId: 'anker', billingModel: 'usage' } }).loadWork()
		expect(http.get).not.toHaveBeenCalled()
	})

	it('shows why the work could not be loaded', async () => {
		http.get.mockRejectedValue(new Error('500'))
		const self = instance({ form: { customerId: 'anker' } })
		await self.loadWork()
		expect(self.workError).toBe('The billable hours and expenses could not be loaded.')
		expect(self.work.expenses).toEqual([])
	})

	it('reloads when the query changes', () => {
		const self = instance({ form: { customerId: 'anker', projectId: 'p', fromDate: 'a', toDate: 'b', rateCardId: 'rc' } })
		self.loadWork = vi.fn()
		Generator.watch.workQuery.call(self)
		expect(self.loadWork).toHaveBeenCalledTimes(1)
		expect(self.workQuery).toBe('false|anker|p|a|b|rc')
	})
})

describe('the request built from ticked rows', () => {
	it('sends the ticked hours and each ticked expense as schema, id and disbursement', () => {
		const self = instance({
			form: { customerId: 'anker', projectId: 'p-kade' },
			work: WORK,
			timeEntryIds: ['h-1', 'h-2'],
			expenseItemIds: ['r-train', 'r-kadaster'],
			disbursementIds: ['r-kadaster', 'r-parking'],
		})
		expect(self.payload).toMatchObject({
			customerId: 'anker',
			timeEntryIds: ['h-1', 'h-2'],
			expenseIds: [],
			expenseItemRefs: [
				{ schema: 'Receipt', id: 'r-train', disbursement: false },
				{ schema: 'Receipt', id: 'r-kadaster', disbursement: true },
			],
		})
		expect(self.tickedHoursAmount).toBe(1187.5)
	})

	it('drafts the invoice with the ticked rows', async () => {
		http.post.mockResolvedValue({ data: { id: 'inv-1' } })
		const self = instance({ form: { customerId: 'anker' }, work: WORK, expenseItemIds: ['r-parking'] })
		await self.onSaveDraft()
		expect(http.post).toHaveBeenCalledWith(
			`${API}/generate`,
			expect.objectContaining({ expenseItemRefs: [{ schema: 'Receipt', id: 'r-parking', disbursement: false }] }),
		)
	})
})

describe('what the lists show', () => {
	it('lists hours with their rate and amount and expenses with cost, markup and amount', async () => {
		const html = await render({ form: { customerId: 'anker', projectId: 'p-kade' }, work: WORK, timeEntryIds: ['h-1', 'h-2'] })
		expect(html).toContain('2026-10-05 S. de Vries: 7.5 hours at EUR 95,00, EUR 712,50')
		expect(html).toContain('Ticked hours: EUR 1.187,50')
		expect(html).toContain('Treinreis Utrecht-Zwolle v.v.: cost EUR 42,80, markup EUR 4,28, EUR 47,08')
		expect(html).toContain('Disbursement, outside the VAT base')
	})

	it('says hours are unavailable when no hours source is connected, not a zero total', async () => {
		const html = await render({
			form: { customerId: 'anker', projectId: 'p-kade' },
			work: { hours: { available: false, reason: 'no source', entries: [] }, expenses: [] },
		})
		expect(html).toContain('Hours are not available: the hours app is not connected.')
		expect(html).not.toContain('Ticked hours')
	})

	it('says every hour is already invoiced when the project has none left', async () => {
		const html = await render({ form: { customerId: 'anker', projectId: 'p-kade' } })
		expect(html).toContain('Every approved hour in this period is already on an invoice.')
		expect(html).toContain('This customer has no pass-through expenses that are not on an invoice yet.')
	})

	it('asks for a project before listing hours', async () => {
		const html = await render({ form: { customerId: 'anker' } })
		expect(html).toContain('Choose a project to list its unbilled hours.')
	})
})
