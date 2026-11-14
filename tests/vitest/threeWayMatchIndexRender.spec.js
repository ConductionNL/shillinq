/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The three-way match index: the table cells as they render (server-side,
 * no DOM), the status filter, the list load with its invoice prefetch, and
 * the re-evaluate round trip.
 *
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'emptyLabel'],
		render() {
			if (this.rows.length === 0) {
				return h('p', { 'data-stub': 'empty' }, this.emptyLabel)
			}
			return h('table', this.rows.map((row) => h('tr', this.columns.map((c) => h('td', { 'data-col': c.key }, this.$slots['cell-' + c.key]?.({ row }) ?? row[c.key])))))
		},
	},
}))

const { default: ThreeWayMatchIndex } = await import('../../src/components/three-way-match/ThreeWayMatchIndex.vue')

const t = (app, text) => text

const matches = [
	{ id: 'm-1', invoiceId: 'inv-1', matchStatus: 'exception_price', matchedPoIds: ['po-1', 'po-2'], matchedGrnIds: [], createdAt: '2026-11-01T10:00:00Z' },
	{ id: 'm-2', invoiceId: 'inv-2', matchStatus: 'auto_approved', matchedPoIds: [], matchedGrnIds: ['grn-1'], createdAt: '' },
]
const invoices = { 'inv-1': { invoiceNumber: 'F-100', supplierId: 'sup-1', totalInclVat: 12345, currency: 'USD' } }

