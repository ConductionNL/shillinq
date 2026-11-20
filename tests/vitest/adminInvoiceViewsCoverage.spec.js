/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The admin invoice list and detail views: loading through invoiceApi (and
 * the empty state when a read fails), posting and exporting a PDF, the
 * column set, the money format, and both templates rendered server-side with
 * stand-ins for the data table, the line-item review and router-link.
 * Only invoiceApi and the Nextcloud / library imports are faked.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const api = vi.hoisted(() => ({ get: vi.fn(), list: vi.fn(), post: vi.fn(), exportPdf: vi.fn() }))

vi.mock('../../src/api/invoiceApi.js', () => ({ default: api }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'emptyLabel'],
		setup(props, { slots }) {
			return () => h('table', { 'data-empty': props.emptyLabel, 'data-columns': props.columns.map((c) => c.key).join(',') },
				props.rows.map((row) => h('tr', [
					h('td', slots['cell-grossAmount']?.({ row })),
					h('td', slots['cell-actions']?.({ row })),
				])))
		},
	},
}))
vi.mock('../../src/components/invoice/InvoiceLineItemReview.vue', () => ({
	default: {
		name: 'InvoiceLineItemReview',
		props: ['lines', 'summary'],
		setup(props) {
			return () => h('div', { 'data-lines': props.lines.length, 'data-summary': props.summary?.invoiceNumber ?? '' })
		},
	},
}))

const { default: AdminInvoiceDetail } = await import('../../src/views/invoice/AdminInvoiceDetail.vue')
const { default: AdminInvoiceList } = await import('../../src/views/invoice/AdminInvoiceList.vue')

const RouterLink = {
	name: 'RouterLink',
	props: ['to'],
	setup(props, { slots }) {
		return () => h('a', { href: props.to }, slots.default?.())
	},
}

/**
 * A fake component instance: the component's data plus its methods bound to it.
 *
 * @param {object} component The component options.
 * @param {object} props Its props.
 * @return {object}
 */
function instance(component, props = {}) {
	const vm = { ...props, ...component.data() }
	for (const [name, fn] of Object.entries(component.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(component.computed ?? {})) {
		Object.defineProperty(vm, name, { get: fn.bind(vm) })
	}
	return vm
}

/**
 * Render a component server-side with its data overridden.
 *
 * @param {object} component The component options.
 * @param {object} props Its props.
 * @param {object} state Data to set before rendering.
 * @return {Promise<string>} The HTML.
 */
async function render(component, props, state) {
	const app = createSSRApp({ ...component, data() { return { ...component.data(), ...state } } }, props)
	app.component('router-link', RouterLink)
	// Drop the scoped-style attributes so assertions read the markup itself.
	return (await renderToString(app)).replace(/ data-v-[0-9a-f]+/g, '')
}

describe('AdminInvoiceDetail', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads the invoice, its lines and its audit trail from the detail envelope', async () => {
		api.get.mockResolvedValue({ invoice: { invoiceNumber: 'F-1' }, lines: [{ id: 1 }], auditTrail: [{ action: 'created' }] })
		const vm = instance(AdminInvoiceDetail, { invoiceId: 'inv 1' })

		await AdminInvoiceDetail.mounted.call(vm)

		expect(api.get).toHaveBeenCalledWith('inv 1')
		expect(vm.invoice).toEqual({ invoiceNumber: 'F-1' })
		expect(vm.lines).toEqual([{ id: 1 }])
		expect(vm.auditTrail).toEqual([{ action: 'created' }])
	})

	it('takes a bare invoice as the invoice, and keeps an empty page when nothing or an error comes back', async () => {
		const vm = instance(AdminInvoiceDetail, { invoiceId: 'i1' })
		api.get.mockResolvedValueOnce({ invoiceNumber: 'F-2' })
		await vm.reload()
		expect(vm.invoice).toEqual({ invoiceNumber: 'F-2' })
		expect(vm.lines).toEqual([])
		expect(vm.auditTrail).toEqual([])

		api.get.mockResolvedValueOnce(null)
		await vm.reload()
		expect(vm.invoice).toEqual({})

		vm.invoice = { invoiceNumber: 'kept' }
		api.get.mockRejectedValueOnce(new Error('404'))
		await vm.reload()
		expect(vm.invoice).toEqual({ invoiceNumber: 'kept' })
	})

	it('posts the invoice and reads it again, and exports its PDF', async () => {
		api.get.mockResolvedValue({ invoice: { status: 'posted' } })
		const vm = instance(AdminInvoiceDetail, { invoiceId: 'i1' })

		await vm.post()
		expect(api.post).toHaveBeenCalledWith('i1')
		expect(api.get).toHaveBeenCalledTimes(1)
		expect(vm.invoice).toEqual({ status: 'posted' })

		await vm.pdf()
		expect(api.exportPdf).toHaveBeenCalledWith('i1')
	})

	it('renders the meta list, the dashes for missing links, the audit trail and an enabled post for a draft', async () => {
		const html = await render(AdminInvoiceDetail, { invoiceId: 'i1' }, {
			invoice: { invoiceNumber: 'F-7', status: 'draft', posted: true, customerId: 'c-1' },
			lines: [{ id: 1 }, { id: 2 }],
			auditTrail: [{ timestamp: '2026-11-01', actor: 'anna', action: 'created' }],
		})

		expect(html).toContain('Invoice F-7')
		expect(html).toContain('href="/invoice"')
		expect(html).toContain('<dd>c-1</dd>')
		expect(html.match(/<dd>—<\/dd>/g)).toHaveLength(4)
		expect(html).toContain('(Posted)')
		expect(html).toContain('data-lines="2"')
		expect(html).toContain('data-summary="F-7"')
		expect(html).toContain('Audit trail')
		expect(html).toContain('<strong>2026-11-01</strong> — anna:')
		expect(html).not.toMatch(/<button[^>]*disabled[^>]*>\s*Post to AR/)
	})

	it('hides the audit trail when there is none and disables post for a posted invoice', async () => {
		const html = await render(AdminInvoiceDetail, { invoiceId: 'i1' }, {
			invoice: { status: 'posted', summary: { invoiceNumber: 'S-1' }, projectId: 'p-1' },
		})

		expect(html).not.toContain('Audit trail')
		expect(html).not.toContain('(Posted)')
		expect(html).toContain('<dd>p-1</dd>')
		expect(html).toContain('data-summary="S-1"')
		expect(html).toMatch(/<button[^>]*disabled[^>]*>\s*Post to AR/)
	})
})

