/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Every named route a page links to must be a registered route. vue-router
 * throws MATCHER_NOT_FOUND on a name it does not know, so a landing card that
 * names a menu group (a menu id, not a page id) breaks the whole page. Page
 * ids must also be unique per path: a later fragment page with the same id
 * replaces the earlier one, leaving the earlier path unroutable.
 *
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8')

const manifestFiles = [
	'src/manifest.json',
	...fs
		.readdirSync(path.join(ROOT, 'src/manifest.d'))
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => 'src/manifest.d/' + f),
]
const pages = manifestFiles.flatMap((f) =>
	(JSON.parse(read(f)).pages || []).map((p) => ({ ...p, file: f })),
)
const pageIds = new Set(pages.map((p) => p.id))

const landingComponents = [
	'src/components/bookkeeping/BookkeepingOverview.vue',
	'src/components/sales/SalesOverview.vue',
	'src/components/purchasing/PurchasingOverview.vue',
	'src/components/banking-cashflow/BankingCashflowOverview.vue',
	'src/components/taxes/TaxesOverview.vue',
]

describe('landing card routes', () => {
	it.each(landingComponents)('%s links only to page ids', (file) => {
		const routes = [...read(file).matchAll(/route: '([^']+)'/g)].map((m) => m[1])
		expect(routes.length).toBeGreaterThan(0)
		expect(routes.filter((r) => !pageIds.has(r))).toEqual([])
	})

	it('the Reports page cards link only to page ids', () => {
		const reports = pages.find((p) => p.id === 'Reports')
		const routes = reports.config.cards.map((c) => c.route)
		expect(routes.filter((r) => !pageIds.has(r))).toEqual([])
	})
})

describe('page ids', () => {
	it('never declare one id on two different paths', () => {
		const pathsById = {}
		for (const p of pages) {
			;(pathsById[p.id] ||= new Set()).add(p.route)
		}
		const clashes = Object.entries(pathsById)
			.filter(([, paths]) => paths.size > 1)
			.map(([id]) => id)
		expect(clashes).toEqual([])
	})
})

describe('WBSO bookkeeping views', () => {
	it.each([
		'src/views/bookkeeping/ChartOfAccountsView.vue',
		'src/views/bookkeeping/TransactionsView.vue',
		'src/views/bookkeeping/DocumentsView.vue',
	])('%s calls its plain app route, not an OCS url', (file) => {
		const source = read(file)
		expect(source).not.toContain('generateOcsUrl')
		expect(source).toMatch(/generateUrl\('\/apps\/shillinq\/api\/v1\//)
	})
})

describe('report view cards', () => {
	it('open a registered page', async () => {
		const { reportViews } = await import('../../src/components/reporting/reportViews.js')
		const missing = reportViews
			.map((view) => view.route || view.id)
			.filter((name) => !pageIds.has(name))
		expect(missing).toEqual([])
	})
})
