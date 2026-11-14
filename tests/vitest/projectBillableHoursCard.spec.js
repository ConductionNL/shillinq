/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Billable hours card on ProjectDetail (sales-billable-rate-per-hour
 * REQ-BRPH-003, REQ-BRPH-004): it reads the chosen month from the server,
 * lists each hour with its rate, tier, value and billed state, totals the
 * unbilled value, and says so when no hours source is connected. The manifest
 * places it after the hours budget and shows each assignment's billing role
 * and rate. Only HTTP is replaced.
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-4.2
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (all, key) => params[key] ?? all),
}))

const { default: Card } = await import('../../src/components/project/ProjectBillableHoursCard.vue')
const { euro } = await import('../../src/utils/billableRate.js')

const OCTOBER = {
	available: true,
	reason: '',
	month: '2026-10',
	rows: [
		{ id: 'h-alice', person: 'user-alice', date: '2026-10-14', hours: 6, status: 'rated', rate: 130, tier: 'user', value: 780, billed: true, invoiceNumber: '2026-T-0031' },
		{ id: 'h-bram', person: 'user-bram', date: '2026-10-14', hours: 4.5, status: 'rated', rate: 120, tier: 'role', value: 540, billed: false, invoiceNumber: null },
		{ id: 'h-chris', person: 'user-chris', date: '2026-10-15', hours: 2, status: 'rated', rate: 95, tier: 'client', value: 190, billed: false, invoiceNumber: null },
		{ id: 'h-dana', person: 'user-dana', date: '2026-10-14', hours: 1, status: 'no-rate', rate: null, tier: null, value: null, message: 'user-dana is not assigned to this project', billed: false, invoiceNumber: null },
	],
	totals: { hours: 13.5, unbilledHours: 6.5, unbilledValue: 730, unratedHours: 1 },
}

/**
 * A stand-in card instance with its methods bound.
 *
 * @param {object} [fields] Data to set.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const self = { ...Card.data(), objectId: 'proj-1', ...fields }
	for (const [name, fn] of Object.entries(Card.methods)) {
		self[name] = typeof fn === 'function' ? fn.bind(self) : fn
	}
	return self
}

/**
 * Render the card with the given state.
 *
 * @param {object} state Data to start from.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	const component = { ...Card, watch: {}, data: () => ({ ...Card.data(), ...state }) }
	return renderToString(createSSRApp(component, { objectId: 'proj-1' }))
}

beforeEach(() => {
	http.get.mockReset()
})

describe('the Billable hours card', () => {
	it('reads the chosen month of the project', async () => {
		http.get.mockResolvedValue({ data: OCTOBER })
		const card = instance({ month: '2026-10' })

		await card.load()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/projects/proj-1/billable-hours', { params: { month: '2026-10' } })
		expect([card.available, card.rows.length, card.totals.unbilledValue, card.loading, card.error]).toEqual([true, 4, 730, false, ''])
	})

	it('lists each hour with its rate, tier, value and billed state, and the totals', async () => {
		const html = await render({ month: '2026-10', available: true, rows: OCTOBER.rows, totals: OCTOBER.totals })

		expect(html.match(/data-testid="pbh-row"/g)).toHaveLength(4)
		expect(html).toContain('Billed on 2026-T-0031')
		expect(html).toContain('Not billed')
		expect(html).toContain('Personal rate')
		expect(html).toContain('Role rate')
		expect(html).toContain('Client rate')
		expect(html).toContain(euro(780))
		expect(html).toContain('No rate')
		expect(html).toContain('user-dana is not assigned to this project')
		expect(html).toContain(euro(730))
		expect(html).toContain('Hours without a rate')
	})

	it('says the hours are not available without an hours source, not zero', async () => {
		http.get.mockResolvedValue({ data: { available: false, reason: 'no-hours-source', rows: [], totals: {} } })
		const card = instance()
		await card.load()
		const html = await render({ available: card.available, rows: card.rows })

		expect(html).toContain('Hours are not available because the hours app is not connected.')
		expect(html).not.toContain('pbh-totals')
	})

	it('shows an error when the hours cannot be read', async () => {
		http.get.mockRejectedValue(new Error('500'))
		const card = instance()

		await card.load()

		expect([card.error, card.loading]).toEqual(['The billable hours could not be loaded.', false])
	})

	it('reads nothing without a project', async () => {
		await instance({ objectId: '' }).load()
		expect(http.get).not.toHaveBeenCalled()
	})
})

describe('the project page', () => {
	const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../../src/manifest.json'), 'utf8'))
	const page = manifest.pages.find((p) => p.id === 'ProjectDetail')

	it('places the card after the hours budget, through its slot', () => {
		const ids = page.config.widgets.map((w) => w.id)
		expect(ids.indexOf('project-billable-hours')).toBe(ids.indexOf('project-hours') + 1)
		expect(page.config.widgets.find((w) => w.id === 'project-billable-hours').type).toBe('custom')
		expect(page.slots['widget-project-billable-hours']).toBe('ProjectBillableHoursCard')
		const cell = page.config.layout.find((c) => c.widgetId === 'project-billable-hours')
		expect([cell.gridY, cell.gridWidth]).toEqual([5, 12])
		const registry = fs.readFileSync(path.join(__dirname, '../../src/registry.js'), 'utf8')
		expect(registry).toContain('ProjectBillableHoursCard: {')
	})

	it('shows each assignment\'s billing role and rate in Hours per assignment', () => {
		const list = page.config.widgets.find((w) => w.id === 'project-assignments')
		const keys = list.content.columns.map((c) => c.key)
		expect(keys).toContain('billingRoleId')
		expect(keys).toContain('recognisedRate')
		expect(list.content.columns.find((c) => c.key === 'billingRoleId').label).toBe('Billing role')
	})

	it('keeps the layout free of overlapping cells', () => {
		const cells = page.config.layout
		for (const a of cells) {
			for (const b of cells) {
				if (a === b) continue
				const overlap = a.gridX < b.gridX + b.gridWidth && b.gridX < a.gridX + a.gridWidth
					&& a.gridY < b.gridY + b.gridHeight && b.gridY < a.gridY + a.gridHeight
				expect(overlap, `${a.widgetId} overlaps ${b.widgetId}`).toBe(false)
			}
		}
	})
})
