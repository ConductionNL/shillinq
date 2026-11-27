/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The financial documents and contract leaf panels' buttons, wired to what
 * they do. The real template is rendered with a button stand-in that keeps
 * each button's click handler; firing it shows the top act opening its form,
 * each row act reaching its method with its own row, and the two Cancel
 * buttons closing their form. Mounting either panel reads its host object.
 */

import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

globalThis.t = (_app, value, vars = {}) => value.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

const { default: Panel } = await import('../../src/integrations/ShillinqFinancialDocumentsPanel.vue')
const { default: ContractPanel } = await import('../../src/integrations/ShillinqContractPanel.vue')

const ALL_GRANTS = { issueInvoice: true, requestPayment: true, administerPayments: true, createQuote: true, creditInvoice: true, raisePayout: true }

const ROWS = [
	{ id: 'i1', kind: 'invoice', number: 'F-2026-001', amount: 125, paymentState: 'outstanding', path: '/apps/shillinq/invoices/i1' },
	{ id: 'p1', kind: 'payment-request', amount: 40, currency: 'EUR', paymentState: 'outstanding' },
]

/**
 * Render the panel with recording buttons and the methods replaced by spies.
 *
 * @param {object} data Preset data.
 * @return {Promise<{click: Function, spies: object, vm: object}>} A click by testid or label, the spies and the instance.
 */
async function rendered (data) {
	const buttons = []
	const recording = {
		name: 'NcButton',
		inheritAttrs: false,
		setup (_props, { slots, attrs }) {
			buttons.push({ attrs, label: () => String(slots.default?.()?.[0]?.children ?? '').trim() })
			return () => h('cn-stub', { 'data-name': 'NcButton' }, slots.default?.())
		},
	}
	const spies = { openForm: vi.fn(), requestPayment: vi.fn(), credit: vi.fn(), send: vi.fn(), openSettle: vi.fn() }
	const box = {}
	const subject = {
		...Panel,
		mixins: [...(Panel.mixins || []), { created () { box.vm = this } }],
		methods: { ...Panel.methods, ...spies },
	}

	await renderTemplate(subject, { data, components: { NcButton: recording, NcLoadingIcon: standIn('NcLoadingIcon') } })

	const click = (key) => {
		const button = buttons.find((b) => b.attrs['data-testid'] === key || b.label() === key)
		expect(button, `a button "${key}"`).toBeDefined()
		button.attrs.onClick()
	}
	return { click, spies, vm: box.vm }
}

describe('ShillinqFinancialDocumentsPanel buttons', () => {
	it('opens the form of the top act that was pressed', async () => {
		const { click, spies } = await rendered({ acts: ALL_GRANTS })

		click('shillinq-issue-invoice')
		click('shillinq-raise-payout')

		expect(spies.openForm.mock.calls).toEqual([['issue-invoice'], ['raise-payout']])
	})

	it('hands each row act its own row', async () => {
		const { click, spies } = await rendered({ rows: ROWS, acts: ALL_GRANTS })

		click('shillinq-request-payment-i1')
		click('shillinq-credit-invoice-i1')
		click('shillinq-send-link-p1')
		click('shillinq-settle-p1')

		expect(spies.requestPayment).toHaveBeenCalledWith(ROWS[0])
		expect(spies.credit).toHaveBeenCalledWith(ROWS[0])
		expect(spies.send).toHaveBeenCalledWith(ROWS[1])
		expect(spies.openSettle).toHaveBeenCalledWith(ROWS[1])
		expect(spies.send).toHaveBeenCalledTimes(1)
	})

	it('closes the open form when its Cancel is pressed', async () => {
		const { click, vm } = await rendered({ acts: ALL_GRANTS, formFor: 'issue-invoice' })

		expect(vm.formFor).toBe('issue-invoice')
		click('Cancel')

		expect(vm.formFor).toBe('')
	})

	it('closes the settle form when its Cancel is pressed', async () => {
		const { click, vm } = await rendered({ rows: ROWS, acts: ALL_GRANTS, settleFor: 'p1' })

		click('Cancel')

		expect(vm.settleFor).toBe('')
	})
})

describe('finance leaf panels on mount', () => {
	it.each([
		['the financial documents panel', Panel],
		['the contract panel', ContractPanel],
	])('%s reads its host object', (_name, component) => {
		const load = vi.fn()

		component.mounted.call({ load })

		expect(load).toHaveBeenCalledTimes(1)
	})
})
