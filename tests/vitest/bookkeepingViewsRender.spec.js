/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The bookkeeping list views as they render (server-side, so no DOM is
 * needed) in their loading, empty, filled and failed states, and their
 * loads: which endpoint, which filters, and how the OCS and plain
 * envelopes are read.
 *
 * @spec openspec/specs/bookkeeping/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		props: ['name', 'description', 'variant'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name }, [this.description, this.$slots.default?.()])
		},
	})
	return { NcAppContent: slotted('NcAppContent'), NcButton: slotted('NcButton', 'button'), NcEmptyContent: slotted('NcEmptyContent'), NcLoadingIcon: slotted('NcLoadingIcon', 'span') }
})
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'emptyLabel'],
		render() {
			if (this.rows.length === 0) {
				return h('p', { 'data-stub': 'CnDataTable' }, this.emptyLabel)
			}
			return h('table', { 'data-stub': 'CnDataTable' }, [
				h('tr', this.columns.map((c) => h('th', c.label))),
				...this.rows.map((row) => h('tr', this.columns.map((c) => h('td', this.$slots['cell-' + c.key] ? this.$slots['cell-' + c.key]({ row }) : row[c.key])))),
			])
		},
	},
}))
vi.mock('../../src/components/wbso/AccountNode.vue', () => ({
	default: { name: 'AccountNode', props: ['account', 'depth'], render() { return h('li', { 'data-depth': this.depth }, this.account.name) } },
}))

globalThis.t = (app, text) => text

const { default: ChartOfAccountsView } = await import('../../src/views/bookkeeping/ChartOfAccountsView.vue')
const { default: TransactionsView } = await import('../../src/views/bookkeeping/TransactionsView.vue')
const { default: DocumentsView } = await import('../../src/views/bookkeeping/DocumentsView.vue')
const { default: FxRatesAdmin } = await import('../../src/views/bookkeeping/multi-currency/FxRatesAdmin.vue')
const { default: SegmentPnLDashboard } = await import('../../src/views/bookkeeping/dimensions/SegmentPnLDashboard.vue')

/**
 * Render a view with its data replaced by `state`.
 *
 * @param {object} view The component.
 * @param {object} state The data to render with.
 * @return {Promise<string>} The HTML.
 */
async function render(view, state) {
	const app = createSSRApp({ ...view, data: () => state })
	app.config.globalProperties.t = globalThis.t
	return renderToString(app)
}

/**
 * A plain instance of a view's options for calling its methods.
 *
 * @param {object} view The component.
 * @return {object} The instance stand-in.
 */
