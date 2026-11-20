/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Contract test for the Checks and Reconciliation tabs of the
 * country-by-country report and the minimum tax return
 * (tax-cbcr-and-pillar2-returns, tasks 6.2 and 6.3, REQ-CBC-011/REQ-CBC-012).
 *
 * The tabs are manifest configuration over the library's record-list widget:
 * the Checks tabs read the CbcrController checks endpoints, the
 * Reconciliation tab reads the report's own reconciliationItems. This test
 * asserts that each endpoint a tab reads is declared in appinfo/routes.php,
 * and that the rows the tabs read are the ones the controller and the
 * schema hold, so a renamed route or field cannot leave an empty tab.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.d/tax-cbcr-and-pillar2-returns.json'), 'utf8'))
const routes = fs.readFileSync(path.join(root, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(path.join(root, 'lib/Controller/CbcrController.php'), 'utf8')

/**
 * The sidebar tab of a detail page.
 *
 * @param {string} pageId The page id.
 * @param {string} tabId The tab id.
 * @return {object|undefined} The tab.
 */
function tab(pageId, tabId) {
	const page = fragment.pages.find((entry) => entry.id === pageId)
	return (page?.config?.sidebarProps?.tabs ?? []).find((entry) => entry.id === tabId)
}

/**
 * Whether routes.php declares a GET on the url a widget reads.
 *
 * @param {string} url The widget url, `/apps/shillinq` prefixed, `@objectId` for the id.
 * @return {boolean}
 */
function declared(url) {
	const pattern = url.replace('/apps/shillinq', '').replace('@objectId', '{returnId}')
	return routes.includes(`'url' => '${pattern}', 'verb' => 'GET'`)
}

describe('Checks tabs of the CbC report and the minimum tax return', () => {
	it.each([
		['CbcrReturnDetail', '/apps/shillinq/api/cbcr/returns/@objectId/checks'],
		['GlobeInformationReturnDetail', '/apps/shillinq/api/cbcr/minimum-tax-returns/@objectId/checks'],
	])('%s reads its checks from a declared route', (pageId, url) => {
		const checks = tab(pageId, 'checks')
		expect(checks).toBeDefined()

		const widget = checks.widgets[0]
		expect(widget.type).toBe('record-list')
		expect(widget.content.endpointSource).toEqual({ url, responsePath: 'checks' })
		expect(declared(url)).toBe(true)
	})

	it('reads the fields the controller answers each check with', () => {
		// checksResponse() answers {checks: [{id, severity, passed, source, statement}], blocking}.
		expect(controller).toContain("['checks' => $results")
		const content = tab('CbcrReturnDetail', 'checks').widgets[0].content
		expect(content.title).toBe('{statement}')

		const conditions = content.pill.cases.map((entry) => entry.when)
		expect(conditions).toEqual([
			{ field: 'passed', op: 'eq', value: true },
			{ field: 'severity', op: 'eq', value: 'mandatory' },
		])
	})

	it('labels a passed check before a blocking one, so a passed blocking check reads Passed', () => {
		const labels = tab('GlobeInformationReturnDetail', 'checks').widgets[0].content.pill.cases.map((entry) => entry.label)
		expect(labels).toEqual(['Passed', 'Blocking'])
	})
})

describe('Reconciliation tab of the CbC report', () => {
	it('lists the reconciliation items the report holds', () => {
		const reconciliation = tab('CbcrReturnDetail', 'reconciliation')
		expect(reconciliation).toBeDefined()

		const content = reconciliation.widgets[0].content
		expect(content.objectRows).toEqual({ path: 'reconciliationItems' })
		expect(content.title).toBe('{description}')
		expect(content.meta.parts).toEqual(['{category}', '{amount|currency}'])
	})

	it('orders the tabs Reconciliation, Checks, Files, Audit trail', () => {
		const page = fragment.pages.find((entry) => entry.id === 'CbcrReturnDetail')
		expect(page.config.sidebarProps.tabs.map((entry) => entry.id)).toEqual(['reconciliation', 'checks', 'files', 'audit'])
	})
})