describe('AdminInvoiceList', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists the invoices with the current filters, and none when the read fails', async () => {
		api.list.mockResolvedValueOnce([{ id: 'i1' }])
		const vm = instance(AdminInvoiceList)
		vm.filters.status = 'draft'

		await AdminInvoiceList.mounted.call(vm)
		expect(api.list).toHaveBeenCalledWith({ fromDate: '', toDate: '', billingModel: '', status: 'draft' })
		expect(vm.invoices).toEqual([{ id: 'i1' }])

		api.list.mockRejectedValueOnce(new Error('down'))
		await vm.reload()
		expect(vm.invoices).toEqual([])
	})

	it('posts a row and reloads, and exports a row\'s PDF', async () => {
		api.list.mockResolvedValue([{ id: 'i1', status: 'posted' }])
		const vm = instance(AdminInvoiceList)

		await vm.post({ id: 'i1' })
		expect(api.post).toHaveBeenCalledWith('i1')
		expect(vm.invoices).toEqual([{ id: 'i1', status: 'posted' }])

		await vm.pdf({ id: 'i2' })
		expect(api.exportPdf).toHaveBeenCalledWith('i2')
	})

	it('formats money the Dutch way with two decimals, empty as zero', () => {
		const vm = instance(AdminInvoiceList)

		expect(vm.formatMoney(1234.5)).toBe('1.234,50')
		expect(vm.formatMoney('7')).toBe('7,00')
		expect(vm.formatMoney(null)).toBe('0,00')
	})

	it('offers the columns in order with only actions unsortable', () => {
		const vm = instance(AdminInvoiceList)

		expect(vm.columns.map((column) => column.key)).toEqual(
			['invoiceNumber', 'invoiceDate', 'dueDate', 'customerId', 'billingModel', 'grossAmount', 'status', 'actions'],
		)
		expect(vm.columns.filter((column) => column.sortable === false).map((column) => column.key)).toEqual(['actions'])
		expect(vm.columns.find((column) => column.key === 'grossAmount').align).toBe('right')
	})

	it('renders the filters, the table and each row\'s amount and actions', async () => {
		const html = await render(AdminInvoiceList, {}, {
			invoices: [{ id: 'i1', grossAmount: 1500, status: 'draft' }, { id: 'i2', grossAmount: 20, status: 'posted' }],
		})

		expect(html).toContain('href="/invoice/generate"')
		expect(html).toContain('data-empty="No invoices found"')
		expect(html).toContain('data-columns="invoiceNumber,invoiceDate,dueDate,customerId,billingModel,grossAmount,status,actions"')
		expect(html).toContain('€ 1.500,00')
		expect(html).toContain('€ 20,00')
		expect(html).toContain('href="/invoice/i1"')
		expect(html).toContain('href="/invoice/i2"')
		expect(html.match(/<button[^>]*disabled[^>]*>\s*Post/g)).toHaveLength(1)
		expect(html).toContain('data-testid="admin-invoice-status-filter"')
	})
})
