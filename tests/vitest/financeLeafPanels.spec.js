/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the contract and payment requests leaf panels
 * (src/integrations/ShillinqContractPanel.vue, ShillinqPaymentRequestsPanel.vue):
 * what each template draws in each state, rendered server-side so the node
 * environment needs no DOM, and the payment request actions, run against a
 * fake component instance with the HTTP client mocked.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import ShillinqContractPanel from '../../src/integrations/ShillinqContractPanel.vue'
import ShillinqPaymentRequestsPanel from '../../src/integrations/ShillinqPaymentRequestsPanel.vue'
import { leafHostPanelMixin } from '../../src/integrations/leafHostPanel.js'

/**
 * Render a panel with its data replaced by `state`.
 *
 * @param {object} panel The panel component.
 * @param {object} state The data the panel renders.
 * @param {object} props The props it is given.
 * @return {Promise<string>} The HTML.
 */
async function render(panel, state, props = {}) {
	const base = panel.data()
	return renderToString(createSSRApp({ ...panel, data: () => ({ ...base, ...state }) }, props))
}

const CONTRACT = {
	id: 'c1',
	title: 'Onderhoud groen',
	status: 'active',
	needsAttention: true,
	counterpartyReference: 'Groen BV',
	startDate: '2026-01-01T00:00:00Z',
	endDate: '2026-12-31',
	remainingValue: 1250.5,
	currency: 'EUR',
	incurredCostComplete: false,
}

describe('ShillinqContractPanel — what it draws', () => {
	it('draws a contract with its party, term, remaining value and the incomplete notice', async () => {
		const html = await render(ShillinqContractPanel, { contracts: [CONTRACT] })

		expect(html).toContain('data-testid="shillinq-contract-c1"')
		expect(html).toContain('Onderhoud groen')
		expect(html).toContain('shillinq-leaf__state--attention')
		expect(html).toContain('With Groen BV')
		expect(html).toContain('Runs from 2026-01-01 until 2026-12-31')
		expect(html).toContain('Remaining value')
		expect(html).toContain('a floor, not a total')
	})

	it('leaves out the lines a contract has nothing for', async () => {
		const html = await render(ShillinqContractPanel, {
			contracts: [{ id: 'c2', contractNumber: 'K-7', status: 'draft', remainingValue: null }],
		})

		expect(html).toContain('K-7')
		expect(html).not.toContain('With ')
		expect(html).not.toContain('Runs ')
		expect(html).not.toContain('shillinq-leaf__state--attention')
		expect(html).toContain('The remaining value has not been rolled up yet.')
		expect(html).not.toContain('a floor, not a total')
	})

	it('says there is no contract when the list is empty', async () => {
		const html = await render(ShillinqContractPanel, { contracts: [] })

		expect(html).toContain('data-testid="shillinq-contract-empty"')
		expect(html).toContain('This record is not handled under a contract.')
	})

	it('drops its own empty line inside a host card', async () => {
		const html = await render(ShillinqContractPanel, { contracts: [] }, { onHeader: () => {} })

		expect(html).toContain('data-testid="shillinq-contract-panel"')
		expect(html).not.toContain('shillinq-contract-empty')
	})

	it('shows the error instead of the list', async () => {
		const html = await render(ShillinqContractPanel, { contracts: [CONTRACT], error: 'The contract could not be read.' })

		expect(html).toContain('role="alert"')
		expect(html).toContain('The contract could not be read.')
		expect(html).not.toContain('shillinq-contract-c1')
	})

	it('draws nothing as a summary in a linked card', async () => {
		const html = await render(ShillinqContractPanel, { contracts: [CONTRACT] }, { onSummary: () => {} })

		expect(html).not.toContain('shillinq-contract-panel')
	})
})

const REQUEST = {
	id: 'r1',
	amount: 45,
	currency: 'EUR',
	state: 'open',
	description: 'Leges omgevingsvergunning',
	dueAt: '2026-12-01T00:00:00Z',
}