/**
 * Render the index with its data replaced, without the created() load.
 *
 * @param {object} state The data.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	const base = { matches: [], invoices: {}, loading: false, error: '', statusFilter: '', reevaluating: '' }
	const app = createSSRApp({ ...ThreeWayMatchIndex, created: undefined, data: () => ({ ...base, ...state }) }, { administrationId: 'adm-1' })
	app.config.globalProperties.t = t
	app.component('RouterLink', { props: ['to'], render() { return h('a', { 'data-to': `${this.to.name}:${this.to.params.id}` }, this.$slots.default?.()) } })
	return renderToString(app)
}

/**
 * A plain instance of the index, its computed values readable.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function index(over = {}) {
	const self = { administrationId: 'adm-1', t, ...ThreeWayMatchIndex.data.call({}), ...over }
	for (const [name, method] of Object.entries(ThreeWayMatchIndex.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(ThreeWayMatchIndex.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

describe('ThreeWayMatchIndex render', () => {
	it('renders each match with its invoice, supplier, amount, status and references', async () => {
		const html = await render({ matches, invoices, reevaluating: 'm-2' })
		expect(html).toMatch(/data-to="SupplierInvoiceDetail:inv-1"[^>]*>\s*F-100/)
		expect(html).toMatch(/data-to="SupplierInvoiceDetail:inv-2"[^>]*>\s*inv-2/)
		expect(html).toContain('USD 123.45')
		expect(html).toMatch(/twm-index__pill--exception_price[^>]*data-testid="twm-status-m-1"[^>]*>\s*Price exception/)
		expect(html).toMatch(/PO\s*:\s*po-1, po-2/)
		expect(html).toMatch(/GRN\s*:\s*grn-1/)
		expect(html).toMatch(/data-testid="twm-reevaluate-m-2"[^>]*disabled[^>]*>\s*Evaluating…/)
		expect(html).toMatch(/data-testid="twm-reevaluate-m-1"(?![^>]*disabled)[^>]*>\s*Re-evaluate/)
		expect(html).toMatch(/<option value="fraud_alert"[^>]*>\s*Fraud alert\s*<\/option>/)
	})

	it('narrows the rows to the chosen status', async () => {
		const html = await render({ matches, invoices, statusFilter: 'auto_approved' })
		expect(html).not.toContain('twm-status-m-1')
		expect(html).toContain('twm-status-m-2')
	})

	it('renders the loading, failed and empty states', async () => {
		expect(await render({ loading: true })).toContain('data-testid="twm-index-loading"')
		expect(await render({ error: 'Denied' })).toMatch(/data-testid="twm-index-error"[^>]*>\s*Denied/)
		expect(await render({})).toContain('No matches recorded yet.')
	})
})

describe('ThreeWayMatchIndex behaviour', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('loads the matches and prefetches each invoice once, tolerating a missing one', async () => {
		const view = index()
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/ThreeWayMatch')) {
				return { data: { results: [...matches, { id: 'm-3', invoiceId: 'inv-1' }] } }
			}
			if (url.endsWith('/inv-2')) {
				throw new Error('gone')
			}
			return { data: invoices['inv-1'] }
		})
		await view.loadMatches()

		expect(axios.get.mock.calls.map((c) => c[0])).toEqual([
			'/index.php/apps/openregister/api/objects/shillinq/ThreeWayMatch',
			'/index.php/apps/openregister/api/objects/shillinq/SupplierInvoice/inv-1',
			'/index.php/apps/openregister/api/objects/shillinq/SupplierInvoice/inv-2',
		])
		expect([view.matches.length, view.invoices, view.loading]).toEqual([3, { 'inv-1': invoices['inv-1'], 'inv-2': null }, false])
	})

	it('reads a bare list, ignores a non-list and reports a failed load', async () => {
		const view = index()
		axios.get.mockResolvedValueOnce({ data: [matches[1]] }).mockResolvedValue({ data: null })
		await view.loadMatches()
		expect(view.matches).toEqual([matches[1]])
		axios.get.mockReset()
		axios.get.mockResolvedValueOnce({ data: { results: 'x' } })
		await view.loadMatches()
		expect(view.matches).toEqual([])
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		await view.loadMatches()
		expect(view.error).toBe('No access')
		axios.get.mockRejectedValueOnce(new Error('x'))
		await view.loadMatches()
		expect([view.error, view.loading]).toEqual(['Failed to load three-way matches', false])
	})

	it('re-evaluates a match and swaps in the answer, or reloads when there is none', async () => {
		const view = index({ matches: [...matches] })
		axios.post.mockResolvedValueOnce({ data: { id: 'm-1', matchStatus: 'within_tolerance' } })
		await view.reevaluate(matches[0])
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/three-way-matches/evaluate', { administrationId: 'adm-1', invoiceId: 'inv-1' })
		expect([view.matches[0].matchStatus, view.reevaluating]).toEqual(['within_tolerance', ''])

		view.loadMatches = vi.fn()
		axios.post.mockResolvedValueOnce({ data: {} })
		await view.reevaluate({ ...matches[1], administrationId: 'adm-9' })
		expect(view.loadMatches).toHaveBeenCalledTimes(1)

		axios.post.mockRejectedValueOnce(new Error('x'))
		await view.reevaluate(matches[1])
		expect([view.error, view.reevaluating]).toEqual(['Re-evaluation failed', ''])
		await view.reevaluate({ id: 'm-x' })
		expect(axios.post).toHaveBeenCalledTimes(3)
	})

	it('adds an unknown answered match to the top', () => {
		const view = index({ matches: [matches[0]] })
		view.replaceMatch({ id: 'm-new' }, { id: 'm-new-2' })
		expect(view.matches.map((m) => m.id)).toEqual(['m-new-2', 'm-1'])
	})

	it('falls back to dashes for unknown invoices, amounts, statuses and dates', () => {
		const view = index({ invoices: { 'inv-3': { totalInclVat: null } } })
		expect([view.supplierInvoiceLabel({}), view.supplierLabel({ invoiceId: 'x' }), view.amountLabel({ invoiceId: 'inv-3' }), view.amountLabel({ invoiceId: 'x' })]).toEqual(['—', '—', '—', '—'])
		expect([view.statusLabel('new_status'), view.statusLabel(''), view.statusLabel('within_tolerance')]).toEqual(['new_status', '—', 'Within tolerance'])
		expect(view.formatDate('')).toBe('—')
		expect(index({ invoices: { a: { totalInclVat: 500 } } }).amountLabel({ invoiceId: 'a' })).toBe('EUR 5.00')
	})
})
