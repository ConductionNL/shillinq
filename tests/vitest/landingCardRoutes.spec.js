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

const landingPageIds = ['BookkeepingOverview', 'SalesOverview', 'PurchasingOverview', 'BankingCashflowOverview', 'TaxesOverview']
function landingEntries (id) {
  return pages
	.find((p) => p.id === id)
	.widgets.filter((w) => w.widgetKey === 'nav-card-grid')
	.flatMap((w) => w.props.entries)
}

describe('landing card routes', () => {
	it.each(landingPageIds)('%s is a dashboard of card grids that link only to page ids', (id) => {
		expect(pages.find((p) => p.id === id).type).toBe('dashboard')
		const routes = landingEntries(id).map((e) => e.route)
		expect(routes.length).toBeGreaterThan(0)
		expect(routes.filter((r) => !pageIds.has(r))).toEqual([])
	})

	it('keeps the preset filters as card queries', () => {
		expect(landingEntries('BookkeepingOverview').find((e) => e.id === 'CostCenters').query).toEqual({ dimensionType: 'cost-center' })
		expect(landingEntries('PurchasingOverview').find((e) => e.id === 'MijnContracten').query).toEqual({ source: 'tenderned' })
	})

	it('has no duplicate card on one landing page', () => {
		for (const id of landingPageIds) {
			const targets = landingEntries(id).map((e) => e.route + JSON.stringify(e.query || {}))
			expect(new Set(targets).size, id).toBe(targets.length)
		}
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
