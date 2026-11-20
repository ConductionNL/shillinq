/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * SupplierInvoiceDetail rendered server-side with @nextcloud/axios mocked:
 * the invoice and its 3-way matches loaded on creation, the load failure,
 * the seller VAT check wording, the OCR and UBL provenance blocks, the
 * confidence tiers, the money / date / status formatting, and the
 * last-invoice dialog that reloads the invoice when it closes.
 *
 * @spec openspec/specs/bookkeeping-accounts-payable-core/spec.md
 */

import axios from '@nextcloud/axios'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../src/modals/LastInvoiceModal.vue', () => ({ default: { name: 'LastInvoiceModal' } }))
vi.mock('../../src/modals/SpreadOverPeriodsModal.vue', () => ({ default: { name: 'SpreadOverPeriodsModal' } }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		ssrRender(ctx, push, parent) {
			push('<button data-stub="NcButton">')
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('</button>')
		},
	},
}))

const { default: SupplierInvoiceDetail } = await import('../../src/components/supplier-invoice/SupplierInvoiceDetail.vue')

const t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m))

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'

/**
 * Render the detail for an invoice id, with the reads axios answers.
 *
 * @param {object} [state] Data to lay over the component's own after creation.
 * @return {Promise<{html: string, vm: object}>}
 */
async function render(state = null) {
	let vm = null
	const app = createSSRApp(
		{
			...SupplierInvoiceDetail,
			mixins: [{ created() { vm = this } }],
		},
		{ id: 'si-1' },
	)
	app.config.globalProperties.t = t
	app.component('RouterLink', {
		props: ['to'],
		ssrRender(ctx, push, parent) {
			push(`<a data-route="${ctx.to.name}:${ctx.to.params.id}">`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('</a>')
		},
	})
	app.config.warnHandler = () => {}
	let html = await renderToString(app)
	if (state !== null) {
		// Let the reads the creation started settle before rendering again.
		await new Promise((resolve) => setTimeout(resolve, 0))
		Object.assign(vm.$data, state)
		html = await renderToString(createSSRApp({ ...SupplierInvoiceDetail, data: () => ({ ...vm.$data }), created() {} }, { id: 'si-1' }).use({
			install(next) {
				next.config.globalProperties.t = t
				next.config.warnHandler = () => {}
				next.component('RouterLink', app.component('RouterLink'))
			},
		}))
	}
	return { html, vm }
}

/**
 * A bare context for the computed values and methods, over this invoice.
 *
 * @param {object|null} invoice The invoice.
 * @return {object} The context.
 */
function context(invoice) {
	const ctx = { invoice, t, id: 'si-1' }
	for (const [name, method] of Object.entries(SupplierInvoiceDetail.methods)) {
		ctx[name] = method.bind(ctx)
	}
	for (const [name, getter] of Object.entries(SupplierInvoiceDetail.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx) })
	}
	return ctx
}

const invoice = {
	invoiceNumber: 'F-100',
	administrationId: 7,
	statusCode: 'approved',
	currency: 'USD',
	invoiceDate: '2026-03-01',
	dueDate: '2026-03-31',
	totalInclVat: 12100,
	totalExclVat: 10000,
	totalVat: 2100,
	sellerVatId: 'NL123',
	sellerVatIdValidationStatus: 'invalid',
	sourceFormat: 'pdf',
	ocrConfidenceScore: 0.42,
	ublSourceUri: 'ubl://x',
	matchedPoIds: ['po-1'],
	isLastInvoice: false,
	lines: [{ lineNumber: 1, description: 'Paper', quantity: 2, unitPrice: 500, vatRate: 0.21, lineTotal: 1000 }],
}

beforeEach(() => {
	axios.get.mockReset()
	spawnDialog.mockReset()
})

