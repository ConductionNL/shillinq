/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-vat-return-from-books 2.3: "Prepare return" on BTW returns reaches
 * shillinq. The manifest, the handler map, the menu, the helper's URL and
 * appinfo/routes.php are held to each other, and the request the dialog
 * sends is the one VATReturnController::create reads.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md#task-2.3
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
const menuLayout = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/menu-layout.json'), 'utf8'),
)
const actionsJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(
	path.join(ROOT, 'lib/Controller/VATReturnController.php'),
	'utf8',
)

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const page = (id) => fragment.pages.find((p) => p.id === id)

describe('Prepare return', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('is a header action on BTW returns whose handler both maps provide', () => {
		const action = (page('VATReturns').config.headerActions || []).find(
			(a) => a.id === 'prepare-vat-return',
		)
		expect(action).toBeDefined()
		expect(action.label).toBe('Prepare return')
		expect(action.handler).toBe('openPrepareVatReturn')
		expect(actionsJs).toContain('openPrepareVatReturn,')
	})

	it('is the Taxes menu entry for BTW returns, and the old page is out of the menu', () => {
		expect(menuLayout.relocations.VATReturns).toBe('Taxes')
		expect(menuLayout.removals).toContain('BtwAangiften')
		expect(menuLayout.removalsReplacedBy.BtwAangiften).toBe('VATReturns')
	})

	it('posts to the route the controller serves, with the parameters it reads', async () => {
		const { buildPrepareRequest, prepareVatReturn } =
			await import('../../src/utils/vatReturnApi.js')
		expect(routes).toContain(
			"'vATReturn#create', 'url' => '/api/vat-returns', 'verb' => 'POST'",
		)
		const body = buildPrepareRequest({
			administrationId: 'adm-kb',
			period: 'quarter',
			periodYear: '2026',
			periodNumber: '3',
			regime: 'standard',
		})
		expect(body).toEqual({
			administrationId: 'adm-kb',
			period: 'quarter',
			periodYear: 2026,
			periodNumber: 3,
			regime: 'standard',
		})
		for (const key of Object.keys(body)) {
			expect(controller).toContain(`getParam('${key}'`)
		}
		axiosMock.post.mockResolvedValue({
			data: { data: { id: 'ret-1', returnNumber: 'NL-2026-Q3' } },
		})
		const created = await prepareVatReturn(body)
		expect(axiosMock.post.mock.calls[0][0]).toBe(
			'/index.php/apps/shillinq/api/vat-returns',
		)
		expect(created.returnNumber).toBe('NL-2026-Q3')
	})

	it('sends a year return as period number 1, and offers the last finished period', async () => {
		const { buildPrepareRequest, lastFinishedPeriod } =
			await import('../../src/utils/vatReturnApi.js')
		expect(
			buildPrepareRequest({ administrationId: 'a', period: 'year', periodYear: 2025 }).periodNumber,
		).toBe(1)
		const october = new Date(2026, 9, 5)
		expect(lastFinishedPeriod('quarter', october)).toEqual({ periodYear: 2026, periodNumber: 3 })
		expect(lastFinishedPeriod('month', october)).toEqual({ periodYear: 2026, periodNumber: 9 })
		expect(lastFinishedPeriod('quarter', new Date(2026, 1, 1))).toEqual({ periodYear: 2025, periodNumber: 4 })
		expect(lastFinishedPeriod('month', new Date(2026, 0, 9))).toEqual({ periodYear: 2025, periodNumber: 12 })
	})

	it('says why a period is refused', async () => {
		const { prepareErrorText } = await import('../../src/utils/vatReturnApi.js')
		expect(controller).toContain('Cannot create returns for future periods')
		expect(
			prepareErrorText({ response: { status: 400, data: { error: 'Cannot create returns for future periods' } } }),
		).toContain('has not ended yet')
		expect(prepareErrorText({ response: { status: 403, data: {} } })).toContain(
			'cannot prepare a return for this administration',
		)
		expect(prepareErrorText({})).toContain('could not be prepared')
	})

	it('links the prepared return to its page route', async () => {
		const { vatReturnUrl } = await import('../../src/utils/vatReturnApi.js')
		expect(page('VATReturnDetail').route).toBe('/vat-returns/:id')
		expect(vatReturnUrl('ret 1')).toBe('/index.php/apps/shillinq/vat-returns/ret%201')
	})
})
