/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The three-way-match audit trail: the ledger summary and timeline as they
 * render (server-side, no DOM), the export envelope, and the ledger load
 * and package export with their failures.
 *
 * @spec openspec/specs/three-way-match/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { default: AuditTrailDetail } = await import('../../src/components/three-way-match/AuditTrailDetail.vue')

const t = (app, text) => text

const ledger = {
	summary: { invoiceNumber: 'INV-7', supplierId: 'sup-1', totalInclVat: 123456, currency: 'USD' },
	events: [
		{ event: 'po_created', objectType: 'PurchaseOrder', objectId: 'po-1', actor: 'alice', timestamp: '2026-11-01T09:30:00Z', details: { amount: 10, approved: true, lines: [1, 2], note: null } },
		{ event: 'match_resolved', objectType: 'Match', objectId: 'm-1', actor: '', timestamp: 'yesterday', details: {} },
		{ event: 'custom_step', objectType: 'X', objectId: 'x-1', timestamp: '' },
	],
}

/**
 * Render the trail with its data replaced, without the created() load.
 *
 * @param {object} state The data.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	const base = { ledger: null, loading: false, error: '', exporting: false, exportError: '', exportEnvelope: null }
	const app = createSSRApp({ ...AuditTrailDetail, created: undefined, data: () => ({ ...base, ...state }) }, { invoiceId: 'inv-1', administrationId: 'adm-1' })
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * A plain instance of the trail, its computed values readable.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function trail(over = {}) {
	const self = { invoiceId: 'inv-1', administrationId: 'adm-1', t, ...AuditTrailDetail.data.call({}), ...over }
	for (const [name, method] of Object.entries(AuditTrailDetail.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(AuditTrailDetail.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

describe('AuditTrailDetail render', () => {
	it('summarises the invoice and lists each event with its details', async () => {
		const html = await render({ ledger })
		expect(html).toMatch(/data-testid="audit-trail-invoice-number"[^>]*>INV-7</)
		expect(html).toMatch(/data-testid="audit-trail-supplier"[^>]*>sup-1</)
		expect(html).toMatch(/data-testid="audit-trail-total"[^>]*>\s*1\.234,56 USD/)
		expect(html).toMatch(/data-testid="audit-trail-event-count"[^>]*>3</)
		expect((html.match(/data-testid="audit-trail-event"/g) || []).length).toBe(3)
		expect(html).toContain('audit-trail__event--po')
		expect(html).toContain('audit-trail__event--match-resolved')
		expect(html).toContain('2026-11-01 09:30:00 UTC')
		expect(html).toContain('Purchase order created')
		expect(html).toContain('custom_step')
		expect(html).toMatch(/Actor:\s*alice/)
		expect(html).toMatch(/Actor:\s*—/)
		expect(html).toContain('PurchaseOrder #po-1')
		expect((html.match(/data-testid="audit-trail-event-details"/g) || []).length).toBe(1)
		expect(html).toMatch(/<dt[^>]*>\s*lines\s*<\/dt><dd[^>]*>\s*\[1,2\]\s*<\/dd>/)
		expect(html).toContain('Export audit package (ZIP)')
	})

	it('shows the export envelope, an export failure and the busy label', async () => {
		const html = await render({ ledger, exporting: true, exportError: 'Disk full', exportEnvelope: { packageId: 'pkg-1', sha256: 'abc', retentionYears: 7, eventCount: 3, archived: false } })
		expect(html).toContain('Exporting…')
		expect(html).toMatch(/data-testid="audit-trail-export-error"[^>]*>\s*Disk full/)
		expect(html).toMatch(/data-testid="audit-trail-export-package-id"[^>]*>\s*pkg-1/)
		expect(html).toMatch(/data-testid="audit-trail-export-retention"[^>]*>\s*7\s+years/)
		expect(html).toMatch(/data-testid="audit-trail-export-archived"[^>]*>\s*Pending/)
		expect(await render({ ledger, exportEnvelope: { archived: true } })).toMatch(/data-testid="audit-trail-export-archived"[^>]*>\s*Yes/)
	})

	it('shows the loading and failed states, and nothing without a ledger', async () => {
		expect(await render({ loading: true })).toContain('data-testid="audit-trail-loading"')
		expect(await render({ error: 'Not found' })).toMatch(/data-testid="audit-trail-error"[^>]*>\s*Not found/)
		const none = await render({})
		expect(none).not.toContain('audit-trail-summary')
		expect(none).not.toContain('audit-trail-error')
	})
})

describe('AuditTrailDetail behaviour', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('falls back to dashes, zero and EUR without a summary', () => {
		const view = trail({ ledger: { events: 'x' } })
		expect([view.events, view.summaryInvoiceNumber, view.summarySupplierId, view.summaryTotal, view.summaryCurrency]).toEqual([[], '—', '—', 0, 'EUR'])
		expect(trail().events).toEqual([])
	})

	it('loads the ledger for the invoice and reports a failure', async () => {
		const view = trail()
		axios.get.mockResolvedValueOnce({ data: ledger })
		await view.loadLedger()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/three-way-match/audit-trail', { params: { administrationId: 'adm-1', invoiceId: 'inv-1' } })
		expect([view.ledger, view.loading]).toEqual([ledger, false])

		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		await view.loadLedger()
		expect(view.error).toBe('Forbidden')
		axios.get.mockRejectedValueOnce(new Error('x'))
		await view.loadLedger()
		expect(view.error).toBe('Failed to load audit trail')
		axios.get.mockResolvedValueOnce({ data: '' })
		await view.loadLedger()
		expect(view.ledger).toBe(null)
	})

	it('exports the package for the invoice and reports a failure', async () => {
		const view = trail()
		axios.post.mockResolvedValueOnce({ data: { packageId: 'pkg-1' } })
		await view.exportPackage()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/three-way-match/audit-trail/export', { administrationId: 'adm-1', invoiceId: 'inv-1' })
		expect([view.exportEnvelope, view.exporting]).toEqual([{ packageId: 'pkg-1' }, false])

		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Locked' } } })
		await view.exportPackage()
		expect(view.exportError).toBe('Locked')
		axios.post.mockRejectedValueOnce(new Error('x'))
		await view.exportPackage()
		expect(view.exportError).toBe('Failed to export audit package')
	})

	it('classifies events, labels unknown ones and formats values', () => {
		const view = trail()
		expect(view.eventClass({ event: 'grn_received' })['audit-trail__event--grn']).toBe(true)
		expect(view.eventClass({ event: 'invoice_received' })['audit-trail__event--invoice']).toBe(true)
		expect(view.eventClass({ event: 'match_evaluated' })['audit-trail__event--match-evaluated']).toBe(true)
		expect(Object.values(view.eventClass(null)).some(Boolean)).toBe(false)
		expect([view.eventLabel('grn_received'), view.eventLabel(''), view.eventLabel('odd')]).toEqual(['Goods received', 'Event', 'odd'])
		expect([view.formatTimestamp(''), view.formatTimestamp('soon')]).toEqual(['—', 'soon'])
		expect([view.formatDetailValue(undefined), view.formatDetailValue(false), view.formatDetailValue({ a: 1 }), view.formatDetailValue(5)]).toEqual(['—', 'false', '{"a":1}', '5'])
		expect([view.hasDetails(null), view.hasDetails('x'), view.hasDetails({}), view.hasDetails({ a: 1 })]).toEqual([false, false, false, true])
		expect(view.formatMoney(undefined)).toBe('0,00')
	})
})