describe('SupplierInvoiceDetail loading', () => {
	it('loads the invoice and its matches on creation and renders them', async () => {
		axios.get.mockImplementation((url) => Promise.resolve(
			url.endsWith('/ThreeWayMatch')
				? { data: { results: [{ id: 'm1', matchReference: 'TWM-1', matchStatus: 'matched' }, { id: 'm2' }] } }
				: { data: invoice },
		))

		const { vm, html } = await render({})

		expect(axios.get).toHaveBeenCalledWith(`${OBJECTS}/SupplierInvoice/si-1`)
		expect(axios.get).toHaveBeenCalledWith(`${OBJECTS}/ThreeWayMatch`, { params: { filter: { invoiceId: 'si-1' } } })
		expect(vm.loading).toBe(false)
		expect(vm.matches).toHaveLength(2)
		expect(html).toContain('F-100')
		expect(html).toContain('USD 121.00')
		expect(html).toContain('USD 100.00')
		expect(html).toContain('not valid according to VIES')
		expect(html).toContain('data-testid="si-detail-ocr"')
		expect(html).toContain('data-testid="si-detail-ocr-low"')
		expect(html).toContain('data-testid="si-detail-provenance"')
		expect(html).toContain('data-testid="si-detail-line-1"')
		expect(html).toContain('data-route="ThreeWayMatchDetail:m1"')
		expect(html).toContain('TWM-1')
		expect(html).toContain('Mark as last invoice')
	})

	it('renders the empty lines and matches, and the closed commitment', async () => {
		axios.get.mockImplementation(() => new Promise(() => {}))

		const { html } = await render({
			loading: false,
			matches: [],
			invoice: { ...invoice, lines: [], isLastInvoice: true, sourceFormat: 'other', ocrConfidenceScore: null, ublSourceUri: '', totalExclVat: null },
		})

		expect(html).toContain('No match yet — awaiting the matching engine.')
		expect(html).toContain('Marked as the last invoice of its order. The commitment is closed.')
		expect(html).not.toContain('data-testid="si-detail-ocr"')
		expect(html).not.toContain('data-testid="si-detail-provenance"')
		expect(html).not.toContain('Mark as last invoice')
	})

	it('shows the loading and error states', async () => {
		axios.get.mockImplementation(() => new Promise(() => {}))

		const loading = await render()
		expect(loading.html).toContain('data-testid="si-detail-loading"')

		const failed = await render({ loading: false, error: 'Gone' })
		expect(failed.html).toContain('data-testid="si-detail-error"')
		expect(failed.html).toContain('Gone')
	})

	it('names the server error of a failed load, or a default', async () => {
		const ctx = { ...context(null), loading: true, error: '', matches: [] }
		ctx.loadInvoice = SupplierInvoiceDetail.methods.loadInvoice.bind(ctx)
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'No access' } } })
		axios.get.mockRejectedValueOnce(new Error('network'))

		await ctx.loadInvoice()
		expect(ctx.error).toBe('No access')
		expect(ctx.loading).toBe(false)

		await ctx.loadInvoice()
		expect(ctx.error).toBe('Failed to load supplier invoice')
	})

	it('keeps no invoice for an empty answer and reads no matches for it', async () => {
		const ctx = { ...context(null), loading: true, error: '', matches: [] }
		ctx.loadInvoice = SupplierInvoiceDetail.methods.loadInvoice.bind(ctx)
		axios.get.mockResolvedValueOnce({ data: '' })

		await ctx.loadInvoice()

		expect(ctx.invoice).toBeNull()
		expect(axios.get).toHaveBeenCalledTimes(1)
	})

	it('reads a bare list of matches, and none when the read fails', async () => {
		const ctx = { ...context(invoice), matches: ['stale'] }
		ctx.loadMatches = SupplierInvoiceDetail.methods.loadMatches.bind(ctx)
		axios.get.mockResolvedValueOnce({ data: [{ id: 'm3' }] })
		axios.get.mockRejectedValueOnce(new Error('down'))

		await ctx.loadMatches()
		expect(ctx.matches).toEqual([{ id: 'm3' }])

		await ctx.loadMatches()
		expect(ctx.matches).toEqual([])
	})
})

