/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BudgetLineCommitments driven through its template: clicking a budget-line
 * row or pressing Enter on it opens that line's drilldown (and a second
 * activation closes it), other keys do nothing, and every state — loading,
 * empty, rows with their drilldown loading, empty or listed, failed — renders
 * whichever way the components render their slots. Only HTTP and the
 * @nextcloud/vue components are replaced.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { domEvent, renderWithStandIns } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcAppContent: standIn('NcAppContent'),
		NcEmptyContent: standIn('NcEmptyContent'),
		NcLoadingIcon: standIn('NcLoadingIcon'),
	}
})

const { default: Commitments } = await import('../../src/views/BudgetLineCommitments.vue')

const globals = { t: (app, text) => text }
const DRILLDOWN_URL = '/index.php/apps/openregister/api/objects/shillinq/CommitmentLine'

const ROW = {
	key: '5.1|FAC-2026|2026|4000',
	programme: '5.1',
	cost_centre: 'FAC-2026',
	financial_year: 2026,
	general_ledger_account: '4000',
	geautoriseerd: 100000,
	mandatory: 40000,
	gerealiseerd: 30000,
	vrij: -500,
}
const LOADED = { loading: false, rows: [ROW] }

beforeEach(() => {
	http.get.mockReset()
})

/**
 * The budget-line row vnodes of a render.
 *
 * @param {object} view The render.
 * @return {Array<object>} The rows.
 */
function rowsOf(view) {
	return view.find((vnode) => vnode.props?.['data-testid'] === 'budget-line-row')
}

describe('BudgetLineCommitments template events', () => {
	it('opens the drilldown of a clicked row with the request for its commitments', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 'c1', commitment: 'PO-1', amount_excl_vat: 1200 }] } })
		const view = await renderWithStandIns(Commitments, { data: LOADED, globals })

		const [row] = rowsOf(view)
		expect(row.props['aria-expanded']).toBe(false)
		await row.props.onClick()

		expect(http.get).toHaveBeenCalledTimes(1)
		const [url, { params }] = http.get.mock.calls[0]
		expect(url).toBe(DRILLDOWN_URL)
		expect(params._limit).toBe(100)
		expect(view.vm.expandedKey).toBe(ROW.key)
		expect(view.vm.drilldownItems).toEqual([{ id: 'c1', commitment: 'PO-1', amount_excl_vat: 1200 }])

		const open = await view.rerender()
		expect(rowsOf(open)[0].props['aria-expanded']).toBe(true)
		expect(open.byTestId('budget-line-drilldown')).toBeDefined()
		expect(open.html).toContain('PO-1')
	})

	it('toggles on Enter, closes on a second activation and ignores other keys', async () => {
		http.get.mockResolvedValue({ data: [] })
		const view = await renderWithStandIns(Commitments, { data: LOADED, globals })
		const [row] = rowsOf(view)

		await row.props.onKeyup(domEvent({ key: 'Tab' }))
		expect(http.get).not.toHaveBeenCalled()
		expect(view.vm.expandedKey).toBeNull()

		await row.props.onKeyup(domEvent({ key: 'Enter' }))
		expect(view.vm.expandedKey).toBe(ROW.key)
		const empty = await view.rerender()
		expect(empty.html).toContain('No underlying commitments found for this line.')

		await row.props.onKeyup(domEvent({ key: 'Enter' }))
		expect(view.vm.expandedKey).toBeNull()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('renders the drilldown loading icon and the error in the vnode branch', async () => {
		const busy = await renderWithStandIns(Commitments, {
			data: { ...LOADED, expandedKey: ROW.key, drilldownLoading: true, errorMessage: 'Nope.' },
			globals,
		})
		expect(busy.standIns('NcLoadingIcon')).toEqual([{ size: 20 }])
		expect(busy.html).toMatch(/role="alert"[^>]*>\s*Nope\.\s*</)
		expect(busy.html).toContain('budget-line-commitments__amount-cell--negative')
	})

	it('renders every state when the components render their slots the server way', async () => {
		const loading = await renderWithStandIns(Commitments, { globals, slots: 'ssr' })
		expect(loading.standIns('NcLoadingIcon')).toEqual([{ size: 32, name: 'Loading budget lines' }])

		const empty = await renderWithStandIns(Commitments, { data: { loading: false }, globals, slots: 'ssr' })
		expect(empty.standIns('NcEmptyContent').map((attrs) => attrs.name)).toEqual(['No budget lines'])

		const rows = await renderWithStandIns(Commitments, { data: { ...LOADED, errorMessage: 'Nope.' }, globals, slots: 'ssr' })
		expect(rows.html.match(/data-testid="budget-line-row"/g)).toHaveLength(1)
		expect(rows.html).toContain('FAC-2026')
		expect(rows.html).toContain('budget-line-commitments__amount-cell--negative')
		expect(rows.html).not.toContain('budget-line-commitments__drilldown-row')
		expect(rows.html).toMatch(/role="alert"[^>]*>\s*Nope\.\s*</)

		const drilling = await renderWithStandIns(Commitments, {
			data: { ...LOADED, expandedKey: ROW.key, drilldownLoading: true },
			globals,
			slots: 'ssr',
		})
		expect(drilling.standIns('NcLoadingIcon')).toEqual([{ size: 20 }])

		const none = await renderWithStandIns(Commitments, { data: { ...LOADED, expandedKey: ROW.key }, globals, slots: 'ssr' })
		expect(none.html).toContain('No underlying commitments found for this line.')

		const listed = await renderWithStandIns(Commitments, {
			data: {
				...LOADED,
				expandedKey: ROW.key,
				drilldownItems: [{ id: 'c1', commitment: 'PO-1', amount_excl_vat: 1200 }, { commitment: 'CT-9', amount_excl_vat: 300 }],
			},
			globals,
			slots: 'ssr',
		})
		expect(listed.html).toContain('data-testid="budget-line-drilldown"')
		expect(listed.html).toContain('PO-1')
		expect(listed.html).toContain('CT-9')
	})
})
