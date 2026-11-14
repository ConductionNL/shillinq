/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * iv3-report-takes-case-costs-by-case-type tasks 5 and 10: the Case costs
 * page shows the case costs per task field and per case type for a period,
 * with a CSV export, over the route SubjectCostReportController answers, and
 * every label it draws is in the Dutch catalogue.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const fragment = JSON.parse(read('src/manifest.d/iv3-report-takes-case-costs-by-case-type.json'))
const nl = JSON.parse(read('l10n/nl.json')).translations
const page = fragment.pages.find((p) => p.id === 'SubjectCosts')
const widget = (id) => page.config.widgets.find((w) => w.id === id)

describe('the case costs page', () => {
	it('is in the Government menu and only for public sector administrations', () => {
		const government = fragment.menu.find((m) => m.id === 'Overheid')
		expect(government.children.map((c) => c.route)).toContain('SubjectCosts')
		expect(page.route).toBe('/overheid/zaakkosten')
		expect(page.config.visibility.in).toEqual(['municipality', 'provincie', 'waterschap'])
	})

	it('reads the period the controller types and accepts year, quarter and month', () => {
		const period = widget('sc-period').content
		expect(period.writes).toBe('@workspace.costPeriod')
		const pattern = new RegExp(period.pattern)
		for (const ok of ['2026', '2026-Q3', '2026-09']) {
			expect(pattern.test(ok)).toBe(true)
		}
		for (const bad of ['2026-Q5', '2026-13', '26']) {
			expect(pattern.test(bad)).toBe(false)
		}
	})

	it('draws both tables over the subject cost route, per task field and per type, with an export', () => {
		const field = widget('sc-field').content
		const type = widget('sc-type').content
		expect(field.endpointSource).toEqual({ url: '/apps/shillinq/api/reporting/subject-costs', params: { period: '@workspace.costPeriod' }, responsePath: 'rows' })
		expect(type.endpointSource.params).toEqual({ period: '@workspace.costPeriod', grouping: 'type' })
		expect(field.exportCsv && type.exportCsv).toBe(true)
		expect(field.columns.map((c) => c.key)).toEqual(['label', 'objects', 'hours', 'cost', 'incomplete', 'types'])
		expect(type.columns.map((c) => c.key)).toEqual(['label', 'objects', 'hours', 'cost', 'incomplete'])
		expect(read('appinfo/routes.php')).toMatch(/'subjectCostReport#rows', 'url' => '\/api\/reporting\/subject-costs', 'verb' => 'GET'/)
	})

	it('has every label in the Dutch catalogue', () => {
		const labels = [page.title, fragment.menu[0].children[0].label]
		for (const w of page.config.widgets) {
			labels.push(w.title, w.content.label, w.content.submitLabel, w.content.emptyText)
			for (const column of (w.content.columns || [])) {
				labels.push(column.label)
			}
		}
		for (const label of labels.filter(Boolean)) {
			expect(nl[label], label).toBeTruthy()
		}
		expect(nl['Case costs per case type']).toBe('Zaakkosten per zaaktype')
	})
})
