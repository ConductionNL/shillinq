/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The framework agreement picker on the purchase-order form (REQ-PG-005):
 * the supplier's active agreements are offered, a line for a product on the
 * picked agreement shows the agreed price, the form shows what is left under
 * the ceiling, refuses an order past it, and sends frameworkAgreementId.
 *
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/procurement-governance/spec.md (REQ-PG-005)
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name, tag = 'div') => ({
		name,
		props: ['label', 'inputLabel', 'disabled', 'modelValue', 'options'],
		render() {
			return h(tag, {
				'data-stub': name,
				'data-label': this.label || this.inputLabel,
				'data-options': this.options ? JSON.stringify(this.options) : undefined,
				disabled: this.disabled || undefined,
			}, this.$slots.default?.())
		},
	})
	return {
		NcButton: stub('NcButton', 'button'),
		NcInputField: stub('NcInputField'),
		NcSelect: stub('NcSelect'),
		NcTextArea: stub('NcTextArea'),
		NcTextField: stub('NcTextField'),
	}
})

const t = (_app, text) => text
const { default: PurchaseOrderForm } = await import('../../src/components/purchase-order/PurchaseOrderForm.vue')

/** The paper agreement of the REQ-PG-005 scenario: EUR 42 a box, EUR 10,000 left. */
const paper = {
	id: 'fa-uuid-1',
	agreementNumber: 'FA-2026-1',
	title: 'Office paper',
	supplierId: 's1',
	statusCode: 'active',
	ceilingAmount: 1000000,
	drawnAmount: 0,
	agreedPrices: [{ productCode: 'PAPER', unitAmount: 4200 }, { productCode: 'BROKEN', unitAmount: 'n/a' }, null],
}

/**
 * Render the form with its data replaced by `state` and its load hooks off.
 *
 * @param {object} state The data to render with.
 * @return {Promise<string>} The HTML.
 */
