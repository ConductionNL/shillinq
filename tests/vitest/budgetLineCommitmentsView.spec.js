/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetLineCommitments as it renders: the four BBV columns per budget line,
 * the dash for a missing coding dimension, the negative-available accent, the
 * drilldown beneath an expanded row, and the loading, empty and failed
 * states. Plus the behaviour not already pinned by
 * budgetLineCommitmentsLoad.spec.js: resolving the administration, the
 * drilldown request and the wording of each failure.
 *
 * The template is rendered with Vue's server renderer, so no DOM is needed.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name, tag) => ({
		name,
		props: ['name', 'description', 'size'],
		render() {
			return h(
				tag,
				{ 'data-stub': name, 'data-name': this.name },
				[this.description, this.$slots.default?.()],
			)
		},
	})
	return {
		NcAppContent: stub('NcAppContent', 'main'),
		NcEmptyContent: stub('NcEmptyContent', 'p'),
		NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	}
})

const { default: Commitments } = await import(
	'../../src/views/BudgetLineCommitments.vue'
)

const t = (app, text) => text

const ROW = {
	key: '5.1|FAC-2026|2026|4000',
	programme: '5.1',
	cost_centre: 'FAC-2026',
	financial_year: 2026,
	general_ledger_account: '4000',
	geautoriseerd: 100000,
	mandatory: 40000,
	gerealiseerd: 30000,
	vrij: 30000,
}

