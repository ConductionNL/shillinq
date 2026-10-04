/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-relation-both-sides: the suggestions' row actions send the pair
 * the table shows, the handlers the manifest names are registered, and the
 * widgets read endpoints the routes serve.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/reporting-relation-both-sides.json'),
		'utf8',
	),
)
const payables = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/bookkeeping-accounts-payable-core.json'),
		'utf8',
	),
)
// The handler map main.js hands to both lookups (live pass S4).
const mainJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		'/index.php' + url.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(() => Promise.resolve(null)),
}))
vi.mock('../../src/modals/RelationsExportDialog.vue', () => ({
	default: { name: 'RelationsExportDialog' },
}))

const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const pair = {
	customerId: 'c-noord',
	customerName: 'Transport Noord B.V.',
	payeeId: 'p-noord',
	matchedOn: 'vat',
	number: 'NL812345678B01',
}

describe('relations both ways', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('confirms the pair the row shows, with what matched', async () => {
		const { confirmRelationSuggestion } =
			await import('../../src/utils/relationActions.js')
		axiosMock.post.mockResolvedValue({ data: {} })

		expect(await confirmRelationSuggestion({ item: pair })).toBe(true)
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/relations/links',
			{ customerId: 'c-noord', payeeId: 'p-noord', matchedOn: 'vat' },
		)
	})

	it('dismisses the pair the row shows', async () => {
		const { dismissRelationSuggestion } =
			await import('../../src/utils/relationActions.js')
		axiosMock.post.mockResolvedValue({ data: { dismissed: true } })

		expect(await dismissRelationSuggestion({ item: pair })).toBe(true)
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/relations/suggestions/dismiss',
			{ customerId: 'c-noord', payeeId: 'p-noord' },
		)
	})

	it('opens the linked customer from the supplier page, and says so when there is none', async () => {
		const { linkedCustomerUrl } =
			await import('../../src/utils/relationActions.js')
		axiosMock.get.mockResolvedValueOnce({ data: { customer: { id: 'c-zuid' } } })
		expect(await linkedCustomerUrl('p-zuid')).toBe(
			'/index.php/apps/shillinq/bookkeeping/customers/c-zuid',
		)
		axiosMock.get.mockResolvedValueOnce({ data: { linked: false } })
		expect(await linkedCustomerUrl('p-west')).toBeNull()
	})

	it('names only registered handlers and served endpoints', () => {
		const report = fragment.pages.find((p) => p.id === 'RelationsBothWays')
		const customer = manifest.pages.find((p) => p.id === 'CustomerDetail')
		const payee = payables.pages.find((p) => p.id === 'PayeeDetail')
		const handlers = [
			...report.config.headerActions.map((a) => a.handler),
			...report.config.widgets.flatMap((w) =>
				(w.content.actions || []).map((a) => a.handler),
			),
			...payee.config.headerActions
				.filter((a) => a.type === 'handler')
				.map((a) => a.handler),
		]
		for (const handler of handlers) {
			expect(mainJs).toContain(`\t${handler},`)
		}
		const urls = [...report.config.widgets, ...customer.config.widgets]
			.map(
				(w) =>
					w.content
					&& w.content.endpointSource
					&& w.content.endpointSource.url,
			)
			.filter(Boolean)
		expect(urls.length).toBeGreaterThanOrEqual(7)
		for (const url of urls) {
			const route = url
				.replace('/apps/shillinq', '')
				.replace('@objectId', '{customerId}')
			expect(routes).toContain(`'url' => '${route}'`)
		}
		expect(customer.config.layout.map((c) => c.widgetId)).toEqual(
			expect.arrayContaining(customer.config.widgets.map((w) => w.id)),
		)
	})
})
