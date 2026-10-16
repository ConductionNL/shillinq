/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Board ShDebiteurenbeheer: Receivables management. The page keeps its id and
 * route, the five age tiles split the open invoices without overlap, the board
 * tabs are a tabs widget with a count on the lists that have one, and the
 * list and the cards read fields the schemas carry.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const actions = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const page = manifest.pages.find((p) => p.id === 'ARAging')
const widgets = Object.fromEntries(page.config.widgets.map((w) => [w.id, w]))
const OPEN = ['issued', 'overdue', 'disputed']

describe('Receivables management board', () => {
	it('keeps its id and route, now as a dashboard', () => {
		expect(page.route).toBe('/bookkeeping/ar-aging')
		expect(page.type).toBe('dashboard')
	})

	it('splits the open invoices into five age buckets by due date', () => {
		const dues = page.config.kpiRow.map((id) => {
			const source = widgets[id].content.source
			expect(source.schema).toBe('ARInvoice')
			expect(source.metric).toBe('sum')
			expect(source.field).toBe('grossAmount')
			expect(source.filter.lifecycleState).toEqual(OPEN)
			expect(widgets[id].content.caption).toBe('{ratio}% of open')
			expect(widgets[id].content.captionSource).toEqual({
				kind: 'ratio',
				denominator: {
					metric: 'sum',
					field: 'grossAmount',
					filter: { lifecycleState: OPEN },
				},
			})
			return source.filter.dueDate
		})
		expect(dues).toEqual([
			{ gte: '@today' },
			{ gte: '@today-30d', lt: '@today' },
			{ gte: '@today-60d', lt: '@today-30d' },
			{ gte: '@today-90d', lt: '@today-60d' },
			{ lt: '@today-90d' },
		])
	})

	it('lists the open invoices with their reminder step', () => {
		const table = widgets['open-invoices'].content
		expect(table.source.filter.lifecycleState).toEqual(OPEN)
		expect(table.columns.map((c) => c.key)).toContain('dunning.currentStage')
		expect(table.rowRoute).toBe('ARInvoiceDetail')
		expect(table.columns[0]).toMatchObject({
			key: 'customerId',
			resolve: { register: 'shillinq', schema: 'CustomerMaster' },
		})
	})

	it('starts a round through a handler shillinq provides', () => {
		const start = page.config.headerActions.find(
			(a) => a.id === 'dunning-next-run',
		)
		expect(start.handler).toBe('openDunningNextRun')
		expect(actions).toContain('openDunningNextRun,')
	})

	it('draws the board tabs as a tabs widget over the three lists', () => {
		const tabs = widgets['receivables-tabs']
		expect(tabs.type).toBe('tabs')
		expect(tabs.content.tabs.map((t) => [t.widgetId, t.label])).toEqual([
			['open-invoices', 'Open balance'],
			['reminder-runs', 'Reminder runs'],
			['payment-request-table', 'Payment requests'],
		])
		for (const tab of tabs.content.tabs) {
			expect(widgets[tab.widgetId].type).toBe('object-table')
		}
		expect(tabs.content.tabs[0].countFrom).toBeUndefined()
		expect(tabs.content.tabs.slice(1).map((t) => t.countFrom)).toEqual([
			'widget',
			'widget',
		])
	})

	it('counts the reminder runs and the payment requests from their schemas', () => {
		expect(widgets['reminder-runs'].content.source).toMatchObject({
			register: 'shillinq',
			schema: 'DunningRun',
		})
		expect(widgets['reminder-runs'].content.rowRoute).toBe('DunningRunDetail')
		expect(widgets['payment-request-table'].content.source).toMatchObject({
			register: 'shillinq',
			schema: 'PaymentRequest',
		})
		expect(widgets['payment-request-table'].content.rowRoute).toBe(
			'PaymentRequestDetail',
		)
	})

	it('keeps the payment requests card below the tabs', () => {
		const placed = page.config.layout.map((l) => l.widgetId)
		expect(placed).toEqual(['receivables-tabs', 'payment-requests'])
		expect(widgets['payment-requests'].type).toBe('record-list')
	})

	it('places every grid widget once, inside 12 columns', () => {
		for (const l of page.config.layout) {
			expect(widgets[l.widgetId]).toBeDefined()
			expect(l.gridX + l.gridWidth).toBeLessThanOrEqual(12)
		}
	})
})
