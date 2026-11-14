/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the contract panel (REQ-FPCR-005, REQ-FPCR-006): it reads the
 * contracts its host object is handled under, and words the title, the
 * counterparty, the term and the remaining value, saying an absent remaining
 * value in words instead of as 0,00. Methods and computed run against a fake
 * instance; only HTTP is replaced.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShillinqContractPanel from '../../src/integrations/ShillinqContractPanel.vue'
import { formatAmount } from '../../src/integrations/leafApi.js'

/**
 * A fake panel instance: its data, its own and its mixins' methods, and its
 * computed as getters.
 *
 * @param {object} props The props.
 * @return {object} The instance.
 */
function panel(props = {}) {
	const component = ShillinqContractPanel
	const vm = { $props: { register: '', schema: '', objectId: '', integrationContext: {}, ...props }, onHeader: null, onSummary: null }
	Object.assign(vm, component.data.call(vm))
	for (const part of [...component.mixins, component]) {
		for (const [name, fn] of Object.entries(part.methods || {})) {
			vm[name] = fn.bind(vm)
		}
		for (const [name, getter] of Object.entries(part.computed || {})) {
			Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
		}
	}
	return vm
}

describe('contract panel', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads the contracts through the host given in the integration context', async () => {
		const contract = { id: 'k-1', title: 'Groenonderhoud' }
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [contract] } })
		const vm = panel({ integrationContext: { register: 'dossiq', schema: 'case', objectId: 7 } })

		await vm.load()

		expect(get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/dossiq/case/7/integrations/shillinq-contracts')
		expect(vm.contracts).toEqual([contract])
		expect(vm.error).toBe('')
		expect(vm.loading).toBe(false)
	})

	it('treats an envelope without items as no contracts', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const vm = panel({ register: 'dossiq', schema: 'case', objectId: 'c-1' })

		await vm.load()

		expect(vm.contracts).toEqual([])
		expect(vm.leafReport.count).toBe(0)
		expect(vm.leafReport.emptyLabel).toBe('This record is not handled under a contract.')
	})

	it('says the contract could not be read when the read fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('403'))
		const vm = panel({ register: 'dossiq', schema: 'case', objectId: 'c-1' })

		await vm.load()

		expect(vm.error).toBe('The contract could not be read.')
		expect(vm.loading).toBe(false)
	})

	it('reads nothing without a host', async () => {
		const get = vi.spyOn(axios, 'get')
		const vm = panel()

		await vm.load()

		expect(get).not.toHaveBeenCalled()
		expect(vm.error).toBe('This panel needs a record to stand on.')
	})

	it('titles a contract by title, then number, then the word', () => {
		const vm = panel()

		expect(vm.titleOf({ title: 'Groenonderhoud', contractNumber: 'C-9' })).toBe('Groenonderhoud')
		expect(vm.titleOf({ contractNumber: 'C-9' })).toBe('C-9')
		expect(vm.titleOf({})).toBe('Contract')
		vm.contracts = [{ contractNumber: 'C-9' }]
		expect(vm.leafReport.rows).toEqual([{ label: 'C-9', icon: 'FileDocumentOutline' }])
	})

	it('names the counterparty', () => {
		expect(panel().counterpartyLine({ counterpartyReference: 'Groen BV' })).toBe('With Groen BV')
	})

	it('words the term by the dates it has', () => {
		const vm = panel()

		expect(vm.termLine({})).toBe('')
		expect(vm.termLine({ startDate: '2026-01-01T00:00:00Z' })).toBe('Runs from 2026-01-01')
		expect(vm.termLine({ endDate: '2027-12-31' })).toBe('Runs until 2027-12-31')
		expect(vm.termLine({ startDate: '2026-01-01', endDate: '2027-12-31T23:59:59Z' })).toBe('Runs from 2026-01-01 until 2027-12-31')
	})

	it('says an absent remaining value in words, and formats a zero one', () => {
		const vm = panel()

		expect(vm.remainingLine({ remainingValue: null })).toBe('The remaining value has not been rolled up yet.')
		expect(vm.remainingLine({})).toBe('The remaining value has not been rolled up yet.')
		expect(vm.remainingLine({ remainingValue: 0, currency: 'EUR' })).toBe(`Remaining value ${formatAmount(0, 'EUR')}`)
		expect(vm.remainingLine({ remainingValue: 1250.5 })).toBe(`Remaining value ${formatAmount(1250.5, 'EUR')}`)
		expect(vm.incompleteLabel).toContain('the remaining value is a floor, not a total')
	})
})