function instance(view) {
	const self = { ...view.data.call({}), $emit: vi.fn() }
	for (const [name, method] of Object.entries(view.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('ChartOfAccountsView', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('renders the tree, the add button only when allowed, and the failure', async () => {
		const html = await render(ChartOfAccountsView, { loading: false, roots: [{ accountNumber: '1000', name: 'Kas' }], canCreate: true, errorMessage: 'Failed to load Chart of Accounts.' })
		expect(html).toContain('Add Account')
		expect(html).toMatch(/<li[^>]*data-depth="0"[^>]*>Kas<\/li>/)
		expect(html).toContain('role="alert"')

		const plain = await render(ChartOfAccountsView, { loading: false, roots: [{ accountNumber: '1000', name: 'Kas' }], canCreate: false, errorMessage: '' })
		expect(plain).not.toContain('Add Account')
		expect(plain).not.toContain('role="alert"')
	})

	it('renders the empty and loading states', async () => {
		expect(await render(ChartOfAccountsView, { loading: false, roots: [], canCreate: false, errorMessage: '' })).toContain('No accounts yet')
		const loading = await render(ChartOfAccountsView, { loading: true, roots: [], canCreate: false, errorMessage: '' })
		expect(loading).toContain('Loading…')
		expect(loading).not.toContain('No accounts yet')
	})

	it('loads the hierarchy from either envelope', async () => {
		const view = instance(ChartOfAccountsView)
		axios.get.mockResolvedValueOnce({ data: { ocs: { data: { tree: [{ accountNumber: '1' }], canCreate: true } } } })
		await view.load()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/accounts/hierarchy')
		expect([view.roots, view.canCreate, view.loading]).toEqual([[{ accountNumber: '1' }], true, false])

		axios.get.mockResolvedValueOnce({ data: { tree: [{ accountNumber: '2' }] } })
		await view.load()
		expect([view.roots, view.canCreate]).toEqual([[{ accountNumber: '2' }], false])

		axios.get.mockResolvedValueOnce({ data: {} })
		await view.load()
		expect(view.roots).toEqual([])
	})

	it('reports a failed load and emits the add request', async () => {
		const view = instance(ChartOfAccountsView)
		axios.get.mockRejectedValueOnce(new Error('500'))
		await view.load()
		expect([view.errorMessage, view.loading]).toEqual(['Failed to load Chart of Accounts.', false])

		view.onAddAccount()
		expect(view.$emit).toHaveBeenCalledWith('add-account')
	})
})

describe('TransactionsView', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	const rows = [{ transactionDate: '2026-01-02', transactionNumber: 'T-1', transactionType: 'invoice', description: 'Huur', amount: 1234.5, status: 'posted' }]

	it('renders the rows with formatted amounts and translated statuses', async () => {
		const html = await render(TransactionsView, { transactions: rows, loading: false, errorMessage: '', canCreate: true, filters: { status: '', type: '', dateFrom: '', dateTo: '' } })
		expect(html).toContain('Create Transaction')
		expect(html).toContain('<th>Transaction Date</th>')
		expect(html).toContain('data-status="posted"')
		expect(html).toContain('>Posted<')
		expect(html).toContain('1.234,50')
	})

	it('renders the loading, empty and failed states', async () => {
		const base = { transactions: [], canCreate: false, filters: { status: '', type: '', dateFrom: '', dateTo: '' } }
		expect(await render(TransactionsView, { ...base, loading: true, errorMessage: '' })).toContain('Loading…')
		const empty = await render(TransactionsView, { ...base, loading: false, errorMessage: 'Failed to load transactions.' })
		expect(empty).toContain('Create the first transaction to start posting to the books.')
		expect(empty).toContain('role="alert"')
		expect(empty).not.toContain('Create Transaction</button>')
	})

	it('sends only the filters that are set', async () => {
		const view = instance(TransactionsView)
		axios.get.mockResolvedValue({ data: { ocs: { data: { transactions: rows, canCreate: true } } } })
		await view.load()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/transactions', { params: {} })
		expect([view.transactions, view.canCreate]).toEqual([rows, true])

		view.filters = { status: 'draft', type: 'invoice', dateFrom: '2026-01-01', dateTo: '2026-12-31' }
		axios.get.mockResolvedValue({ data: { transactions: [] } })
		await view.load()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/transactions', { params: { status: 'draft', type: 'invoice', dateFrom: '2026-01-01', dateTo: '2026-12-31' } })
		expect([view.transactions, view.canCreate]).toEqual([[], false])
	})

	it('reports a failed load, formats amounts and statuses, and emits create', async () => {
		const view = instance(TransactionsView)
		axios.get.mockRejectedValue(new Error('500'))
		await view.load()
		expect([view.errorMessage, view.loading]).toEqual(['Failed to load transactions.', false])

		expect(view.formatAmount('n/a')).toBe('n/a')
		expect(view.formatAmount(10)).toMatch(/€\s?10,00/)
		expect([view.translateStatus('draft'), view.translateStatus('reversed'), view.translateStatus('odd')]).toEqual(['Draft', 'Reversed', 'odd'])
		view.onCreate()
		expect(view.$emit).toHaveBeenCalledWith('create-transaction')
	})
})

describe('DocumentsView', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	const rows = [
		{ documentNumber: 'D-1', documentType: 'tax-form', documentDate: '2026-02-01', status: 'filed', fileReference: '/f/1' },
		{ documentNumber: 'D-2', documentType: 'scan', documentDate: '2026-02-02', status: 'odd', fileReference: '' },
	]
	const filters = { status: '', type: '', filedFrom: '' }

	it('renders translated types and statuses, a file link, and a dash without one', async () => {
		const html = await render(DocumentsView, { documents: rows, loading: false, errorMessage: '', canCreate: true, filters })
		expect(html).toContain('Upload Document')
		expect(html).toContain('<th>File Reference</th>')
		expect(html).toContain('Tax Form')
		expect(html).toContain('>Filed<')
		expect(html).toMatch(/<a[^>]*href="\/f\/1"[^>]*target="_blank"[^>]*rel="noopener"/)
		expect(html).toContain('—')
		expect(html).toContain('scan')
	})

	it('renders the loading, empty and failed states', async () => {
		expect(await render(DocumentsView, { documents: [], loading: true, errorMessage: '', canCreate: false, filters })).toContain('Loading…')
		const empty = await render(DocumentsView, { documents: [], loading: false, errorMessage: 'Failed to load documents.', canCreate: false, filters })
		expect(empty).toContain('Upload the first document to track an invoice, receipt, or contract.')
		expect(empty).toContain('role="alert"')
		expect(empty).not.toContain('Upload Document</button>')
	})

	it('sends only the filters that are set and reads either envelope', async () => {
		const view = instance(DocumentsView)
		axios.get.mockResolvedValue({ data: { ocs: { data: { documents: rows, canCreate: true } } } })
		await view.load()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/documents', { params: {} })
		expect([view.documents, view.canCreate, view.loading]).toEqual([rows, true, false])

		view.filters = { status: 'archived', type: 'memo', filedFrom: '2026-01-01' }
		axios.get.mockResolvedValue({ data: {} })
		await view.load()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/documents', { params: { status: 'archived', type: 'memo', filedFrom: '2026-01-01' } })
		expect([view.documents, view.canCreate]).toEqual([[], false])
	})

	it('reports a failed load, translates and emits upload', async () => {
		const view = instance(DocumentsView)
		axios.get.mockRejectedValue(new Error('500'))
		await view.load()
		expect([view.errorMessage, view.loading]).toEqual(['Failed to load documents.', false])

		expect(['invoice', 'receipt', 'contract', 'bank-statement', 'memo', 'x'].map((type) => view.translateType(type)))
			.toEqual(['Invoice', 'Receipt', 'Contract', 'Bank Statement', 'Memo', 'x'])
		expect([view.translateStatus('draft'), view.translateStatus('archived')]).toEqual(['Draft', 'Archived'])
		view.onUpload()
		expect(view.$emit).toHaveBeenCalledWith('upload-document')
	})
})

