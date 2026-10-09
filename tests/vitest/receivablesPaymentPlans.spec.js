/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * receivables-payment-plans: Agree a payment plan, the plan page's actions
 * and the Payment plan tab of Match by hand reach shillinq. The manifest,
 * the registry, the helpers' URLs and appinfo/routes.php are held to each
 * other, and the request the dialog sends is the one PaymentPlanController
 * and PaymentPlanService read.
 *
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/specs/bookkeeping-credit-control-dunning/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const fragment = JSON.parse(read('src/manifest.d/receivables-payment-plans.json'))
const manifest = JSON.parse(read('src/manifest.json'))
const menuLayout = JSON.parse(read('src/menu-layout.json'))
const registryJs = read('src/registry.js')
const routes = read('appinfo/routes.php')
const service = read('lib/PaymentPlan/PaymentPlanService.php')
const matchModal = read('src/modals/BankLineMatchModal.vue')

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const page = (pages, id) => pages.find((p) => p.id === id)

describe('Agree a payment plan', () => {
	it('is a header action on the customer and on an overdue invoice, opening a registered modal', () => {
		const customer = page(manifest.pages, 'CustomerDetail').config
			.headerActions[0]
		const invoice = page(manifest.pages, 'ARInvoiceDetail').config
			.headerActions[0]
		expect(customer.target).toBe('AgreePaymentPlanModal')
		expect(customer.props.from).toBe('customer')
		expect(invoice.props.from).toBe('invoice')
		expect(invoice.visibleWhen).toEqual({
			field: 'lifecycleState',
			op: 'eq',
			value: 'overdue',
		})
		expect(registryJs).toContain(
			"AgreePaymentPlanModal: { kind: 'modal', component: AgreePaymentPlanModal }",
		)
	})

	it('shows the customer plans on the customer page, with a layout cell', () => {
		const config = page(manifest.pages, 'CustomerDetail').config
		expect(config.widgets.map((w) => w.id)).toContain('customer-payment-plans')
		expect(config.layout.map((c) => c.widgetId)).toContain(
			'customer-payment-plans',
		)
	})

	it('sends the fields PaymentPlanService::draft reads', async () => {
		const { buildPlanRequest } =
			await import('../../src/utils/paymentPlanApi.js')
		const body = buildPlanRequest({
			administrationId: 'adm-hoekstra',
			customerId: 'cust-zwaan',
			invoiceIds: ['ar-0231', 'ar-0266'],
			mode: 'count',
			instalmentCount: '6',
			frequency: 'monthly',
			firstDueDate: '2026-11-01',
			graceDays: '14',
		})
		expect(body.instalmentCount).toBe(6)
		expect(body.instalmentAmount).toBeNull()
		for (const key of Object.keys(body)) {
			expect(service).toContain(`'${key}'`)
		}
	})

	it('offers only overdue invoices with an amount due', async () => {
		const { isPlannable } = await import('../../src/utils/paymentPlanApi.js')
		expect(
			isPlannable({ lifecycleState: 'overdue', amountDue: 605 }, '2026-10-15'),
		).toBe(true)
		expect(
			isPlannable(
				{ lifecycleState: 'issued', dueDate: '2026-10-01', amountDue: 5 },
				'2026-10-15',
			),
		).toBe(true)
		expect(
			isPlannable(
				{ lifecycleState: 'issued', dueDate: '2026-10-31', amountDue: 400 },
				'2026-10-15',
			),
		).toBe(false)
		expect(
			isPlannable({ lifecycleState: 'paid', amountDue: 0 }, '2026-10-15'),
		).toBe(false)
	})
})

describe('Payment plans page', () => {
	it('is in the Sales menu with a Due this month filter', () => {
		expect(fragment.menu[0].route).toBe('PaymentPlans')
		expect(menuLayout.relocations.PaymentPlans).toBe('Sales')
		const due = page(fragment.pages, 'PaymentPlans').config.quickFilters.find(
			(f) => f.label === 'Due this month',
		)
		expect(due.filter).toEqual({
			lifecycleState: 'active',
			instalmentThisMonth: true,
		})
		expect(service).toContain("'instalmentThisMonth'")
	})

	it('has activate, record a payment and cancel actions on a registered modal', () => {
		const actions = page(fragment.pages, 'PaymentPlanDetail').config
			.headerActions
		expect(actions.map((a) => a.props.mode)).toEqual([
			'activate',
			'settle',
			'cancel',
			'cancel',
		])
		// One cancel per state: visibleWhen has no "in" operator (gate 53).
		expect(actions.slice(2).map((a) => a.visibleWhen)).toEqual([
			{ field: 'lifecycleState', op: 'eq', value: 'draft' },
			{ field: 'lifecycleState', op: 'eq', value: 'active' },
		])
		actions.forEach((a) => expect(a.target).toBe('PaymentPlanActionModal'))
		expect(registryJs).toContain(
			"PaymentPlanActionModal: { kind: 'modal', component: PaymentPlanActionModal }",
		)
	})
})

describe('Payment plan endpoints', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('call routes the app declares', async () => {
		const api = await import('../../src/utils/paymentPlanApi.js')
		axiosMock.post.mockResolvedValue({ data: {} })
		axiosMock.get.mockResolvedValue({ data: { candidates: [] } })
		await api.activatePlan('plan-7')
		await api.settlePlan('plan-7', { amount: 403.33, paidDate: '2026-11-01' })
		await api.cancelPlan('plan-7', 'withdrawn')
		await api.payPlanFromLine('plan-7', 'line-1')
		await api.planCandidates('line-1')
		const urls = [...axiosMock.post.mock.calls, ...axiosMock.get.mock.calls].map(
			(c) => c[0],
		)
		expect(urls).toEqual([
			'/index.php/apps/shillinq/api/v1/payment-plans/plan-7/activate',
			'/index.php/apps/shillinq/api/v1/payment-plans/plan-7/settle',
			'/index.php/apps/shillinq/api/v1/payment-plans/plan-7/cancel',
			'/index.php/apps/shillinq/api/v1/payment-plans/plan-7/bank-line',
			'/index.php/apps/shillinq/api/v1/bank-lines/line-1/payment-plans',
		])
		for (const route of [
			'/payment-plans/{id}/activate',
			'/payment-plans/{id}/settle',
			'/payment-plans/{id}/cancel',
			'/payment-plans/{id}/bank-line',
			'/bank-lines/{lineId}/payment-plans',
			"'/api/v1/payment-plans'",
		]) {
			expect(routes).toContain(route)
		}
	})

	it('Match by hand has a Payment plan tab for money in', () => {
		expect(matchModal).toContain("tab === 'plan'")
		expect(matchModal).toContain('payPlanFromLine(this.planId, this.line.id)')
	})
})
