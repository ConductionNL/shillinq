/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InvoiceGenerator: which fields each billing model asks for, the request it
 * sends, and the draft, preview and post actions against the invoice API.
 * The template is rendered on the server renderer (no DOM in this suite) to
 * check what each billing model shows.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import Generator from '../../src/components/invoice/InvoiceGenerator.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const API = '/index.php/apps/shillinq/api/v1/invoices'

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
 * @param {object} [props] Props.
 * @return {Promise<string>} The HTML.
 */
function render(state, props = {}) {
	const component = {
		...Generator,
		data() {
			const base = Generator.data()
			return { ...base, ...state, form: { ...base.form, ...(state.form || {}) } }
		},
	}
	return renderToString(createSSRApp(component, props))
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('what each billing model asks for', () => {
	it.each([
		['t_and_m', { needsRateCard: true, needsRetainer: false, needsFixedFee: false, needsMilestone: false, needsUsage: false }],
		['retainer', { needsRateCard: false, needsRetainer: true, needsFixedFee: false, needsMilestone: false, needsUsage: false }],
		['fixed_fee', { needsRateCard: false, needsRetainer: false, needsFixedFee: true, needsMilestone: false, needsUsage: false }],
		['milestone', { needsRateCard: false, needsRetainer: false, needsFixedFee: false, needsMilestone: true, needsUsage: false }],
		['usage', { needsRateCard: false, needsRetainer: false, needsFixedFee: false, needsMilestone: false, needsUsage: true }],
		['mixed', { needsRateCard: true, needsRetainer: true, needsFixedFee: true, needsMilestone: false, needsUsage: false }],
	])('%s', (billingModel, expected) => {
		const self = instance({ form: { billingModel } })
		const actual = Object.fromEntries(Object.keys(expected).map((key) => [key, self[key]]))
		expect(actual).toEqual(expected)
	})

	it('renders the rate card and the id lists for time and materials', async () => {
		const html = await render({}, { rateCards: [{ id: 'rc-1', name: 'Standard' }] })
		expect(html).toContain('rc-1')
		expect(html.match(/<textarea/g)).toHaveLength(3)
		expect(html).not.toContain('invoice-generator__totals')
	})

	it('renders the retainer schedules, fixed fee and milestone fields only when asked', async () => {
		const mixed = await render({ form: { billingModel: 'mixed' } }, { retainers: [{ id: 'rs-9', name: 'Q1' }] })
		expect(mixed).toContain('rs-9')
		const milestone = await render({ form: { billingModel: 'milestone', milestoneId: 'ms-3' } })
		expect(milestone).toContain('ms-3')
		expect(milestone).not.toContain('rs-9')
	})

	it('renders the readings and their load error for usage billing, without the id lists', async () => {
		const html = await render({
			form: { billingModel: 'usage' },
			readings: [{ id: 'r1', periodStart: '2026-01-01', periodEnd: '2026-01-31', quantity: 12, unit: 'kWh', ratedAmount: 3.5 }],
			meterReadingIds: ['r1'],
			readingsError: 'The readings could not be loaded.',
		})
		expect(html).toContain('2026-01-01 to 2026-01-31: 12 kWh, EUR 3,50')
		expect(html).toContain('The readings could not be loaded.')
		// Only the notes field is left; the time and expense id lists are hidden.
		expect(html.match(/<textarea/g)).toHaveLength(1)
	})

	it('renders the totals and the error once there is a draft', async () => {
		const html = await render({
			draftId: 'inv-1',
			totals: { netAmount: 100, vatAmount: 21, grossAmount: 121 },
			error: 'Failed to post invoice',
		})
		expect(html).toContain('invoice-generator__totals')
		expect(html).toContain('121,00')
		expect(html).toContain('Failed to post invoice')
	})
})

describe('the request', () => {
	it('splits the id lists, converts the fee to cents and sends readings only for usage', () => {
		const self = instance({
			form: { customerId: 'c1' },
			timeIdsRaw: ' t1, t2 ,,t3 ',
			expenseIdsRaw: '',
			fixedFeeEuros: '12.345',
			meterReadingIds: ['r1'],
		})
		expect(self.payload).toMatchObject({
			customerId: 'c1',
			billingModel: 't_and_m',
			timeEntryIds: ['t1', 't2', 't3'],
			expenseIds: [],
			fixedFeeCents: 1235,
			meterReadingIds: [],
		})
		self.form.billingModel = 'usage'
		expect(self.payload.meterReadingIds).toEqual(['r1'])
	})

	it('reads no ids from a non-string', () => {
		expect(instance().parseIds(null)).toEqual([])
		expect(instance().parseIds(['a'])).toEqual([])
	})

	it('reloads the readings when the usage query changes', () => {
		const self = instance()
		self.loadReadings = vi.fn()
		Generator.watch.usageQuery.call(self)
		expect(self.loadReadings).toHaveBeenCalledTimes(1)
		expect(instance({ form: { customerId: 'c', fromDate: 'a', toDate: 'b' } }).usageQuery).toBe('t_and_m|c|a|b')
	})
})

describe('the actions', () => {
	it('drafts the invoice, keeps its id and summary and announces it', async () => {
		const result = { id: 'inv-1', summary: { netAmount: 1, vatAmount: 0.21, grossAmount: 1.21 } }
		http.post.mockResolvedValue({ data: result })
		const self = instance({ form: { customerId: 'c1' } })
		await self.onSaveDraft()
		expect(http.post).toHaveBeenCalledWith(`${API}/generate`, expect.objectContaining({ customerId: 'c1' }))
		expect(self.draftId).toBe('inv-1')
		expect(self.totals).toEqual(result.summary)
		expect(self.$emit).toHaveBeenCalledWith('draft-created', result)
		expect(self.busy).toBe(false)
	})

	it('takes the invoice id and flat totals when there is no summary', async () => {
		http.post.mockResolvedValue({ data: { invoiceId: 'inv-2', netAmount: 5, vatAmount: 1, grossAmount: 6 } })
		const self = instance()
		await self.onSaveDraft()
		expect(self.draftId).toBe('inv-2')
		expect(self.totals).toEqual({ netAmount: 5, vatAmount: 1, grossAmount: 6 })
	})

	it('shows why a draft failed', async () => {
		http.post.mockRejectedValueOnce(new Error('Customer unknown'))
		const self = instance()
		await self.onSaveDraft()
		expect(self.error).toBe('Customer unknown')
		http.post.mockRejectedValueOnce({})
		await self.onSaveDraft()
		expect(self.error).toBe('Failed to draft invoice')
		expect(self.draftId).toBeNull()
		expect(self.busy).toBe(false)
	})

	it('drafts first when previewing without a draft, then opens the PDF', async () => {
		const open = vi.fn()
		vi.stubGlobal('window', { open })
		http.post.mockResolvedValue({ data: { id: 'inv 3' } })
		const self = instance()
		await self.onPreviewPdf()
		expect(open).toHaveBeenCalledWith(`${API}/inv%203/pdf`, '_blank', 'noopener')
		expect(self.$emit).toHaveBeenCalledWith('pdf-previewed', 'inv 3')
	})

	it('previews nothing when the draft fails', async () => {
		http.post.mockRejectedValue(new Error('no'))
		const self = instance()
		await self.onPreviewPdf()
		expect(self.$emit).not.toHaveBeenCalledWith('pdf-previewed', expect.anything())
	})

	it('posts a draft to accounts receivable and announces it', async () => {
		http.post.mockResolvedValue({ data: { status: 'posted' } })
		const self = instance({ draftId: 'inv-1' })
		await self.onPost()
		expect(http.post).toHaveBeenCalledWith(`${API}/inv-1/post`)
		expect(self.$emit).toHaveBeenCalledWith('posted', { status: 'posted' })
		expect(self.busy).toBe(false)
	})

	it('posts nothing without a draft, and shows why a post failed', async () => {
		await instance().onPost()
		expect(http.post).not.toHaveBeenCalled()

		http.post.mockRejectedValueOnce(new Error('Period closed'))
		const self = instance({ draftId: 'inv-1' })
		await self.onPost()
		expect(self.error).toBe('Period closed')
		http.post.mockRejectedValueOnce(null)
		await self.onPost()
		expect(self.error).toBe('Failed to post invoice')
	})
})