describe('FxRatesAdmin', () => {
	const status = (over) => ({ jobClass: 'Job', lastRunAt: null, lastRunEpoch: null, adapterDormant: false, interval: 86400, status: 'never-ran', ...over })

	it('shows the loading icon while reading the import status', async () => {
		const html = await render(FxRatesAdmin, { loading: true, errorMessage: '', status: status({}) })
		expect(html).toContain('data-name="Loading import status"')
		expect(html).not.toContain('Import status')
	})

	it('shows a successful run with its badge and time', async () => {
		const at = '2026-11-03T06:00:00Z'
		const html = await render(FxRatesAdmin, { loading: false, errorMessage: '', status: status({ status: 'ok', lastRunAt: at }) })
		expect(html).toMatch(/class="[^"]*fx-rates-admin__status-badge--ok[^"]*"[^>]*data-status="ok"[^>]*>\s*OK\s*</)
		expect(html).toContain('Last successful run')
		expect(html).toContain(new Date(at).toLocaleString())
		expect(html).not.toContain('The cron has not produced a successful run yet.')
		expect(html).not.toContain('is currently dormant')
	})

	it('says the cron never ran, and explains a dormant adapter', async () => {
		const never = await render(FxRatesAdmin, { loading: false, errorMessage: '', status: status({}) })
		expect(never).toContain('fx-rates-admin__status-badge--warn')
		expect(never).toContain('Never ran')
		expect(never).toContain('The cron has not produced a successful run yet.')

		const dormant = await render(FxRatesAdmin, { loading: false, errorMessage: 'Failed to load FX import status.', status: status({ status: 'dormant', adapterDormant: true }) })
		expect(dormant).toContain('fx-rates-admin__status-badge--dormant')
		expect(dormant).toContain('Dormant')
		expect(dormant).toContain('The Treasury rate adapter is currently dormant.')
		expect(dormant).toMatch(/role="alert"[^>]*>\s*Failed to load FX import status\./)
	})
})