describe('ShillinqPaymentRequestsPanel — what it draws', () => {
	it('draws a request with its state, description, due date and its actions', async () => {
		const html = await render(ShillinqPaymentRequestsPanel, {
			requests: [{ ...REQUEST, reported: { state: 'paid' } }],
		})

		expect(html).toContain('data-testid="shillinq-payment-request-r1"')
		expect(html).toContain('paid')
		expect(html).toContain('Leges omgevingsvergunning')
		expect(html).toContain('Due 2026-12-01')
		expect(html).toContain('shillinq-leaf__actions')
		expect(html).not.toContain('shillinq-leaf__settle')
	})

	it('shows the published fee and the empty line when nothing was asked', async () => {
		const html = await render(ShillinqPaymentRequestsPanel, { requests: [], fee: { amount: 12, currency: 'EUR' } })

		expect(html).toContain('Published fee for this record:')
		expect(html).toContain('data-testid="shillinq-payment-requests-empty"')
	})

	it('opens the settle form, with every settlement method, for the request it was opened on', async () => {
		const html = await render(ShillinqPaymentRequestsPanel, {
			requests: [REQUEST, { id: 'r2', amount: 10, state: 'open' }],
			settleFor: 'r1',
			notices: { r2: 'The payment link went out to the debtor.' },
		})

		expect(html).toContain('id="shillinq-settle-method-r1"')
		expect(html).not.toContain('id="shillinq-settle-method-r2"')
		for (const method of ['pin', 'cash', 'bank-transfer', 'waived', 'other']) {
			expect(html).toContain(`value="${method}"`)
		}
		expect(html).toContain('How the money arrived')
		expect(html).toContain('placeholder="What the bank statement will show"')
		expect(html).toContain('The payment link went out to the debtor.')
	})

	it('shows the error instead of the requests', async () => {
		const html = await render(ShillinqPaymentRequestsPanel, { requests: [REQUEST], error: 'Nope' })

		expect(html).toContain('Nope')
		expect(html).not.toContain('shillinq-payment-request-r1')
	})
})

describe('ShillinqPaymentRequestsPanel — the actions', () => {
	const methods = ShillinqPaymentRequestsPanel.methods

	/**
	 * A fake panel instance carrying the panel's own data.
	 *
	 * @return {object} The instance.
	 */
	function instance() {
		return {
			...ShillinqPaymentRequestsPanel.data(),
			refusal: leafHostPanelMixin.methods.refusal,
			load: vi.fn(async () => {}),
		}
	}

	beforeEach(() => {
		axios.post = vi.fn(async () => ({ data: {} }))
	})

	it('sends the link and says it went out', async () => {
		const vm = instance()

		await methods.send.call(vm, { id: 'r1' })

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post.mock.calls[0][0]).toContain('/payment-requests/r1/send')
		expect(vm.notices.r1).toBe('The payment link went out to the debtor.')
		expect(vm.busyId).toBe('')
	})

	it('shows the server words when sending is refused', async () => {
		axios.post = vi.fn(async () => {
			throw { response: { data: { error: 'No debtor e-mail on file.' } } }
		})
		const vm = instance()

		await methods.send.call(vm, { id: 'r1' })

		expect(vm.notices.r1).toBe('No debtor e-mail on file.')
		expect(vm.busyId).toBe('')
	})

	it('opens the settle form with a fresh pin settlement, and closes it', () => {
		const vm = instance()
		vm.settleForm = { method: 'cash', settlementReference: 'x', reason: 'y' }

		methods.openSettle.call(vm, { id: 'r1' })
		expect(vm.settleFor).toBe('r1')
		expect(vm.settleForm).toEqual({ method: 'pin', settlementReference: '', reason: '' })

		methods.closeSettle.call(vm)
		expect(vm.settleFor).toBe('')
	})

	it('records the payment with the form values, closes the form and reloads', async () => {
		const vm = instance()
		vm.settleFor = 'r1'
		vm.settleForm = { method: 'waived', settlementReference: 'REF-1', reason: 'Hardship' }

		await methods.settle.call(vm, { id: 'r1' })

		expect(axios.post.mock.calls[0][0]).toContain('/payment-requests/r1/settle')
		expect(axios.post.mock.calls[0][1]).toEqual({
			method: 'waived',
			settlementReference: 'REF-1',
			amount: 0,
			reason: 'Hardship',
		})
		expect(vm.settleFor).toBe('')
		expect(vm.notices.r1).toBe('The payment is on the record.')
		expect(vm.load).toHaveBeenCalledTimes(1)
		expect(vm.busyId).toBe('')
	})

	it('keeps the form open and says why when settling is refused', async () => {
		axios.post = vi.fn(async () => {
			throw new Error('network')
		})
		const vm = instance()
		vm.settleFor = 'r1'

		await methods.settle.call(vm, { id: 'r1' })

		expect(vm.settleFor).toBe('r1')
		expect(vm.notices.r1).toBe('That did not go through.')
		expect(vm.load).not.toHaveBeenCalled()
	})
})
