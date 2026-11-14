/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What the goods receipt note page shows: loading and error lines, the
 * header with status, carrier, delivery note, receiver and the purchase
 * orders it is against, the line table, the photos, the three-way matches
 * and the action each status allows. Rendered from the component's real
 * template.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */

import { describe, expect, it, vi } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))

const { default: GoodsReceiptNoteDetail } = await import('../../src/components/goods-receipt-note/GoodsReceiptNoteDetail.vue')

const components = { NcButton: standIn('NcButton'), RouterLink: standIn('RouterLink') }

/**
 * Render the page for a state.
 *
 * @param {object} data Preset data.
 * @return {Promise<string>} The HTML.
 */
const render = (data) => renderTemplate(GoodsReceiptNoteDetail, { props: { id: 'grn-1' }, data: { loading: false, ...data }, components })

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * The labels of the buttons rendered.
 *
 * @param {string} html The HTML.
 * @return {Array<string>} The labels.
 */
const buttons = (html) => [...html.matchAll(/<cn-stub data-name="NcButton"[^>]*>([\s\S]*?)<\/cn-stub>/g)].map((m) => text(m[1]))

describe('GoodsReceiptNoteDetail template', () => {
	it('says it is loading, or why it could not load', async () => {
		expect(text(await render({ loading: true }))).toBe('Loading goods receipt note…')
		expect(text(await render({ error: 'Not found' }))).toBe('Not found')
		expect(text(await render({}))).toBe('')
	})

	it('shows a received note in full, with both actions', async () => {
		const html = await render({
			grn: {
				grnNumber: 'GRN-0042',
				statusCode: 'received',
				carrier: 'PostNL',
				deliveryNoteReference: 'DN-9',
				receivedAt: '2026-05-04T10:00:00Z',
				receivedBy: 'magazijn',
				poIds: ['po-1', 'po-2'],
				photos: ['f 1'],
			},
			purchaseOrders: { 'po-1': { poNumber: 'PO-2026-001' }, 'po-2': null },
			lines: [{ id: 'l1', poLineId: 'pol-1', quantityReceived: 10, quantityAccepted: 9.5, quantityRejected: 0.5, rejectionReason: 'Dented', batchReference: 'B-7' }, { id: 'l2', poLineId: 'pol-2' }],
			matches: [{ id: 'm1', matchReference: 'TWM-1' }, { id: 'm2' }],
		})
		const shown = text(html)

		expect(shown).toContain('GRN-0042')
		expect(html).toContain('grn-detail__pill--received')
		expect(shown).toContain('Carrier: PostNL')
		expect(shown).toContain('Delivery note: DN-9')
		expect(shown).toContain(`Received: ${new Date('2026-05-04T10:00:00Z').toLocaleString()} — magazijn`)
		expect(shown).toContain('Against: PO-2026-001 po-2')
		expect(html).toContain('data-to="{&quot;name&quot;:&quot;PurchaseOrderDetail&quot;,&quot;params&quot;:{&quot;id&quot;:&quot;po-1&quot;}}"')
		expect(shown).toContain('pol-1 10.000 9.500 0.500 Dented B-7')
		expect(shown).toContain('pol-2 0.000 0.000 0.000 — —')
		expect(shown).not.toContain('No lines yet.')
		expect(html).toContain('href="/index.php/apps/files/?fileid=f%201"')
		expect(shown).toContain('TWM-1 m2')
		expect(buttons(html)).toEqual(['Quality check passed', 'Accept goods'])
	})

	it('shows a bare quality-checked note with its empty sections and only Accept, and a failed transition', async () => {
		const html = await render({ grn: { grnNumber: 'GRN-7', statusCode: 'quality_checked' }, transitionError: 'Quality check failed' })
		const shown = text(html)

		expect(shown).toContain('Quality checked')
		expect(shown).toContain('Received: —')
		expect(shown).not.toContain('Carrier')
		expect(shown).not.toContain('Against')
		expect(shown).toContain('No lines yet.')
		expect(shown).toContain('No photos attached.')
		expect(shown).toContain('No matches yet — invoices will populate them.')
		expect(buttons(html)).toEqual(['Accept goods'])
		expect(shown).toContain('Quality check failed')
	})

	it('offers no action once accepted, and shows Working… while a transition runs', async () => {
		expect(buttons(await render({ grn: { statusCode: 'accepted' } }))).toEqual([])
		expect(text(await render({ grn: { statusCode: 'odd-status' } }))).toContain('odd-status')
		expect(buttons(await render({ grn: { statusCode: 'received' }, transitioning: true }))).toEqual(['Working…', 'Working…'])
	})
})