describe('SegmentPnLDashboard', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	const state = (over) => ({ loading: false, errorMessage: '', activeSegment: 'costCenter', rows: [], analyticalDimensions: [], administrationId: 'adm-1', periodId: '', periods: [{ id: 'p1', label: '2026-01' }], ...over })
	const row = (key, over = {}) => ({ key, name: '', parent: '', revenue: 0, costs: 0, result: 0, depth: 0, ...over })

	it('renders the segment chips, the active one primary, and the periods', async () => {
		const html = await render(SegmentPnLDashboard, state({ activeSegment: 'project', rows: [row('P1', { revenue: 10, result: 10 })] }))
		for (const label of ['Cost center', 'Cost center (rolled up)', 'Cost object', 'Project', 'Analytical dimension']) {
			expect(html).toContain(label)
		}
		expect(html).toContain('All periods')
		expect(html).toMatch(/<option value="p1"[^>]*>\s*2026-01\s*</)
		expect(html).toContain('data-segment="project"')
		expect(html).toMatch(/<th scope="col"[^>]*>\s*Project\s*</)
	})

	it('renders the rows, names, negative results and totals', async () => {
		const html = await render(SegmentPnLDashboard, state({ rows: [row('CC1', { name: 'Zuivering', revenue: 100, costs: 40, result: 60 }), row('CC2', { costs: 25, result: -25 })] }))
		expect(html).toContain('Zuivering')
		expect(html).toContain('segment-pnl-dashboard__amount-cell--negative')
		expect(html).toContain('Total')
		expect(html).not.toContain('>Parent<')
		expect((html.match(/segment-pnl-dashboard__group-name/g) || []).length).toBe(1)
	})

	it('adds the parent column and indents children for the hierarchy', async () => {
		const html = await render(SegmentPnLDashboard, state({ activeSegment: 'costCenterHierarchy', rows: [row('A'), row('A1', { parent: 'A', depth: 1 })] }))
		expect(html).toMatch(/<th scope="col"[^>]*>\s*Parent\s*</)
		expect(html).toContain('segment-pnl-dashboard__row--child')
		expect(html).toContain('--row-depth:1')
	})

	it('renders the loading, empty and failed states', async () => {
		expect(await render(SegmentPnLDashboard, state({ loading: true }))).toContain('data-name="Loading segment P&amp;L"')
		const empty = await render(SegmentPnLDashboard, state({ errorMessage: 'Failed to load segment P&L.' }))
		expect(empty).toContain('No posted lines on profit and loss accounts carry this segment yet.')
		expect(empty).toContain('role="alert"')
		expect(empty).not.toContain('<table')
	})

	/**
	 * A dashboard instance with its computed values readable.
	 *
	 * @param {object} over The data to start from.
	 * @return {object} The instance.
	 */
	function dashboard(over = {}) {
		const self = { ...instance(SegmentPnLDashboard), ...over, t: (app, text) => text }
		for (const [name, method] of Object.entries(SegmentPnLDashboard.methods)) {
			self[name] = method.bind(self)
		}
		for (const [name, getter] of Object.entries(SegmentPnLDashboard.computed)) {
			Object.defineProperty(self, name, { get: getter.bind(self) })
		}
		return self
	}

	it('names the group column after the segment', () => {
		const labels = ['costCenter', 'costCenterHierarchy', 'costObject', 'project', 'analyticalDimension', 'other']
			.map((activeSegment) => dashboard({ activeSegment }).groupLabel)
		expect(labels).toEqual(['Cost center', 'Cost center (hierarchy)', 'Cost object', 'Project', 'Analytical dimension', 'Segment'])
		expect(dashboard({ activeSegment: 'costCenterHierarchy' }).hasHierarchy).toBe(true)
	})

	it('orders the hierarchy depth-first, roots first, orphans last', () => {
		const view = dashboard()
		const flat = [row('B1', { parent: 'B' }), row('A'), row('A1', { parent: 'A' }), row('A1x', { parent: 'A1' }), row('B', { parent: 'missing' }), row('C1', { parent: 'C2' }), row('C2', { parent: 'C1' })]

		const ordered = view.applyHierarchy(flat)

		expect(ordered.map((r) => [r.key, r.depth])).toEqual([['A', 0], ['A1', 1], ['A1x', 2], ['B', 0], ['B1', 1], ['C1', 0], ['C2', 0]])
		expect(view.normaliseRows({ groups: [] }, 'costCenterHierarchy')).toEqual([])
	})

	it('switches segment only to another one', () => {
		const view = dashboard()
		view.loadSegment = vi.fn()

		view.selectSegment('costCenter')
		expect(view.loadSegment).not.toHaveBeenCalled()
		view.selectSegment('project')
		expect(view.activeSegment).toBe('project')
		expect(view.loadSegment).toHaveBeenCalledWith('project')
	})

	it('words a failed load by its status', () => {
		const view = dashboard()
		const message = (status) => view.segmentLoadError({ response: { status } })
		expect(message(404)).toBe(message(501))
		expect(message(404)).toContain('Aggregation endpoint unavailable')
		expect(message(403)).toBe('Permission required to read segment P&L data.')
		expect(message(401)).toBe(message(403))
		expect(view.segmentLoadError(new Error('x'))).toBe('Failed to load segment P&L.')
	})

	it('refuses an unknown segment and reads the administration context', async () => {
		const view = dashboard()
		await view.loadSegment('nope')
		expect([view.errorMessage, view.loading]).toEqual(['Unknown segment selected.', false])
		expect(axios.get).not.toHaveBeenCalled()

		axios.get.mockResolvedValueOnce({ data: { administrations: [{ administrationId: 'adm-9' }] } })
		expect(await view.resolveAdministrationId()).toBe('adm-9')
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await view.resolveAdministrationId()).toBe('')
		axios.get.mockRejectedValueOnce(new Error('down'))
		expect(await view.resolveAdministrationId()).toBe('')
	})

	it('treats unreadable periods as none and formats non-numbers as zero', async () => {
		const view = dashboard({ periods: [{ id: 'old' }] })
		axios.get.mockRejectedValueOnce(new Error('down'))
		await view.loadPeriods()
		expect(view.periods).toEqual([])
		expect(view.formatAmount('n/a')).toBe(view.formatAmount(0))
	})
})