async function render(state) {
	const app = createSSRApp({ ...PurchaseOrderForm, created: undefined, watch: {}, data() { return { ...PurchaseOrderForm.data.call(this), ...state } } })
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * A stand-in instance of the form for calling its methods.
 *
 * @param {object} state Fields to set.
 * @return {object} The instance.
 */
function form(state = {}) {
	const self = { t, pushed: [], $router: { push: (to) => self.pushed.push(to) } }
	for (const [name, method] of Object.entries(PurchaseOrderForm.methods)) {
		self[name] = method.bind(self)
	}
	Object.assign(self, PurchaseOrderForm.data.call(self), state)
	for (const name of Object.keys(PurchaseOrderForm.computed)) {
		Object.defineProperty(self, name, { get: () => PurchaseOrderForm.computed[name].call(self) })
	}
	return self
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('PurchaseOrderForm agreement picker', () => {
	it('loads the supplier\'s active agreements of the administration', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [paper, { id: 'fa-2' }] } })
		const self = form({ administrationId: 'a1', supplierId: 's1' })
		await self.loadAgreements()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/shillinq/FrameworkAgreement', { params: { administrationId: 'a1', supplierId: 's1', statusCode: 'active' } })
		expect(self.agreementOptions).toEqual([
			{ value: 'FA-2026-1', label: 'Office paper (FA-2026-1)' },
			{ value: 'fa-2', label: 'fa-2' },
		])
		expect(self.agreementsError).toBe('')
	})

	it('offers nothing without administration or supplier, and drops a pick no longer on offer', async () => {
		const self = form({ administrationId: 'a1', supplierId: '', agreements: [paper], frameworkAgreementId: 'FA-2026-1' })
		await self.loadAgreements()
		expect(axios.get).not.toHaveBeenCalled()
		expect([self.agreements, self.frameworkAgreementId]).toEqual([[], ''])

		const other = form({ administrationId: 'a1', supplierId: 's2', agreements: [paper], frameworkAgreementId: 'FA-2026-1' })
		axios.get.mockResolvedValueOnce({ data: { results: [] } })
		await other.loadAgreements()
		expect(other.frameworkAgreementId).toBe('')

		const kept = form({ administrationId: 'a1', supplierId: 's1', frameworkAgreementId: 'FA-2026-1' })
		axios.get.mockResolvedValueOnce({ data: { results: [paper] } })
		await kept.loadAgreements()
		expect(kept.frameworkAgreementId).toBe('FA-2026-1')
	})

	it('shows an inline error when the agreements cannot be read', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const self = form({ administrationId: 'a1', supplierId: 's1', agreements: [paper], frameworkAgreementId: 'FA-2026-1' })
		await self.loadAgreements()
		expect([self.agreements, self.frameworkAgreementId, self.agreementsError]).toEqual([[], '', 'Failed to load framework agreements'])
	})

	it('prices a line on the agreement at the agreed price: 20 boxes at EUR 42 leave EUR 9,160', () => {
		const self = form({
			agreements: [paper],
			frameworkAgreementId: 'FA-2026-1',
			lines: [{ productCode: ' PAPER ', quantity: 20, unitPrice: 55 }],
		})
		expect(self.agreedPrice(self.lines[0])).toBe(42)
		expect(self.total).toBe(840)
		expect(self.ceilingLeft).toBe(9160)

		self.lines.push({ productCode: 'BROKEN', quantity: 1, unitPrice: 10 }, { productCode: '', quantity: 1, unitPrice: 5 })
		expect(self.agreedPrice(self.lines[1])).toBeNull()
		expect(self.agreedPrice(self.lines[2])).toBeNull()
		expect(self.total).toBe(855)

		self.frameworkAgreementId = null
		expect(self.agreedPrice(self.lines[0])).toBeNull()
		expect(self.ceilingLeft).toBeNull()
		expect(self.total).toBe(1115)
	})

	it('refuses an order past the ceiling and sends frameworkAgreementId otherwise', async () => {
		const base = { administrationId: 'a1', supplierId: 's1', costCenter: 'cc', agreements: [paper], frameworkAgreementId: 'FA-2026-1' }
		const over = form({ ...base, lines: [{ productCode: 'PAPER', quantity: 300, unitPrice: 0 }] })
		await over.onSubmit()
		expect(over.error).toBe('This order exceeds the ceiling of the framework agreement')
		expect(axios.post).not.toHaveBeenCalled()

		const lines = [{ productCode: 'PAPER', quantity: 20, unitPrice: 0 }]
		const self = form({ ...base, lines })
		axios.post.mockResolvedValueOnce({ data: { poNumber: 'PO-9' } })
		await self.onSubmit()
		expect(axios.post).toHaveBeenCalledWith('/apps/shillinq/api/purchase-orders', { administrationId: 'a1', supplierId: 's1', costCenter: 'cc', projectCode: '', currency: 'EUR', notes: '', lines, frameworkAgreementId: 'FA-2026-1' })
		expect(self.pushed).toEqual([{ name: 'PurchaseOrderDetail', params: { id: 'PO-9' } }])
	})
})

describe('PurchaseOrderForm agreement rendering', () => {
	it('labels the picker, shows the agreed price and the ceiling left', async () => {
		const html = await render({
			agreements: [paper],
			frameworkAgreementId: 'FA-2026-1',
			lines: [{ productCode: 'PAPER', quantity: 20, unitPrice: 0, vatRate: 0.21, glAccount: '4000' }],
		})
		expect(html).toMatch(/data-label="Framework agreement \(optional\)"[^>]*data-options="[^"]*FA-2026-1/)
		expect(html).toMatch(/data-testid="po-form-agreed-price-0"[^>]*>\s*EUR 42.00/)
		expect(html).toContain('Agreed price')
		expect(html).toMatch(/data-testid="po-form-ceiling-left"[\s\S]*<strong[^>]*>EUR 9160.00<\/strong>/)
	})

	it('disables the empty picker, prices by hand and shows the load error', async () => {
		const html = await render({
			agreementsError: 'Failed to load framework agreements',
			lines: [{ productCode: 'PAPER', quantity: 1, unitPrice: 5, vatRate: 0.21, glAccount: '4000' }],
		})
		expect(html).toMatch(/data-label="Framework agreement \(optional\)"[^>]*disabled/)
		expect(html).not.toContain('po-form-agreed-price-0')
		expect(html).not.toContain('po-form-ceiling-left')
		expect(html).toMatch(/data-testid="po-form-agreement-error"[^>]*>\s*Failed to load framework agreements/)
	})
})
