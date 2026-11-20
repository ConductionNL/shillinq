/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * SegmentPnLDashboard rendered with recording stand-ins for @nextcloud/vue
 * (tests/vitest/ssrCapture.js), so the segment chips, the period select and
 * the CSV button are pressed through the handlers the template bound; and
 * the load itself against a stubbed register: the administration context,
 * the fiscal periods and the GLLine aggregation, with their exact requests.
 *
 * @spec openspec/specs/bookkeeping-cost-centers-dimensions/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { default: SegmentPnLDashboard } = await import('../../src/views/bookkeeping/dimensions/SegmentPnLDashboard.vue')

const CONTEXT = '/index.php/apps/shillinq/api/administrations/context'
const PERIODS = '/index.php/apps/openregister/api/objects/shillinq/FiscalPeriod'
const AGGREGATION = (name) => `/index.php/apps/openregister/api/objects/aggregations/shillinq/GLLine/${name}`
const t = (app, text) => text

/**
 * Render the dashboard with part of its data replaced.
 *
 * @param {object} state The data to lay over the dashboard's own.
 * @return {Promise<{html: string, view: object}>}
 */
async function dashboard(state) {
	const view = session()
	const html = await view.render(
		SegmentPnLDashboard,
		{ loading: false, administrationId: 'adm-1', periods: [{ id: 'p1', label: 'January' }, { id: 'p2', label: 'February' }], ...state },
		{ t },
		['NcAppContent', 'NcButton', 'NcEmptyContent', 'NcLoadingIcon'],
	)
	return { html: html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, ''), view }
}

/**
 * A plain dashboard instance, methods bound and computed readable.
 *
 * @param {object} [over] The data to start from.
 * @return {object}
 */
