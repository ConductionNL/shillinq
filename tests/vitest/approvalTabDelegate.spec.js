/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Approval sidebar tabs of the purchase order and supplier invoice detail
 * pages offer "Hand to a colleague": the shared `tasks` widget of
 * @conduction/nextcloud-vue shows the delegate verb (with a mandate, and
 * "on behalf of" on a delegated step) only when `content.delegate` is true.
 *
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-2.1
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const pages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8')).pages

/**
 * The `tasks` widgets on a detail page's Approval tab.
 *
 * @param {string} id The page id.
 * @return {Array<object>} The widgets.
 */
function approvalTaskWidgets(id) {
	const page = pages.find((candidate) => candidate.id === id)
	const tabs = page?.config?.sidebarProps?.tabs ?? []
	const approval = tabs.find((tab) => tab.id === 'approval')
	return (approval?.widgets ?? []).filter((widget) => widget.type === 'tasks')
}

describe('purchasing-approval-delegation: Hand to a colleague on the Approval tabs', () => {
	it.each(['PurchaseOrderDetail', 'SupplierInvoiceDetail'])('%s offers the delegate verb on its approval steps', (id) => {
		const widgets = approvalTaskWidgets(id)
		expect(widgets).toHaveLength(1)
		expect(widgets[0].content.delegate).toBe(true)
		expect(widgets[0].content.objectUuid).toBe('@objectId')
	})

	it('leaves the delegate verb off task widgets outside the approval tabs', () => {
		const others = pages.flatMap((page) => (page.config?.sidebarProps?.tabs ?? [])
			.filter((tab) => tab.id !== 'approval')
			.flatMap((tab) => tab.widgets ?? [])
			.filter((widget) => widget.type === 'tasks'))
		for (const widget of others) {
			expect(widget.content?.delegate).not.toBe(true)
		}
	})
})
