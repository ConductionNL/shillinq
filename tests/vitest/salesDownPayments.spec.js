/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * sales-down-payments: the New down-payment invoice action and the down
 * payments panel reach shillinq. The manifest, the handler map in main.js,
 * the registry, the helpers' URLs and appinfo/routes.php are held to each
 * other, and the request the dialog sends is the one DownPaymentController reads.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const peppolFragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json'),
		'utf8',
	),
)
const mainJs = fs.readFileSync(path.join(ROOT, 'src/main.js'), 'utf8')
const registryJs = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(
	path.join(ROOT, 'lib/Controller/DownPaymentController.php'),
	'utf8',
)

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(() => Promise.resolve(null)),
}))
vi.mock('../../src/modals/DownPaymentInvoiceModal.vue', () => ({
	default: { name: 'DownPaymentInvoiceModal' },
}))

const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

describe('New down-payment invoice', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('is a header action on Accounts Receivable whose handler main.js provides', async () => {
		const ar = manifest.pages.find((p) => p.id === 'AccountsReceivable')
		const action = ar.config.headerActions.find(
			(a) => a.id === 'new-down-payment-invoice',
		)
		expect(action.label).toBe('New down-payment invoice')
		expect(action.handler).toBe('openDownPaymentInvoice')
		expect(mainJs).toContain('openDownPaymentInvoice')

		const { openDownPaymentInvoice } =
			await import('../../src/utils/downPaymentActions.js')
		const { spawnDialog } = await import('@nextcloud/vue/functions/dialog')
		await openDownPaymentInvoice({ actionId: 'new-down-payment-invoice' })
		expect(spawnDialog).toHaveBeenCalledWith(
			expect.objectContaining({ name: 'DownPaymentInvoiceModal' }),
			{},
		)
	})

	it('sends the fields DownPaymentController reads, to a declared route', async () => {
		const { buildRaiseRequest, raiseDownPayment } =
			await import('../../src/utils/downPaymentApi.js')
		const request = buildRaiseRequest({
			administrationId: 'adm-kvl',
			customerId: 'cust-1',
			order: {
				value: 'order-117',
				label: 'Keuken Eiland 2026-117',
				shillinq: true,
			},
			mode: 'percentage',
			value: '30',
			invoiceDate: '2026-06-01',
			rates: { 21: '', 9: '', 0: '' },
		})
		expect(request).toEqual({
			administrationId: 'adm-kvl',
			customerId: 'cust-1',
			orderReference: 'order-117',
			orderLabel: 'Keuken Eiland 2026-117',
			percentage: 30,
			invoiceDate: '2026-06-01',
		})
		for (const field of Object.keys(request)) {
			expect(controller).toContain(`'${field}'`)
		}

		axiosMock.post.mockResolvedValue({ data: { id: 'ar-dp' } })
		await raiseDownPayment(request)
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/ar-invoices/down-payments',
			request,
		)
		expect(routes).toContain(
			"'url' => '/api/ar-invoices/down-payments', 'verb' => 'POST'",
		)
	})

	it('sends the net per VAT rate for an order shillinq cannot read', async () => {
		const { buildRaiseRequest } =
			await import('../../src/utils/downPaymentApi.js')
		const request = buildRaiseRequest({
			administrationId: 'adm-kvl',
			customerId: 'cust-1',
			order: { value: 'Offerte 88', label: 'Offerte 88', shillinq: false },
			mode: 'amount',
			value: '1000,50',
			invoiceDate: '2026-06-01',
			rates: { 21: '3000', 9: '1000', 0: '' },
		})
		expect(request.amount).toBe(1000.5)
		expect(request.percentage).toBeUndefined()
		expect(request.orderVatBreakdown).toEqual([
			{ rate: 0.21, net: 3000 },
			{ rate: 0.09, net: 1000 },
		])
	})
})

describe('down payments panel', () => {
	it('is a custom widget on the invoice page, mounted through a registered slot', () => {
		const detail = peppolFragment.pages.find((p) => p.id === 'ARInvoiceDetail')
		const widget = detail.config.widgets.find(
			(w) => w.id === 'invoice-down-payments',
		)
		expect(widget.type).toBe('custom')
		expect(
			detail.config.layout.some((l) => l.widgetId === 'invoice-down-payments'),
		).toBe(true)
		expect(detail.slots['widget-invoice-down-payments']).toBe(
			'ArDownPaymentPanel',
		)
		expect(registryJs).toMatch(
			/kind: 'widget',\s*component: ArDownPaymentPanel,/,
		)
	})

	it('reads and deducts through declared routes', async () => {
		const { loadDownPayments, deductDownPayments } =
			await import('../../src/utils/downPaymentApi.js')
		axiosMock.get.mockResolvedValue({ data: { position: [] } })
		axiosMock.post.mockResolvedValue({ data: { id: 'ar-kitchen' } })

		await loadDownPayments('ar-kitchen')
		await deductDownPayments('ar-kitchen', 'order-117')

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/ar-invoices/ar-kitchen/down-payments',
		)
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/ar-invoices/ar-kitchen/down-payment-deductions',
			{ orderReference: 'order-117' },
		)
		expect(routes).toContain(
			"'url' => '/api/ar-invoices/{id}/down-payments', 'verb' => 'GET'",
		)
		expect(routes).toContain(
			"'url' => '/api/ar-invoices/{id}/down-payment-deductions', 'verb' => 'POST'",
		)
	})
})