function instance(over = {}) {
	const self = { ...SegmentPnLDashboard.data.call({}), t, ...over }
	for (const [name, method] of Object.entries(SegmentPnLDashboard.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(SegmentPnLDashboard.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

/**
 * The register: the administration context, the fiscal periods and one aggregation answer.
 *
 * @param {object} aggregation The aggregation response body.
 */
function register(aggregation) {
	axios.get.mockImplementation(async (url) => {
		if (url === CONTEXT) {
			return { data: { activeAdministrationId: 'adm-1' } }
		}
		if (url === PERIODS) {
			return {
				data: {
					results: [
						{ periodId: '2026-M02', name: 'February', startDate: '2026-02-01' },
						{ periodId: '2026-M01', startDate: '2026-01-01' },
					],
				},
			}
		}
		return { data: aggregation }
	})
}

const row = (key, over = {}) => ({ key, name: '', parent: '', revenue: 0, costs: 0, result: 0, depth: 0, ...over })

beforeEach(() => {
	axios.get.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('SegmentPnLDashboard controls', () => {
	it('offers one chip per segment, the active one primary, and the periods', async () => {
		const { html, view } = await dashboard({ activeSegment: 'costObject', rows: [row('CO1', { revenue: 5, result: 5 })] })

		const chips = view.stubs.filter((entry) => entry.name === 'NcButton' && entry.attrs.variant !== 'tertiary')
		expect(chips.map((entry) => [entry.text, entry.attrs.variant])).toEqual([
			['Cost center', 'secondary'],
			['Cost center (rolled up)', 'secondary'],
			['Cost object', 'primary'],
			['Project', 'secondary'],
			['Analytical dimension', 'secondary'],
		])
		expect(html).toMatch(/<option value="p2"[^>]*>\s*February\s*<\/option>/)
		expect(html).toContain('data-segment="costObject"')
		expect(view.labelled('NcButton', 'Export CSV').disabled).toBe(false)
	})

	it('loads another segment through its chip, scoped to the administration', async () => {
		register({ groups: [{ key: 'P1', name: 'Bouw', values: { revenue: '200', costs: 50, result: 150 } }] })
		const { view } = await dashboard({ rows: [row('CC1')] })

		await view.labelled('NcButton', 'Project').onClick()
		await vi.waitFor(() => expect(view.vm.loading).toBe(false))

		expect(view.vm.activeSegment).toBe('project')
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenNthCalledWith(1, CONTEXT)
		expect(axios.get).toHaveBeenNthCalledWith(2, AGGREGATION('byProject'), { params: { 'filter[administrationId]': 'adm-1' } })
		expect(view.vm.rows).toEqual([row('P1', { name: 'Bouw', revenue: 200, costs: 50, result: 150 })])
	})

	it('narrows to the period chosen in the select', async () => {
		register({ groups: [] })
		const { view } = await dashboard({ activeSegment: 'costObject', rows: [row('CO1')] })
		const [select] = view.vnodes((vnode) => vnode.type === 'select')

		select.props['onUpdate:modelValue']('p2')
		expect(view.vm.periodId).toBe('p2')
		await select.props.onChange()

		expect(axios.get).toHaveBeenLastCalledWith(AGGREGATION('byCostObject'), {
			params: { 'filter[administrationId]': 'adm-1', 'filter[periodId]': 'p2' },
		})
		expect([view.vm.rows, view.vm.errorMessage]).toEqual([[], ''])
	})

	it('switches segment through the chip vnodes the template builds too', async () => {
		register({ groups: [] })
		const { view } = await dashboard({ rows: [] })
		const chips = view.vnodes((vnode) => vnode.type?.name === 'NcButton' && vnode.props?.variant)
		expect(chips).toHaveLength(6)

		chips[0].props.onClick()
		expect(axios.get).not.toHaveBeenCalled()
		await chips[3].props.onClick()
		await vi.waitFor(() => expect(view.vm.loading).toBe(false))
		expect(view.vm.activeSegment).toBe('project')
		expect(axios.get).toHaveBeenLastCalledWith(AGGREGATION('byProject'), { params: { 'filter[administrationId]': 'adm-1' } })
	})

	it('exports the shown rows as CSV through the Export CSV button', async () => {
		const blobs = []
		vi.stubGlobal('URL', { createObjectURL: (blob) => { blobs.push(blob); return 'blob:2' }, revokeObjectURL: vi.fn() })
		vi.stubGlobal('document', { createElement: () => ({ click: vi.fn() }), body: { appendChild: vi.fn(), removeChild: vi.fn() } })
		const { view } = await dashboard({ rows: [row('CC1', { name: null, revenue: 12, costs: 2, result: 10 })] })

		view.labelled('NcButton', 'Export CSV').onClick()

		expect(blobs).toHaveLength(1)
		expect(await blobs[0].text()).toBe('segment,name,parent,revenue,costs,result\nCC1,,,12.00,2.00,10.00\n')
	})
})

describe('SegmentPnLDashboard states', () => {
	it('shows the loading icon while loading', async () => {
		const { html, view } = await dashboard({ loading: true, rows: [row('CC1')] })
		expect(view.attrsOf('NcLoadingIcon')[0].name).toBe('Loading segment P&L')
		expect(html).not.toContain('<table')
		expect(view.attrsOf('NcEmptyContent')).toEqual([])
	})

	it('shows the empty state, a disabled export and the error', async () => {
		const { html, view } = await dashboard({ rows: [], errorMessage: 'Failed to load segment P&L.' })
		expect(view.attrsOf('NcEmptyContent')[0]).toMatchObject({
			name: 'No segment data',
			description: 'No posted lines on profit and loss accounts carry this segment yet.',
		})
		expect(view.labelled('NcButton', 'Export CSV').disabled).toBe(true)
		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to load segment P&amp;L\.\s*</)
	})

	it('adds the parent cells, an empty total cell and the names for the hierarchy', async () => {
		const { html } = await dashboard({
			activeSegment: 'costCenterHierarchy',
			rows: [row('A', { name: 'Holding', revenue: 10, result: 10 }), row('A1', { parent: 'A', depth: 1, costs: 4, result: -4 })],
		})
		const bodyCells = [...html.matchAll(/<tr class="([^"]*)"[^>]*>([\s\S]*?)<\/tr>/g)]
			.map((match) => [match[1].trim(), match[2].replace(/<[^>]+>/g, '|').replace(/\|+/g, '|').replace(/\s+/g, ' ')])
		expect(bodyCells).toHaveLength(2)
		expect(bodyCells[1][0]).toBe('segment-pnl-dashboard__row--child')
		expect(bodyCells[1][1]).toMatch(/\| ?A ?\|$/)
		expect(html).toContain('Holding')
		expect(html).toMatch(/<tfoot>[\s\S]*<td><\/td><\/tr><\/tfoot>/)
		expect(html).toContain('segment-pnl-dashboard__amount-cell--negative')
	})
})

describe('SegmentPnLDashboard loading', () => {
	it('loads the segment of the dashboard when mounted', () => {
		const self = { activeSegment: 'costObject', loadSegment: vi.fn() }
		SegmentPnLDashboard.mounted.call(self)
		expect(self.loadSegment).toHaveBeenCalledWith('costObject')
	})

	it('reads the periods first when it has none, then the flat rows', async () => {
		register({ groups: [{ key: 'CC1', name: 'Zuivering', values: { revenue: 100, costs: 40, result: 60 } }, { key: '', values: {} }] })
		const view = instance()

		await view.loadSegment('costCenter')

		expect(axios.get.mock.calls).toEqual([
			[CONTEXT],
			[PERIODS, { params: { administrationId: 'adm-1', _limit: 500 } }],
			[AGGREGATION('byCostCenter'), { params: { 'filter[administrationId]': 'adm-1' } }],
		])
		expect(view.periods).toEqual([
			{ id: '2026-M01', label: '2026-M01' },
			{ id: '2026-M02', label: 'February' },
		])
		expect(view.rows).toEqual([row('CC1', { name: 'Zuivering', revenue: 100, costs: 40, result: 60 })])
		expect(view.total).toEqual({ revenue: 100, costs: 40, result: 60 })
		expect([view.loading, view.errorMessage]).toEqual([false, ''])
	})

	it('nests the hierarchy segment and skips a key it already placed', async () => {
		register({
			groups: [
				{ key: 'A', values: {} },
				{ key: 'A1', parent: 'A', values: {} },
				{ key: 'A1', parent: 'A', values: { revenue: 9 } },
			],
		})
		const view = instance({ periods: [{ id: 'p1', label: 'January' }] })

		await view.loadSegment('costCenterHierarchy')

		expect(axios.get).toHaveBeenLastCalledWith(AGGREGATION('byCostCenterHierarchy'), { params: { 'filter[administrationId]': 'adm-1' } })
		expect(view.rows.map((r) => [r.key, r.depth, r.revenue])).toEqual([['A', 0, 0], ['A1', 1, 0]])
	})

	it('formats with two decimals when the currency formatter is unavailable', () => {
		vi.stubGlobal('Intl', { NumberFormat: function NumberFormat() { throw new RangeError('no ICU') } })
		const view = instance()
		expect(view.formatAmount(12.5)).toBe('12.50')
		expect(view.formatAmount('x')).toBe('0.00')
	})

	it('escapes absent CSV values as empty and quotes newlines', () => {
		const view = instance()
		expect([view.csvEscape(null), view.csvEscape(undefined), view.csvEscape('a\nb'), view.csvEscape(7)]).toEqual(['', '', '"a\nb"', '7'])
	})
})
