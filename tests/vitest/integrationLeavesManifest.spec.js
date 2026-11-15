/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The integration leaves on shillinq's detail pages (integration-leaves-consume):
 * the files widget on the pages whose records hold a document, the talk tab on
 * the curated dossier pages, and the pages this change must leave alone.
 *
 * @spec openspec/changes/integration-leaves-consume/specs/consume-platform-abstractions/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const contracts = read('src/manifest.d/contract-lifecycle-management.json')
const page = (source, id) => source.pages.find((p) => p.id === id)

describe('files widget on pages whose records hold a document (REQ-CPA-005)', () => {
	const cases = [
		['BankConnectionDetail', 'BankConnection', 'Imported statements'],
		['ExpenseClaimDetail', 'ExpenseClaimEntry', 'Receipt evidence'],
		['BtwAangifteDetail', 'VatReturn', 'Filed return attachment'],
	]

	it.each(cases)('%s keeps its data and related body and adds the files leaf below it', (id, schema, title) => {
		const { config } = page(manifest, id)
		expect(config.widgets.map((w) => w.type)).toEqual(['data', 'related', 'integration'])
		expect(config.widgets[0].content).toEqual({ register: 'shillinq', schema, columns: 3 })
		const files = config.widgets[2]
		expect(files).toMatchObject({ integrationId: 'files', title })
		const placed = config.layout.map((item) => item.widgetId)
		expect(placed).toEqual(config.widgets.map((w) => w.id))
		const filesCell = config.layout[2]
		expect(filesCell.gridY).toBeGreaterThanOrEqual(config.layout[1].gridY + config.layout[1].gridHeight)
		expect(filesCell.gridWidth).toBe(12)
	})

	it('ReceiptDetail is overlaid by the ReceiptCapture component, so a manifest widget would not render there', () => {
		const overlay = read('src/manifest.d/receipt-extraction-consume.json').pages.find((p) => p.id === 'ReceiptDetail')
		expect(overlay.type).toBe('custom')
		expect(page(manifest, 'ReceiptDetail').config.widgets).toBeUndefined()
	})
})

describe('talk tab on curated dossier pages (REQ-CPA-008)', () => {
	it.each([
		['ExpenseClaimDetail', manifest],
		['ContractDetail', contracts],
	])('%s lists talk in its curated tabs, before the audit trail', (id, source) => {
		const tabs = page(source, id).config.sidebarProps.tabs
		const ids = tabs.map((tab) => tab.id)
		expect(ids).toContain('talk')
		expect(ids.indexOf('talk')).toBe(ids.indexOf('audit') - 1)
		const talk = tabs.find((tab) => tab.id === 'talk')
		expect(talk.widgets).toEqual([{ type: 'integration', integrationId: 'talk' }])
		expect(talk.order).toBeLessThan(tabs.find((tab) => tab.id === 'audit').order)
	})

	it('ARInvoiceDetail curates no tabs, so it gets talk from the registry and is not given a list', () => {
		expect(page(manifest, 'ARInvoiceDetail').config.sidebarProps?.tabs).toBeUndefined()
	})
})
