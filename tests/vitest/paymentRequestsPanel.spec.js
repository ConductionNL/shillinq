/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the payment-request panel (REQ-SOPR-004, REQ-SOPR-008): it
 * reads the requests on its host object, shows the published fee, sends the
 * payment link, records a payment made another way, and words a refusal the
 * way the server does. Methods and computed run against a fake instance, as
 * the other panel specs do; only HTTP is replaced.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShillinqPaymentRequestsPanel from '../../src/integrations/ShillinqPaymentRequestsPanel.vue'
import { formatAmount } from '../../src/integrations/leafApi.js'

const HOST = { register: 'dossiq', schema: 'case', objectId: 'c-1' }

/**
 * A fake panel instance: its data, its own and its mixins' methods, and its
 * computed as getters.
 *
 * @param {object} props The props.
 * @return {object} The instance.
 */
function panel(props = HOST) {
	const component = ShillinqPaymentRequestsPanel
	const vm = { $props: { integrationContext: {}, ...props }, ...props, onHeader: null, onSummary: null }
	Object.assign(vm, component.data.call(vm))
	const parts = [...component.mixins, component]
	for (const part of parts) {
		for (const [name, fn] of Object.entries(part.methods || {})) {
			vm[name] = fn.bind(vm)
		}
		for (const [name, getter] of Object.entries(part.computed || {})) {
			Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
		}
	}
	return vm
}

const requestP1 = { id: 'p-1', amount: 85, currency: 'EUR', state: 'outstanding', dueAt: '2026-12-01T00:00:00Z', description: 'Leges' }

describe('payment requests panel', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads the requests and the published fee off the host object', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [requestP1], fee: { amount: 25, currency: 'EUR' } } })
		const vm = panel()

		await vm.load()

		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/dossiq/case/c-1/integrations/shillinq-payment-requests')
		expect(vm.requests).toEqual([requestP1])
		expect(vm.loading).toBe(false)
		expect(vm.error).toBe('')
		expect(vm.feeLine).toBe(`Published fee for this record: ${formatAmount(25, 'EUR')}`)
	})

	it('treats an envelope without items as no requests and no fee', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: 'nope' } })
		const vm = panel()

		await vm.load()

		expect(vm.requests).toEqual([])
		expect(vm.fee).toBeNull()
		expect(vm.feeLine).toBe('')
	})

	it('reads nothing without a host and says it needs one', async () => {
		const get = vi.spyOn(axios, 'get')
		const vm = panel({ register: 'dossiq', schema: '', objectId: 'c-1' })

		await vm.load()

		expect(get).not.toHaveBeenCalled()
		expect(vm.error).toBe('This panel needs a record to stand on.')
	})

	it('says the requests could not be read when the read fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
		const vm = panel()

		await vm.load()

		expect(vm.error).toBe('The payment requests could not be read.')
		expect(vm.loading).toBe(false)
	})

	it('shows no fee line for a fee without a usable amount', () => {
		const vm = panel()
		vm.fee = { amount: 'n/a', currency: 'EUR' }
		expect(vm.feeLine).toBe('')
		vm.fee = 'twenty'
		expect(vm.feeLine).toBe('')
	})

	it('reports the counted-in state over the stored one, and the due date', () => {
		const vm = panel()

		expect(vm.stateOf({ state: 'outstanding', reported: { state: 'paid' } })).toBe('paid')
		expect(vm.stateOf({ state: 'outstanding', reported: {} })).toBe('outstanding')
		expect(vm.stateOf({})).toBe('')
		expect(vm.dueLine(requestP1)).toBe('Due 2026-12-01')
		expect(vm.dueLine({})).toBe('')
		expect(vm.amountOf(requestP1)).toBe(formatAmount(85, 'EUR'))
	})

	it('reports one row per request to a card, amount and state joined', () => {
		const vm = panel()
		vm.requests = [requestP1, { id: 'p-2', state: 'cancelled' }]

		const report = vm.leafReport

		expect(report.count).toBe(2)
		expect(report.emptyLabel).toBe('Nobody has been asked to pay anything on this record yet.')
		expect(report.rows).toEqual([
			{ label: `${formatAmount(85, 'EUR')} · outstanding`, icon: 'CashMultiple' },
			{ label: 'cancelled', icon: 'CashMultiple' },
		])
	})

	it('sends the payment link and says it went out', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { sent: true } })
		const vm = panel()

		await vm.send(requestP1)

		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/payment-requests/p-1/send', {})
		expect(vm.notices['p-1']).toBe('The payment link went out to the debtor.')
		expect(vm.busyId).toBe('')
	})

	it('shows the server\'s words when sending is refused', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { error: 'You may not administer payments.' } } })
		const vm = panel()

		await vm.send(requestP1)

		expect(vm.notices['p-1']).toBe('You may not administer payments.')
		expect(vm.busyId).toBe('')
	})

	it('opens a fresh settlement form and closes it again', () => {
		const vm = panel()
		vm.settleForm = { method: 'cash', settlementReference: 'x', reason: 'y' }

		vm.openSettle(requestP1)
		expect(vm.settleFor).toBe('p-1')
		expect(vm.settleForm).toEqual({ method: 'pin', settlementReference: '', reason: '' })

		vm.closeSettle()
		expect(vm.settleFor).toBe('')
	})

	it('records the payment for the whole request, closes the form and reads again', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [{ ...requestP1, state: 'paid' }] } })
		const vm = panel()
		vm.openSettle(requestP1)
		vm.settleForm.method = 'waived'
		vm.settleForm.reason = 'Hardheidsclausule'

		await vm.settle(requestP1)

		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/payment-requests/p-1/settle', {
			method: 'waived',
			settlementReference: '',
			amount: 0,
			reason: 'Hardheidsclausule',
		})
		expect(vm.settleFor).toBe('')
		expect(vm.notices['p-1']).toBe('The payment is on the record.')
		expect(get).toHaveBeenCalledTimes(1)
		expect(vm.requests[0].state).toBe('paid')
		expect(vm.busyId).toBe('')
	})

	it('keeps the form open and gives a plain refusal when the server names no reason', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('network'))
		const get = vi.spyOn(axios, 'get')
		const vm = panel()
		vm.openSettle(requestP1)

		await vm.settle(requestP1)

		expect(vm.settleFor).toBe('p-1')
		expect(vm.notices['p-1']).toBe('That did not go through.')
		expect(get).not.toHaveBeenCalled()
		expect(vm.busyId).toBe('')
	})

	it('offers the five ways money can arrive, pin first', () => {
		expect(panel().settlementMethods.map((option) => option.value)).toEqual(['pin', 'cash', 'bank-transfer', 'waived', 'other'])
	})
})
