/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AccountantPortalDashboard driven through its template: each client card's
 * handover-pack button downloads that client's pack, at most three attention
 * items are listed per card, and the loading, empty and failed states render
 * whichever way the components render their slots. Only the accountant API
 * and the @nextcloud/vue components are replaced.
 *
 * @spec openspec/specs/accountant-portal/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const api = vi.hoisted(() => ({ downloadHandoverPack: vi.fn(), fetchAccountantDashboard: vi.fn() }))
vi.mock('../../src/api/accountantApi.js', () => api)
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcAppContent: standIn('NcAppContent'),
		NcButton: standIn('NcButton'),
		NcEmptyContent: standIn('NcEmptyContent'),
		NcLoadingIcon: standIn('NcLoadingIcon'),
	}
})

const { default: Dashboard } = await import('../../src/views/AccountantPortalDashboard.vue')

const globals = { t: (app, text) => text }

const CLIENTS = [
	{
		administrationId: 'adm-a',
		name: 'Bakkerij A',
		role: 'accountant',
		periodClose: { state: 'closed', endDate: '2026-09-30' },
		vatFiling: { statusCode: 'filed', dueDate: '2026-10-31', overdue: false },
		missingDocuments: 0,
		openItemsCount: 4,
		attentionItems: [
			{ id: 'i1', message: 'First' },
			{ id: 'i2', message: 'Second' },
			{ id: 'i3', message: 'Third' },
			{ id: 'i4', message: 'Fourth' },
		],
	},
	{
		administrationId: 'adm-b',
		administrationCode: 'B-01',
		role: 'viewer',
		periodClose: null,
		vatFiling: null,
		missingDocuments: 2,
		openItemsCount: 0,
		attentionItems: [],
	},
]

beforeEach(() => {
	api.downloadHandoverPack.mockReset()
	api.fetchAccountantDashboard.mockReset()
})

describe('AccountantPortalDashboard template events', () => {
	it('downloads the handover pack of the card whose button is clicked', async () => {
		const view = await renderWithStandIns(Dashboard, { data: { loading: false, administrations: CLIENTS }, globals })

		const buttons = view.find((vnode) => vnode.props?.['data-testid'] === 'accountant-handover-pack-button')
		expect(buttons).toHaveLength(2)
		buttons[1].props.onClick()

		expect(api.downloadHandoverPack).toHaveBeenCalledTimes(1)
		expect(api.downloadHandoverPack).toHaveBeenCalledWith('adm-b')
	})

	it('lists at most three attention items and only on a card that has them', async () => {
		const view = await renderWithStandIns(Dashboard, { data: { loading: false, administrations: CLIENTS }, globals })

		const lists = view.find((vnode) => vnode.type === 'ul')
		expect(lists).toHaveLength(1)
		expect(view.find((vnode) => vnode.type === 'li').map(vnodeText)).toEqual(['First', 'Second', 'Third'])
		expect(view.html).toContain('Download handover pack')
		expect(view.html).not.toContain('Fourth')
	})

	it('shows the loading icon, then the empty state, then an error, in the vnode branch', async () => {
		const loading = await renderWithStandIns(Dashboard, { globals })
		expect(loading.standIns('NcLoadingIcon')).toHaveLength(1)
		expect(loading.standIns('NcEmptyContent')).toHaveLength(0)

		const empty = await renderWithStandIns(Dashboard, { data: { loading: false }, globals })
		expect(empty.standIns('NcEmptyContent').map((attrs) => attrs.name)).toEqual(['No client administrations'])

		const failed = await renderWithStandIns(Dashboard, { data: { loading: false, errorMessage: 'Nope.' }, globals })
		expect(failed.find((vnode) => vnode.props?.role === 'alert').map(vnodeText)).toEqual(['Nope.'])
	})

	it('renders the same states when the components render their slots the server way', async () => {
		const loading = await renderWithStandIns(Dashboard, { globals, slots: 'ssr' })
		expect(loading.standIns('NcLoadingIcon')).toEqual([{ size: 32, name: 'Loading client administrations' }])

		const empty = await renderWithStandIns(Dashboard, { data: { loading: false }, globals, slots: 'ssr' })
		expect(empty.standIns('NcEmptyContent')).toHaveLength(1)
		expect(empty.html).not.toContain('accountant-client-card')

		const cards = await renderWithStandIns(Dashboard, {
			data: { loading: false, administrations: CLIENTS, errorMessage: 'Nope.' },
			globals,
			slots: 'ssr',
		})
		expect(cards.html.match(/data-testid="accountant-client-card"/g)).toHaveLength(2)
		expect(cards.html).toContain('Bakkerij A')
		expect(cards.html).toContain('B-01')
		expect(cards.html).toMatch(/<li[^>]*>Third<\/li>/)
		expect(cards.html).not.toContain('Fourth')
		expect(cards.html.match(/Download handover pack/g)).toHaveLength(2)
		expect(cards.html).toMatch(/role="alert"[^>]*>\s*Nope\.\s*</)
	})
})
