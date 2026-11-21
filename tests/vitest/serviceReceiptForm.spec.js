/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ServiceReceiptForm: it reads the order's service lines, and one submit
 * creates the receipt, adds a line per entry, confirms it and opens it
 * (purchasing-order-dispatch-and-receipt, task 4.2).
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import Detail from '../../src/components/purchase-order/PurchaseOrderDetail.vue'
import Form from '../../src/components/service-receipt/ServiceReceiptForm.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const RECEIPTS = '/index.php/apps/shillinq/api/service-receipts'

/**
 * A stand-in instance with a component's data, methods and computed.
 *
 * @param {object} component The component options.
 * @param {object} [fields] Fields to override.
 * @return {object} The instance.
 */
function instance(component, fields = {}) {
	const self = { t: (_app, text) => text, $router: { push: vi.fn() }, ...component.data(), ...fields }
	for (const [name, fn] of Object.entries(component.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(component.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('loading the order', () => {
	it('keeps only the service lines, each starting on quantity', async () => {
		http.get.mockImplementation((url) => Promise.resolve({
			data: url.endsWith('/PurchaseOrder/po-32')
				? { id: 'po-32', poNumber: 'PO-2026-032', administrationId: 'adm-1' }
				: { results: [
					{ id: 'pol-1', description: 'Advies', quantityOrdered: 40, unitOfMeasure: 'HUR' },
					{ id: 'pol-2', description: 'Folders', quantityOrdered: 5000, unitOfMeasure: 'EA' },
				] },
		}))
		const self = instance(Form)

		await self.load('po-32')

		expect(http.get).toHaveBeenCalledWith(`${OBJECTS}/PurchaseOrderLine`, { params: { filter: { poId: 'po-32' } } })
		expect(self.purchaseOrder.poNumber).toBe('PO-2026-032')
		expect(self.entries).toEqual([
			{ poLineId: 'pol-1', description: 'Advies', quantityOrdered: 40, unitOfMeasure: 'HUR', mode: 'quantityConfirmed', value: '' },
		])
		expect(self.loading).toBe(false)
	})

	it('says so when no order is named', async () => {
		const self = instance(Form)

		await self.load(undefined)

		expect(http.get).not.toHaveBeenCalled()
		expect(self.error).toBe('Failed to load purchase order')
	})
})

describe('confirming the service', () => {
	it('creates the receipt, adds the entered line, confirms it and opens it', async () => {
		http.post.mockImplementation((url) => Promise.resolve({ data: url === RECEIPTS ? { id: 'sr-1' } : {} }))
		const self = instance(Form, {
			purchaseOrder: { id: 'po-32', administrationId: 'adm-1', costCenter: 'FAC' },
			periodStart: '2026-09-01',
			periodEnd: '2026-09-30',
			entries: [
				{ poLineId: 'pol-1', mode: 'quantityConfirmed', value: '25' },
				{ poLineId: 'pol-3', mode: 'quantityConfirmed', value: '' },
			],
		})

		await self.submit()

		expect(http.post.mock.calls).toEqual([
			[RECEIPTS, { administrationId: 'adm-1', poIds: ['po-32'], periodStart: '2026-09-01', periodEnd: '2026-09-30', notes: '', costCenter: 'FAC', projectCode: '' }],
			[`${RECEIPTS}/sr-1/lines`, { administrationId: 'adm-1', poLineId: 'pol-1', quantityConfirmed: 25, periodStart: '2026-09-01', periodEnd: '2026-09-30' }],
			[`${RECEIPTS}/sr-1/confirm`, { administrationId: 'adm-1' }],
		])
		expect(self.$router.push).toHaveBeenCalledWith({ name: 'ServiceReceiptDetail', params: { id: 'sr-1' } })
		expect(self.submitting).toBe(false)
	})

	it('sends nothing without an entered line', async () => {
		const self = instance(Form, { purchaseOrder: { id: 'po-32' }, entries: [{ poLineId: 'pol-1', mode: 'quantityConfirmed', value: '' }] })

		await self.submit()

		expect(http.post).not.toHaveBeenCalled()
	})

	it('shows the server refusal and stays on the form', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'Purchase order line not found' } } })
		const self = instance(Form, { purchaseOrder: { id: 'po-32', administrationId: 'adm-1' }, entries: [{ poLineId: 'pol-1', mode: 'percentageComplete', value: 50 }] })

		await self.submit()

		expect(self.error).toBe('Purchase order line not found')
		expect(self.$router.push).not.toHaveBeenCalled()
	})
})

describe('confirm service on the order page', () => {
	it('is offered only for an order with a service line, and opens the form for it', () => {
		const goods = instance(Detail, { id: 'po-31', purchaseOrder: { lines: [{ unitOfMeasure: 'EA' }] } })
		const services = instance(Detail, { id: 'po-32', purchaseOrder: { lines: [{ unitOfMeasure: 'HUR' }] } })

		expect(goods.hasServiceLines).toBe(false)
		expect(services.hasServiceLines).toBe(true)

		services.onConfirmService()
		expect(services.$router.push).toHaveBeenCalledWith({ name: 'ServiceReceiptForm', query: { poId: 'po-32' } })
	})
})
