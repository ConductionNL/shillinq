/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The three-way match exception panel: the comparison, divergences, open
 * and resolved states as they render (server-side, no DOM), the match load
 * with its related documents, and the three resolutions.
 *
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { default: Panel } = await import('../../src/components/three-way-match/ThreeWayMatchExceptionPanel.vue')

const t = (app, text) => text

const match = (over = {}) => ({ id: 'm-1', invoiceId: 'inv-1', matchStatus: 'exception_price', matchedPoIds: ['po-1'], matchedGrnIds: ['grn-1'], createdAt: '2026-11-01T10:00:00Z', divergenceDetails: [], ...over })
const po = { poNumber: 'PO-1', expectedDeliveryDate: '2026-10-01', totalInclVat: 10000, totalVat: 1735 }
const grn = { grnNumber: 'GRN-1', receivedAt: '2026-10-02T08:00:00Z' }
const invoice = { invoiceNumber: 'F-1', invoiceDate: '2026-10-03', totalInclVat: 12100, totalVat: 2100 }

/**
 * Render the panel with its data replaced, without the created() load.
 *
 * @param {object} state The data.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	const base = { match: null, invoice: null, po: null, grn: null, notes: '', loading: false, submitting: false, error: '', actionError: '', dispatch: null }
	const app = createSSRApp({ ...Panel, created: undefined, data: () => ({ ...base, ...state }) }, { id: 'm-1', administrationId: 'adm-1' })
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * A plain instance of the panel, its computed values readable.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function panel(over = {}) {
	const self = { id: 'm-1', administrationId: 'adm-1', t, ...Panel.data.call({}), ...over }
	for (const [name, method] of Object.entries(Panel.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(Panel.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

describe('ThreeWayMatchExceptionPanel render', () => {
	it('compares the three documents side by side and lists the divergences', async () => {
		const html = await render({
			match: match({ divergenceDetails: [{ field: 'amount', expected: '100.00', actual: '121.00', deltaCents: 2100, deltaPercentage: 2100 }, { field: 'date', expected: 'a', actual: 'b' }] }),
			po, grn, invoice,
		})
		expect(html).toMatch(/Match exception\s*#m-1/)
		expect(html).toContain('twm-exception__pill--exception_price')
		expect(html).toMatch(/Invoice\s*:\s*F-1/)
		expect(html).toMatch(/data-testid="twm-exception-row-reference"[^>]*><td[^>]*>Reference<\/td><td[^>]*>PO-1<\/td><td[^>]*>GRN-1<\/td><td[^>]*>F-1<\/td>/)
		expect(html).toMatch(/data-testid="twm-exception-row-amount"[^>]*>(?:<td[^>]*>[^<]*<\/td>){2}<td[^>]*>—<\/td>/)
		expect(html).toContain('data-testid="twm-exception-div-0"')
		expect(html).toMatch(/data-testid="twm-exception-div-1"[^>]*><strong[^>]*>date<\/strong>: expected a · actual b\s*<!--/)
		expect(html).toContain('data-testid="twm-exception-actions"')
		expect(html).toMatch(/data-testid="twm-exception-accept"[^>]*disabled/)
	})

	it('enables the resolutions once a motivation is typed, and shows a refusal', async () => {
		const html = await render({ match: match(), notes: 'Price agreed by phone', actionError: 'Too late' })
		expect(html).toMatch(/data-testid="twm-exception-accept"(?![^>]*disabled)/)
		expect(html).toMatch(/data-testid="twm-exception-action-error"[^>]*>\s*Too late/)
		expect(html).not.toContain('twm-exception-divergence')
		expect(html).toMatch(/data-testid="twm-exception-row-reference"[^>]*><td[^>]*>Reference<\/td><td[^>]*>—<\/td><td[^>]*>—<\/td><td[^>]*>—<\/td>/)
	})

	it('shows a resolved match with its resolution and dispatch', async () => {
		const html = await render({ match: match({ resolutionAction: 'credit_note_requested', resolvedBy: 'alice', resolvedAt: '2026-11-02T09:00:00Z', resolutionNotes: 'Short delivery' }), dispatch: { dispatchId: 'd-9' } })
		expect(html).toContain('data-testid="twm-exception-resolved"')
		expect(html).not.toContain('twm-exception-actions')
		expect(html).toContain('Dispute filed (UBL CreditNote)')
		expect(html).toContain('alice')
		expect(html).toContain('Short delivery')
		expect(html).toMatch(/data-testid="twm-exception-dispatch-id"[^>]*>\s*CreditNote dispatch\s*:\s*d-9/)
		const bare = await render({ match: match({ resolvedAt: '2026-11-02T09:00:00Z' }) })
		expect(bare).not.toContain('twm-exception-dispatch-id')
		expect((bare.match(/<dd[^>]*>—<\/dd>/g) || []).length).toBe(3)
	})

	it('renders the loading and failed states, and nothing without a match', async () => {
		expect(await render({ loading: true })).toContain('data-testid="twm-exception-loading"')
		expect(await render({ error: 'Gone' })).toMatch(/data-testid="twm-exception-error"[^>]*>\s*Gone/)
		expect(await render({})).not.toContain('twm-exception-header')
	})
})

describe('ThreeWayMatchExceptionPanel behaviour', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('loads the match and its first PO, GRN and invoice', async () => {
		const view = panel()
		const docs = { ThreeWayMatch: match(), SupplierInvoice: invoice, PurchaseOrder: po, GoodsReceiptNote: grn }
		axios.get.mockImplementation(async (url) => ({ data: docs[url.split('/').at(-2)] }))
		await view.loadMatch()

		expect(axios.get.mock.calls.map((c) => c[0].replace('/index.php/apps/openregister/api/objects/shillinq/', ''))).toEqual([
			'ThreeWayMatch/m-1', 'SupplierInvoice/inv-1', 'PurchaseOrder/po-1', 'GoodsReceiptNote/grn-1',
		])
		expect([view.invoice, view.po, view.grn, view.loading]).toEqual([invoice, po, grn, false])
	})

	it('keeps placeholders for related documents that fail, and loads none without references', async () => {
		const view = panel()
		axios.get.mockImplementation(async (url) => {
			if (url.endsWith('/ThreeWayMatch/m-1')) {
				return { data: match() }
			}
			throw new Error('gone')
		})
		await view.loadMatch()
		expect([view.invoice, view.po, view.grn]).toEqual([null, null, null])

		axios.get.mockReset()
		axios.get.mockResolvedValueOnce({ data: match({ invoiceId: '', matchedPoIds: 'x', matchedGrnIds: null }) })
		await view.loadMatch()
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('reports a failed match load', async () => {
		const view = panel()
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Not found' } } })
		await view.loadMatch()
		expect(view.error).toBe('Not found')
		axios.get.mockRejectedValueOnce(new Error('x'))
		await view.loadMatch()
		expect([view.error, view.loading]).toEqual(['Failed to load match', false])
	})

	it('requires a motivation before any resolution', async () => {
		const view = panel({ notes: '   ' })
		await view.onAccept()
		expect(view.actionError).toBe('A motivation / reason is required.')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('posts each resolution with its trimmed reason and takes the answer', async () => {
		const resolved = match({ resolutionAction: 'accept' })
		const cases = [
			['onAccept', 'accept', 'resolutionNotes', { match: resolved, dispatch: { dispatchId: 'd-1' } }],
			['onDispute', 'dispute', 'disputeReason', { match: resolved }],
			['onReject', 'reject', 'rejectionReason', resolved],
		]
		for (const [method, path, key, answer] of cases) {
			const view = panel({ notes: '  because  ', match: match() })
			axios.post.mockResolvedValueOnce({ data: answer })
			await view[method]()
			expect(axios.post).toHaveBeenLastCalledWith(`/index.php/apps/shillinq/api/three-way-match/exceptions/${path}`, { administrationId: 'adm-1', matchId: 'm-1', [key]: 'because' })
			expect([view.match, view.notes, view.submitting, view.isResolved]).toEqual([resolved, '', false, true])
			expect(view.dispatch).toEqual(path === 'accept' ? { dispatchId: 'd-1' } : null)
		}
	})

	it('reports a refused resolution and keeps the motivation', async () => {
		const view = panel({ notes: 'because', match: match() })
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Already resolved' } } })
		await view.onReject()
		expect([view.actionError, view.notes, view.submitting]).toEqual(['Already resolved', 'because', false])
		axios.post.mockRejectedValueOnce(new Error('x'))
		await view.onDispute()
		expect(view.actionError).toBe('Resolution failed')
	})

	it('reads as open without a match and without a resolution', () => {
		expect([panel().isResolved, panel().statusPillClass, panel().divergenceRows]).toEqual([false, '', []])
		expect(panel({ match: match() }).isResolved).toBe(false)
	})
})