describe('SupplierInvoiceDetail last invoice', () => {
	it('opens the dialog for this invoice and reloads only when it closed with a change', async () => {
		const ctx = context({ administrationId: 7 })
		ctx.loadInvoice = vi.fn()
		spawnDialog.mockResolvedValueOnce(true).mockResolvedValueOnce(false)

		await ctx.openLastInvoice()
		expect(spawnDialog).toHaveBeenCalledWith({ name: 'LastInvoiceModal' }, { invoiceId: 'si-1', administrationId: '7' })
		expect(ctx.loadInvoice).toHaveBeenCalledTimes(1)

		ctx.invoice = {}
		await ctx.openLastInvoice()
		expect(spawnDialog.mock.calls[1][1].administrationId).toBe('')
		expect(ctx.loadInvoice).toHaveBeenCalledTimes(1)
	})
})

describe('SupplierInvoiceDetail wording', () => {
	it('words the seller VAT check per status', () => {
		const at = '2026-02-03T10:00:00Z'
		const day = new Date(at).toLocaleDateString()

		expect(context({ sellerVatIdValidationStatus: 'valid', sellerVatIdValidatedAt: at }).sellerVatCheck).toBe(`valid, checked on ${day}`)
		expect(context({ sellerVatIdValidationStatus: 'vies_outage', sellerVatIdValidatedAt: at }).sellerVatCheck).toBe(`VIES not reachable, last valid on ${day}`)
		expect(context({ sellerVatIdValidationStatus: 'vies_outage' }).sellerVatCheck).toBe('VIES not reachable')
		expect(context({ sellerVatIdValidationStatus: 'unchecked' }).sellerVatCheck).toBe('')
		expect(context(null).sellerVatCheck).toBe('')
	})

	it('tells a PDF from a UBL ingestion', () => {
		expect(context(null).isPdfIngestion).toBe(false)
		expect(context({ sourceFormat: 'pdf' }).isPdfIngestion).toBe(true)
		expect(context({ ocrConfidenceScore: 0 }).isPdfIngestion).toBe(true)
		expect(context({ ocrConfidenceScore: null }).isPdfIngestion).toBe(false)

		expect(context(null).isUblIngestion).toBe(false)
		expect(context({ sourceFormat: 'ubl' }).isUblIngestion).toBe(true)
		expect(context({ peppolReceivedAt: '2026-01-01' }).isUblIngestion).toBe(true)
		expect(context({ sourceFormat: 'pdf' }).isUblIngestion).toBe(false)
	})

	it('grades the OCR confidence', () => {
		const high = context({ ocrConfidenceScore: 0.9 })
		expect([high.ocrPercent, high.ocrConfidenceTier, high.ocrConfidenceTierLabel, high.isLowConfidence]).toEqual(['90%', 'high', 'High', false])

		const medium = context({ ocrConfidenceScore: '0.7' })
		expect([medium.ocrConfidenceTier, medium.ocrConfidenceTierLabel]).toEqual(['medium', 'Medium'])

		const low = context({ ocrConfidenceScore: 0.699 })
		expect([low.ocrPercent, low.ocrConfidenceTierLabel, low.isLowConfidence]).toEqual(['70%', 'Low', true])

		expect(context(null).ocrPercent).toBe('0%')
	})

	it('labels the status, money, rates and dates', () => {
		const ctx = context({})

		expect(ctx.statusLabel('exception')).toBe('Exception')
		expect(ctx.statusLabel('paid')).toBe('Paid')
		expect(ctx.statusLabel('custom')).toBe('custom')
		expect(ctx.statusLabel('')).toBe('—')
		expect(ctx.formatMoney(null)).toBe('—')
		expect(ctx.formatMoney(undefined)).toBe('—')
		expect(ctx.formatMoney(12345)).toBe('EUR 123.45')
		expect(ctx.formatPct(0.09)).toBe('9.00%')
		expect(ctx.formatPct(null)).toBe('0.00%')
		expect(ctx.formatDate('')).toBe('—')
		expect(ctx.formatDate('2026-05-06')).toBe(new Date('2026-05-06').toLocaleDateString())
		expect(ctx.formatTimestamp(null)).toBe('—')
		expect(ctx.formatTimestamp('2026-05-06T08:09:00Z')).toBe(new Date('2026-05-06T08:09:00Z').toLocaleString())
	})
})
