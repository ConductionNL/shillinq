/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The goods receipt note detail page is manifest config: the GRN fields,
 * its lines, the three-way matches that include it, and the two server
 * transitions as header actions.
 */

import { describe, expect, it } from 'vitest'

const manifest = (await import('../../src/manifest.d/bookkeeping-purchase-order-3way-04-goods-receipt-note.json')).default
const registry = (await import('node:fs')).readFileSync(new URL('../../src/registry.js', import.meta.url), 'utf8')
const page = manifest.pages.find((p) => p.id === 'GoodsReceiptNoteDetail')

describe('GoodsReceiptNoteDetail', () => {
	it('is a built-in detail page on GoodsReceiptNote, with no custom component left', () => {
		expect(page.type).toBe('detail')
		expect(page.config).toMatchObject({ register: 'shillinq', schema: 'GoodsReceiptNote' })
		expect(page.component).toBeUndefined()
		expect(registry).not.toContain('GoodsReceiptNoteDetail')
	})

	it('lists the lines of this receipt and the matches that include it', () => {
		const lists = page.config.widgets.filter((w) => w.type === 'object-list')
		expect(lists.map((w) => [w.content.schema, w.content.filter])).toEqual([
			['GoodsReceiptLine', { grnId: '@objectId' }],
			['ThreeWayMatch', { grnIds: '@objectId' }],
		])
		expect(lists[1].content.rowRoute).toBe('ThreeWayMatchDetail')
		const placed = page.config.layout.map((l) => l.widgetId).sort()
		expect(placed).toEqual(page.config.widgets.map((w) => w.id).sort())
	})

	it('runs quality check and accept on the server, each only in its states', () => {
		const [check, accept] = page.config.headerActions
		expect(check).toMatchObject({ type: 'api-call', method: 'POST', url: '/apps/shillinq/api/goods-receipt-notes/@objectId/quality-check', visibleWhen: { field: 'statusCode', op: 'eq', value: 'received' } })
		expect(accept).toMatchObject({ type: 'api-call', method: 'POST', url: '/apps/shillinq/api/goods-receipt-notes/@objectId/accept', visibleWhen: { field: 'statusCode', op: 'in', value: ['received', 'quality_checked'] } })
	})
})