/**
 * Render the page with its data overridden. `mounted()` is dropped so the
 * render does not load over the state under test.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data) {
	const app = createSSRApp({
		...Commitments,
		mounted() {},
		data: () => ({ ...Commitments.data(), ...data }),
	})
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * The page's methods bound to a plain context seeded from its data.
 *
 * @param {object} [state] The state to seed on top of the defaults.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { ...Commitments.data(), t, ...state }
	for (const [name, fn] of Object.entries(Commitments.methods)) {
		ctx[name] = typeof fn === 'function' ? fn.bind(ctx) : fn
	}
	return ctx
}

beforeEach(() => {
	http.get.mockReset()
})

describe('BudgetLineCommitments rendering', () => {
	it('shows only the loading icon while loading', async () => {
		const html = await render({})

		expect(html).toContain('Loading budget lines')
		expect(html).not.toContain('data-testid="budget-line-row"')
		expect(html).not.toContain('data-stub="NcEmptyContent"')
	})

	it('explains an administration without commitment lines', async () => {
		const html = await render({ loading: false })

		expect(html).toContain('No budget lines')
		expect(html).toContain(
			'No commitment line records exist yet. Approve a purchase order or sign a contract to materialise a commitment.',
		)
	})

	it('renders a row per budget line with its four amounts', async () => {
		const html = await render({ loading: false, rows: [ROW] })

		expect(html.match(/data-testid="budget-line-row"/g)).toHaveLength(1)
		expect(html).toContain('5.1')
		expect(html).toContain('FAC-2026')
		expect(html).toContain('2026')
		expect(html).toContain('4000')
		expect(html).toContain('aria-expanded="false"')
		expect(html).not.toContain('budget-line-commitments__amount-cell--negative')
	})

	it('renders a dash for every missing coding dimension', async () => {
		const html = await render({
			loading: false,
			rows: [{ key: 'k', programme: '', cost_centre: '', financial_year: null, general_ledger_account: '' }],
		})

		expect(html.match(/—/g).length).toBeGreaterThanOrEqual(4)
	})

	it('accents an overspent line whose available budget is negative', async () => {
		const html = await render({ loading: false, rows: [{ ...ROW, vrij: -500 }] })

		expect(html).toContain('budget-line-commitments__amount-cell--negative')
	})

	it('shows the drilldown loading, empty and filled states under the expanded row only', async () => {
		const loading = await render({
			loading: false,
			rows: [ROW],
			expandedKey: ROW.key,
			drilldownLoading: true,
		})
		const empty = await render({ loading: false, rows: [ROW], expandedKey: ROW.key })
		const filled = await render({
			loading: false,
			rows: [ROW],
			expandedKey: ROW.key,
			drilldownItems: [
				{ id: 'c1', commitment: 'PO-1', amount_excl_vat: 25000 },
				{ id: 'c2', commitment: 'PO-2', amount_excl_vat: 15000 },
			],
		})
		const collapsed = await render({ loading: false, rows: [ROW], drilldownItems: [{ id: 'c1' }] })

		expect(loading).toContain('budget-line-commitments__drilldown-row')
		expect(loading).not.toContain('No underlying commitments found for this line.')
		expect(empty).toContain('No underlying commitments found for this line.')
		expect(filled).toContain('data-testid="budget-line-drilldown"')
		expect(filled).toContain('PO-1')
		expect(filled).toContain('PO-2')
		expect(filled).toContain('aria-expanded="true"')
		expect(collapsed).not.toContain('budget-line-commitments__drilldown-row')
	})

	it('shows the error as an alert', async () => {
		const html = await render({
			loading: false,
			rows: [ROW],
			errorMessage: 'Failed to load budget lines.',
		})

		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to load budget lines\./)
	})
})

describe('BudgetLineCommitments administration', () => {
	it('takes the active administration from the session context', async () => {
		http.get.mockResolvedValue({ data: { activeAdministrationId: 'adm-1' } })

		await expect(context().resolveAdministrationId()).resolves.toBe('adm-1')
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/administrations/context',
		)
	})

	it('falls back to the first accessible administration', async () => {
		http.get.mockResolvedValue({
			data: { administrations: [{ administrationId: 'adm-9' }] },
		})

		await expect(context().resolveAdministrationId()).resolves.toBe('adm-9')
	})

	it('resolves to none when the context is empty or unreadable', async () => {
		http.get.mockResolvedValue({ data: {} })
		await expect(context().resolveAdministrationId()).resolves.toBe('')

		http.get.mockRejectedValue(new Error('no session'))
		await expect(context().resolveAdministrationId()).resolves.toBe('')
	})

	it('loads the rows on mount', () => {
		const vm = context()
		vm.loadRows = vi.fn()

		Commitments.mounted.call(vm)

		expect(vm.loadRows).toHaveBeenCalledTimes(1)
	})
})

describe('BudgetLineCommitments failures', () => {
	it('words a missing aggregation endpoint, a refusal and anything else', () => {
		const vm = context()

		expect(vm.rowsLoadError({ response: { status: 404 } })).toBe(
			'Aggregation endpoint unavailable on this OpenRegister build.',
		)
		expect(vm.rowsLoadError({ response: { status: 501 } })).toBe(
			'Aggregation endpoint unavailable on this OpenRegister build.',
		)
		expect(vm.rowsLoadError({ response: { status: 401 } })).toBe(
			'Permission required to read budget-line data.',
		)
		expect(vm.rowsLoadError({ response: { status: 403 } })).toBe(
			'Permission required to read budget-line data.',
		)
		expect(vm.rowsLoadError({ response: { status: 500 } })).toBe(
			'Failed to load budget lines.',
		)
		expect(vm.rowsLoadError(undefined)).toBe('Failed to load budget lines.')
	})
})

describe('BudgetLineCommitments drilldown', () => {
	it('reads the underlying commitments with bare property filters', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 'c1' }] } })
		const vm = context({ rows: [ROW] })

		await vm.toggleDrilldown(ROW)

		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/CommitmentLine',
			{
				params: {
					programme: '5.1',
					costCentre: 'FAC-2026',
					financialYear: 2026,
					generalLedgerAccount: '4000',
					_limit: 100,
				},
			},
		)
		expect([vm.expandedKey, vm.drilldownItems, vm.drilldownLoading]).toEqual([
			ROW.key,
			[{ id: 'c1' }],
			false,
		])
	})

	it('reads a bare list body and nothing from any other shape', async () => {
		http.get.mockResolvedValue({ data: [{ id: 'c1' }] })
		const bare = context()
		await bare.toggleDrilldown(ROW)

		http.get.mockResolvedValue({ data: { total: 0 } })
		const other = context()
		await other.toggleDrilldown(ROW)

		expect(bare.drilldownItems).toEqual([{ id: 'c1' }])
		expect(other.drilldownItems).toEqual([])
	})

	it('collapses the row it is already showing without a request', async () => {
		const vm = context({
			expandedKey: ROW.key,
			drilldownItems: [{ id: 'c1' }],
		})

		await vm.toggleDrilldown(ROW)

		expect([vm.expandedKey, vm.drilldownItems]).toEqual([null, []])
		expect(http.get).not.toHaveBeenCalled()
	})

	it('keeps the row open with no commitments when the request fails', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const vm = context({ drilldownItems: [{ id: 'stale' }] })

		await vm.toggleDrilldown(ROW)

		expect([vm.expandedKey, vm.drilldownItems, vm.drilldownLoading]).toEqual([
			ROW.key,
			[],
			false,
		])
	})
})
