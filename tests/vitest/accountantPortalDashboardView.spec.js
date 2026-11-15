/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AccountantPortalDashboard: what it renders while loading, without clients,
 * with client cards and after a failure, and how it loads the dashboard and
 * labels each client's period close and BTW filing. The template is rendered
 * with Vue's server renderer, so no DOM is needed.
 *
 * @spec openspec/specs/accountant-portal/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const api = vi.hoisted(() => ({ fetchAccountantDashboard: vi.fn(), downloadHandoverPack: vi.fn() }))
vi.mock('../../src/api/accountantApi.js', () => api)
vi.mock('@nextcloud/vue', () => ({
	NcAppContent: { render() { return h('main', this.$slots.default?.()) } },
	NcButton: { props: ['variant'], render() { return h('button', { 'data-variant': this.variant }, this.$slots.default?.()) } },
	NcEmptyContent: { props: ['name', 'description'], render() { return h('p', { class: 'empty' }, `${this.name}: ${this.description}`) } },
	NcLoadingIcon: { props: ['name'], render() { return h('span', { class: 'loading' }, this.name) } },
}))

const { default: Dashboard } = await import('../../src/views/AccountantPortalDashboard.vue')

const t = (app, text) => text

/**
 * Render the dashboard with its data overridden.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data) {
	const app = createSSRApp({ ...Dashboard, data: () => ({ ...Dashboard.data(), ...data }) })
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * The dashboard's methods bound to a plain context seeded from its data.
 *
 * @return {object} The context.
 */
function context() {
	const ctx = { ...Dashboard.data(), t }
	for (const [name, fn] of Object.entries(Dashboard.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

const client = {
	administrationId: 'adm-1',
	name: 'Bakkerij Jansen',
	role: 'accountant',
	periodClose: { state: 'closed', endDate: '2026-03-31' },
	vatFiling: { statusCode: 'open', dueDate: '2026-04-30', overdue: true },
	missingDocuments: 2,
	openItemsCount: 0,
	attentionItems: [{ id: 1, message: 'One' }, { id: 2, message: 'Two' }, { id: 3, message: 'Three' }, { id: 4, message: 'Four' }],
}

beforeEach(() => {
	api.fetchAccountantDashboard.mockReset()
	api.downloadHandoverPack.mockReset()
})

describe('AccountantPortalDashboard rendering', () => {
	it('shows only the loading icon while loading', async () => {
		const html = await render({})
		expect(html).toContain('Loading client administrations')
		expect(html).not.toContain('class="empty"')
		expect(html).not.toContain('data-testid="accountant-client-card"')
	})

	it('explains an empty portal', async () => {
		const html = await render({ loading: false })
		expect(html).toContain('No client administrations: You have no administration memberships yet.')
	})

	it('renders a card per client with its signals, at most three attention items and the pack button', async () => {
		const html = await render({ loading: false, administrations: [client, { administrationId: 'adm-2', administrationCode: 'KL-2', role: 'viewer', missingDocuments: 0, openItemsCount: 5 }] })

		expect(html.match(/data-testid="accountant-client-card"/g)).toHaveLength(2)
		expect(html).toContain('Bakkerij Jansen')
		expect(html).toContain('KL-2')
		expect(html).toContain('closed (2026-03-31)')
		expect(html).toContain('open — due 2026-04-30')
		expect(html).toContain('No period recorded')
		expect(html).toContain('No return on file')
		expect(html).toContain('Three')
		expect(html).not.toContain('Four')
		expect(html.match(/accountant-portal__status-row--overdue/g)).toHaveLength(3)
		expect(html.match(/data-testid="accountant-handover-pack-button"/g)).toHaveLength(2)
	})

	it('falls back to the administration id for a client without name or code', async () => {
		const html = await render({ loading: false, administrations: [{ administrationId: 'adm-9', missingDocuments: 0, openItemsCount: 0 }] })
		expect(html).toContain('adm-9')
	})

	it('shows the error as an alert', async () => {
		const html = await render({ loading: false, errorMessage: 'Failed to load the accountant dashboard.' })
		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to load the accountant dashboard\./)
	})
})

describe('AccountantPortalDashboard behaviour', () => {
	it('loads the administrations and stops loading', async () => {
		api.fetchAccountantDashboard.mockResolvedValue({ administrations: [client] })
		const vm = context()
		await vm.loadDashboard()
		expect([vm.administrations, vm.loading, vm.errorMessage]).toEqual([[client], false, ''])
	})

	it('treats a response without a list as no administrations', async () => {
		api.fetchAccountantDashboard.mockResolvedValue({ administrations: 'none' })
		const vm = context()
		await vm.loadDashboard()
		expect(vm.administrations).toEqual([])
	})

	it('asks a signed-out user to sign in, and reports other failures', async () => {
		const vm = context()
		api.fetchAccountantDashboard.mockRejectedValueOnce({ response: { status: 401 } })
		await vm.loadDashboard()
		expect([vm.errorMessage, vm.loading]).toEqual(['Please sign in to view the accountant portal.', false])

		api.fetchAccountantDashboard.mockRejectedValueOnce(new Error('down'))
		await vm.loadDashboard()
		expect(vm.errorMessage).toBe('Failed to load the accountant dashboard.')
	})

	it('loads the dashboard when mounted', async () => {
		api.fetchAccountantDashboard.mockResolvedValue({ administrations: [client] })
		const vm = context()
		Dashboard.mounted.call(vm)
		await vi.waitFor(() => expect(vm.loading).toBe(false))
		expect(api.fetchAccountantDashboard).toHaveBeenCalledTimes(1)
		expect(vm.administrations).toEqual([client])
	})

	it('downloads the pack of the chosen administration', () => {
		context().downloadPack('adm-1')
		expect(api.downloadHandoverPack).toHaveBeenCalledWith('adm-1')
	})

	it('labels a filing without a due date by its status alone', () => {
		expect(context().vatFilingLabel({ statusCode: 'submitted' })).toBe('submitted')
	})
})
