/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The account picker and the allocation index never list another
 * administration's records: the picker falls back to the administration the
 * caller works in, and both refuse to fetch when there is none.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
const axiosMock = { get: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {} }))
vi.mock('../../src/store/modules/budgetBBVMappingStore.js', () => ({
	useBudgetBBVMappingStore: () => ({}),
}))

const { default: GlAccountPicker } = await import(
	'../../src/components/BudgetBBVMapping/GlAccountPicker.vue'
)
const { default: BudgetBBVMappingIndex } = await import(
	'../../src/components/BudgetBBVMapping/BudgetBBVMappingIndex.vue'
)

/**
 * A picker instance stand-in for calling its methods.
 *
 * @param {string} administrationId The prop.
 * @return {object} The instance.
 */
function picker(administrationId) {
	return {
		administrationId,
		modelValue: '',
		accounts: [],
		fetchError: '',
		loading: false,
		t: (app, text) => text,
		$emit: vi.fn(),
	}
}

describe('administration-scoped account picker', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('asks for the accounts of the given administration', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: [] } })
		await GlAccountPicker.methods.fetchAccounts.call(picker('adm-2'))

		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get.mock.calls[0][1].params.administrationId).toBe('adm-2')
	})

	it('falls back to the active administration on a new mapping', async () => {
		axiosMock.get.mockImplementation((url) => Promise.resolve(
			url.endsWith('/api/administrations/context')
				? { data: { activeAdministrationId: 'adm-1' } }
				: { data: { results: [{ accountNumber: '4000' }] } },
		))
		const instance = picker('')
		await GlAccountPicker.methods.fetchAccounts.call(instance)

		const accountCall = axiosMock.get.mock.calls.find(([url]) => url.includes('/Account'))
		expect(accountCall[1].params.administrationId).toBe('adm-1')
		expect(instance.accounts).toHaveLength(1)
	})

	it('lists nothing when no administration is active', async () => {
		axiosMock.get.mockResolvedValue({ data: { activeAdministrationId: null } })
		const instance = picker('')
		await GlAccountPicker.methods.fetchAccounts.call(instance)

		expect(axiosMock.get.mock.calls.some(([url]) => url.includes('/Account'))).toBe(false)
		expect(instance.accounts).toEqual([])
		expect(instance.fetchError).not.toBe('')
	})
})

describe('administration-scoped allocation index', () => {
	it('fetches the allocations of the scoped administration only', async () => {
		const store = { fetchCollection: vi.fn().mockResolvedValue({ results: [] }), pagination: {}, errors: {} }
		const instance = { store, scope: { administrationId: 'adm-1' }, t: (app, text) => text }
		await BudgetBBVMappingIndex.methods.loadMappings.call(instance)

		expect(store.fetchCollection.mock.calls[0][1].administrationId).toBe('adm-1')
	})

	it('fetches nothing without an administration', async () => {
		const store = { fetchCollection: vi.fn(), pagination: {}, errors: {} }
		const instance = { store, scope: { administrationId: null }, t: (app, text) => text }
		await BudgetBBVMappingIndex.methods.loadMappings.call(instance)
		await BudgetBBVMappingIndex.methods.onPageChange.call(instance, 2)

		expect(store.fetchCollection).not.toHaveBeenCalled()
		expect(instance.objects).toEqual([])
	})
})
