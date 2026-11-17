/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-vat-return-from-books 3.3: the Checks tab on a BTW return reaches the
 * server. The manifest tab, the registry widget, the helper's URL,
 * appinfo/routes.php and the controller are held to each other, and every
 * check the server runs has a name in the tab.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-3.3
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/bookkeeping-vat-btw-filing.json'),
		'utf8',
	),
)
const registryJs = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(
	path.join(ROOT, 'lib/Controller/VATReturnController.php'),
	'utf8',
)
const provider = fs.readFileSync(
	path.join(ROOT, 'lib/Standards/Checks/VatReturnChecks.php'),
	'utf8',
)

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))
const axiosMock = { get: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

describe('Checks tab on a BTW return', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('is a sidebar tab on the return page that renders a registered widget', () => {
		const detail = fragment.pages.find((p) => p.id === 'VATReturnDetail')
		const tab = detail.config.sidebarProps.tabs.find((x) => x.id === 'checks')
		expect(tab).toBeDefined()
		expect(tab.label).toBe('Checks')
		expect(tab.widgets[0].type).toBe('VatReturnChecksPanel')
		const entry = registryJs.slice(
			registryJs.indexOf('\tVatReturnChecksPanel: {'),
		)
		expect(entry.slice(0, entry.indexOf('\n\t},'))).toContain("kind: 'widget'")
	})

	it('asks the route the controller serves', async () => {
		expect(routes).toContain(
			"'vATReturn#checks', 'url' => '/api/vat-returns/{returnId}/checks', 'verb' => 'GET'",
		)
		expect(controller).toContain(
			'public function checks(string $returnId): JSONResponse',
		)
		const { fetchVatReturnChecks } =
			await import('../../src/utils/vatReturnChecks.js')
		axiosMock.get.mockResolvedValue({
			data: { data: [{ id: 'x', passed: true }] },
		})
		const checks = await fetchVatReturnChecks('ret 1')
		expect(axiosMock.get.mock.calls[0][0]).toBe(
			'/index.php/apps/shillinq/api/vat-returns/ret%201/checks',
		)
		expect(checks).toHaveLength(1)
	})

	it('names every check the server runs and writes out its state', async () => {
		const { checkNames, checkRows } =
			await import('../../src/utils/vatReturnChecks.js')
		const ids = [
			...provider.matchAll(/const [A-Z_]+ = '(nl-vat-return-[a-z-]+)'/g),
		].map((m) => m[1])
		expect(ids).toHaveLength(6)
		expect(Object.keys(checkNames()).sort()).toEqual([...ids].sort())

		const rows = checkRows([
			{
				id: 'nl-vat-return-line-box',
				passed: false,
				blocking: true,
				offenders: ['MEM-77'],
			},
			{
				id: 'nl-vat-return-no-drafts',
				passed: false,
				blocking: false,
				offenders: ['Sales invoice CONCEPT-1'],
			},
			{
				id: 'nl-vat-return-previous-filed',
				passed: true,
				blocking: true,
				offenders: [],
			},
		])
		expect(rows.map((r) => r.state)).toEqual(['blocking', 'warning', 'passed'])
		expect(rows[0].name).toBe('Every VAT line has a box')
		expect(rows[0].stateLabel).toBe('Failed, blocks submitting')
		expect(rows[0].offenders).toEqual(['MEM-77'])
		expect(rows[2].stateLabel).toBe('Passed')
	})
})
