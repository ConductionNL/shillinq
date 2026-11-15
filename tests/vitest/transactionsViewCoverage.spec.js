/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * TransactionsView's wiring: what each filter control writes and reloads,
 * what the create button and the table cells are bound to, and the load on
 * mount. The view is rendered server-side (no DOM); the stubbed NcAppContent
 * keeps the vnodes of its slot so their bound handlers can be called, as
 * the browser would on input.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-21
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString, ssrRenderSlot, ssrRenderVNode } from 'vue/server-renderer'

const captured = vi.hoisted(() => ({ content: [], push: false }))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({
	// Server-renders its slot as HTML in `push` mode; otherwise asks the slot
	// for its vnodes, keeps them, and renders those.
	NcAppContent: {
		name: 'NcAppContent',
		ssrRender(ctx, push, parent) {
			push('<main>')
			if (captured.push) {
				ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			} else {
				const nodes = ctx.$slots.default()
				captured.content.push(nodes)
				nodes.forEach((node) => ssrRenderVNode(push, node, parent))
			}
			push('</main>')
		},
	},
	NcButton: {
		name: 'NcButton',
		render() {
			return h('button', this.$slots.default?.())
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'emptyLabel'],
		render() {
			return h('table', this.rows.map((row) => h('tr', [
				h('td', this.$slots['cell-amount']({ row })),
				h('td', this.$slots['cell-status']({ row })),
			])))
		},
	},
}))

globalThis.t = (app, text) => text

const { default: TransactionsView } = await import('../../src/views/bookkeeping/TransactionsView.vue')

const URL = '/index.php/apps/shillinq/api/v1/transactions'

/**
 * Render the view with its data replaced by `state`.
 *
 * @param {object} state The data to render with.
 * @return {Promise<string>} The HTML.
 */
async function render(state) {
	const app = createSSRApp({ ...TransactionsView, data: () => state })
	app.config.globalProperties.t = globalThis.t
	return renderToString(app)
}

/**
 * Every vnode under `nodes`, depth first.
 *
 * @param {Array|object} nodes The vnodes.
 * @return {Array<object>} The flattened vnodes.
 */
function flatten(nodes) {
	const out = []
	for (const node of [].concat(nodes ?? [])) {
		if (!node || typeof node !== 'object') {
			continue
		}
		out.push(node)
		if (Array.isArray(node.children)) {
			out.push(...flatten(node.children))
		}
	}
	return out
}

/**
 * A fresh state for the view.
 *
 * @param {object} over Values to change.
 * @return {object} The state.
 */
function state(over = {}) {
	return {
		transactions: [],
		loading: false,
		errorMessage: '',
		canCreate: false,
		filters: { status: '', type: '', dateFrom: '', dateTo: '' },
		...over,
	}
}

describe('TransactionsView wiring', () => {
	beforeEach(() => {
		axios.get.mockReset()
		captured.content.length = 0
		captured.push = false
	})

	it('writes each filter into its field and reloads with it', async () => {
		axios.get.mockResolvedValue({ data: { transactions: [] } })
		const data = state()
		await render(data)

		const nodes = flatten(captured.content.at(-1))
		const selects = nodes.filter((node) => node.type === 'select')
		const inputs = nodes.filter((node) => node.type === 'input')
		expect(selects.map((node) => node.children.length)).toEqual([4, 6])
		expect(inputs.map((node) => node.props.type)).toEqual(['date', 'date'])

		selects[0].props['onUpdate:modelValue']('posted')
		selects[1].props['onUpdate:modelValue']('invoice')
		inputs[0].props['onUpdate:modelValue']('2026-01-01')
		inputs[1].props['onUpdate:modelValue']('2026-03-31')
		expect(data.filters).toEqual({ status: 'posted', type: 'invoice', dateFrom: '2026-01-01', dateTo: '2026-03-31' })

		await selects[0].props.onChange()
		expect(axios.get).toHaveBeenLastCalledWith(URL, { params: { status: 'posted', type: 'invoice', dateFrom: '2026-01-01', dateTo: '2026-03-31' } })
		for (const control of [selects[1], inputs[0], inputs[1]]) {
			await control.props.onChange()
		}
		expect(axios.get).toHaveBeenCalledTimes(4)
	})

	it('shows the create button only when allowed, wired to the create event', async () => {
		await render(state())
		expect(flatten(captured.content.at(-1)).some((node) => node.type?.name === 'NcButton')).toBe(false)

		const html = await render(state({ canCreate: true }))
		expect(html).toContain('Create Transaction')
		const button = flatten(captured.content.at(-1)).find((node) => node.type?.name === 'NcButton')
		expect(button.props.variant).toBe('primary')
		expect(button.props.onClick).toEqual(expect.any(Function))
		expect(button.children.default()[0].children).toBe('Create Transaction')
	})

	it('hands the rows to the table and fills the amount and status cells', async () => {
		const rows = [{ amount: 12.5, status: 'reversed' }, { amount: 'n/a', status: 'odd' }]
		const html = await render(state({ transactions: rows, errorMessage: 'Failed to load transactions.' }))

		const table = flatten(captured.content.at(-1)).find((node) => node.type?.name === 'CnDataTable')
		expect(table.props.rows).toEqual(rows)
		expect(table.props.emptyLabel).toBe('Create the first transaction to start posting to the books.')
		const amount = table.children['cell-amount']({ row: rows[0] })[0]
		const status = table.children['cell-status']({ row: rows[1] })[0]
		expect(amount.props.class).toBe('wbso-transactions__cell--amount')
		expect(amount.children).toMatch(/€\s?12,50/)
		expect([status.props['data-status'], status.children]).toEqual(['odd', 'odd'])

		const alert = flatten(captured.content.at(-1)).find((node) => node.props?.role === 'alert')
		expect(alert.children).toBe('Failed to load transactions.')
		expect(html).toContain('Reversed')
	})

	it('shows the loading text instead of the table while loading', async () => {
		await render(state({ loading: true }))
		const nodes = flatten(captured.content.at(-1))
		expect(nodes.find((node) => node.props?.class === 'wbso-transactions__loading').children).toBe('Loading…')
		expect(nodes.some((node) => node.type?.name === 'CnDataTable')).toBe(false)
	})

	it('server-renders the loading, create and failure markup', async () => {
		captured.push = true
		const loading = await render(state({ loading: true, canCreate: true }))
		expect(loading).toMatch(/<div class="wbso-transactions__loading"[^>]*>\s*Loading…\s*<\/div>/)
		expect(loading).toMatch(/<button[^>]*>\s*Create Transaction\s*<\/button>/)
		expect(loading).not.toContain('role="alert"')

		const failed = await render(state({ errorMessage: 'Failed to load transactions.' }))
		expect(failed).toMatch(/role="alert"[^>]*>\s*Failed to load transactions\.\s*<\/p>/)
		expect(failed).not.toContain('Create Transaction')
		expect(failed).not.toContain('wbso-transactions__loading')
		expect(captured.content).toEqual([])
	})

	it('loads the transactions when mounted', async () => {
		const view = { ...TransactionsView.data.call({}) }
		view.load = TransactionsView.methods.load.bind(view)
		axios.get.mockResolvedValue({ data: { ocs: { data: { transactions: [{ id: 1 }], canCreate: true } } } })

		TransactionsView.mounted.call(view)
		await vi.waitFor(() => expect(view.loading).toBe(false))

		expect(axios.get).toHaveBeenCalledWith(URL, { params: {} })
		expect([view.transactions, view.canCreate]).toEqual([[{ id: 1 }], true])
	})
})
