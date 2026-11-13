/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for how a budget BBV mapping is saved and deleted: the payload
 * (optional fields only when set), create versus update, the error wording
 * the server gives, the delete dialog, the way back to the index, and the
 * selected account and programme summaries. Methods and computed run against
 * a fake instance; only HTTP, the dialogs and the router are replaced.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetBBVMappingDetail from '../../src/components/BudgetBBVMapping/BudgetBBVMappingDetail.vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) : text) }))

const VALID = { glAccountNumber: '4000', programmeCode: 'P01', allocationPercentage: '40', effectiveFrom: '2026-01-01', effectiveTo: '', status: 'active', administrationId: '' }
const URL = '/index.php/apps/openregister/api/objects/shillinq/BudgetBBVMapping'

/**
 * A fake detail instance.
 *
 * @param {object} props The props (id, administrationId).
 * @param {object} form  Form fields over VALID.
 * @return {object} The instance.
 */
function detail(props = { id: 'm-7' }, form = {}) {
	const vm = { administrationId: '', ...props, $router: { push: vi.fn() }, $emit: vi.fn() }
	for (const [name, fn] of Object.entries(BudgetBBVMappingDetail.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, BudgetBBVMappingDetail.data.call(vm))
	vm.form = { ...VALID, ...form }
	for (const [name, getter] of Object.entries(BudgetBBVMappingDetail.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	return vm
}

beforeEach(() => {
	vi.restoreAllMocks()
	showError.mockClear()
	showSuccess.mockClear()
	// The shared axios stub carries only get and post; this view also puts and deletes.
	axios.put = vi.fn()
	axios.delete = vi.fn()
})

afterEach(() => {
	delete axios.put
	delete axios.delete
})

describe('BudgetBBVMappingDetail payload', () => {
	it('sends the allocation as a number and leaves out an empty end date and administration', () => {
		expect(detail().buildPayload()).toEqual({ glAccountNumber: '4000', programmeCode: 'P01', allocationPercentage: 40, effectiveFrom: '2026-01-01', status: 'active' })
	})

	it('sends the end date and administration when they are set, and active when no status is', () => {
		const payload = detail({ id: 'm-7' }, { effectiveTo: '2026-12-31', administrationId: 'adm-1', status: '' }).buildPayload()

		expect(payload.effectiveTo).toBe('2026-12-31')
		expect(payload.administrationId).toBe('adm-1')
		expect(payload.status).toBe('active')
	})
})

describe('BudgetBBVMappingDetail save', () => {
	it('creates a new mapping and returns to the index', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const vm = detail({ id: 'new' })

		await vm.onSave()

		expect(post).toHaveBeenCalledWith(URL, expect.objectContaining({ glAccountNumber: '4000', allocationPercentage: 40 }))
		expect(showSuccess).toHaveBeenCalledWith('Mapping created.')
		expect(vm.$router.push).toHaveBeenCalledWith({ name: 'BudgetBBVMappings' })
		expect(vm.saving).toBe(false)
	})

	it('updates an existing mapping by its id', async () => {
		const put = axios.put.mockResolvedValue({ data: {} })
		const vm = detail({ id: 'm-7' })

		await vm.onSave()

		expect(put).toHaveBeenCalledWith(`${URL}/m-7`, expect.objectContaining({ programmeCode: 'P01' }))
		expect(showSuccess).toHaveBeenCalledWith('Mapping saved.')
	})

	it('does not save a mapping that is not savable', async () => {
		const put = axios.put
		const vm = detail({ id: 'm-7' }, { glAccountNumber: '' })

		await vm.onSave()

		expect(put).not.toHaveBeenCalled()
		expect(vm.saving).toBe(false)
	})

	it.each([
		['the server\'s error', { response: { data: { error: 'Allocation over 100 %' } } }, 'Allocation over 100 %'],
		['the server\'s message', { response: { data: { message: 'Validation failed' } } }, 'Validation failed'],
		['the exception\'s message', new Error('Network Error'), 'Network Error'],
		['its own sentence', {}, 'Failed to save mapping.'],
	])('shows %s when the save is refused, and stays on the page', async (name, error, expected) => {
		axios.put.mockRejectedValue(error)
		const vm = detail({ id: 'm-7' })

		await vm.onSave()

		expect(vm.saveError).toBe(expected)
		expect(showError).toHaveBeenCalledWith(expected)
		expect(vm.$router.push).not.toHaveBeenCalled()
		expect(vm.saving).toBe(false)
	})
})

describe('BudgetBBVMappingDetail delete', () => {
	it('opens the dialog only for an existing mapping', () => {
		const created = detail({ id: 'new' })
		created.openDeleteDialog()
		expect(created.deleteDialogOpen).toBe(false)

		const existing = detail({ id: 'm-7' })
		existing.openDeleteDialog()
		expect(existing.deleteDialogOpen).toBe(true)
		existing.closeDeleteDialog()
		expect(existing.deleteDialogOpen).toBe(false)
	})

	it('deletes the mapping, closes the dialog and returns to the index', async () => {
		const del = axios.delete.mockResolvedValue({ data: {} })
		const vm = detail({ id: 'm-7' })
		vm.deleteDialogOpen = true

		await vm.onDelete()

		expect(del).toHaveBeenCalledWith(`${URL}/m-7`)
		expect(showSuccess).toHaveBeenCalledWith('Mapping deleted.')
		expect(vm.deleteDialogOpen).toBe(false)
		expect(vm.$router.push).toHaveBeenCalledWith({ name: 'BudgetBBVMappings' })
		expect(vm.deleting).toBe(false)
	})

	it('deletes nothing on the create route', async () => {
		const del = axios.delete
		const vm = detail({ id: 'new' })
		vm.deleteDialogOpen = true

		await vm.onDelete()

		expect(del).not.toHaveBeenCalled()
		expect(vm.deleteDialogOpen).toBe(false)
	})

	it('keeps the dialog open and says why when the delete is refused', async () => {
		axios.delete.mockRejectedValueOnce({ response: { data: { message: 'In use by a budget line' } } }).mockRejectedValueOnce({})
		const vm = detail({ id: 'm-7' })
		vm.deleteDialogOpen = true

		await vm.onDelete()
		expect(showError).toHaveBeenLastCalledWith('In use by a budget line')
		await vm.onDelete()
		expect(showError).toHaveBeenLastCalledWith('Failed to delete mapping.')

		expect(vm.deleteDialogOpen).toBe(true)
		expect(vm.$router.push).not.toHaveBeenCalled()
		expect(vm.deleting).toBe(false)
	})
})

describe('BudgetBBVMappingDetail navigation and summaries', () => {
	it('falls back to a navigate event without a router, or when the router throws', () => {
		const vm = detail()
		vm.$router = undefined
		vm.onCancel()
		expect(vm.$emit).toHaveBeenCalledWith('navigate', { name: 'BudgetBBVMappings' })

		const throwing = detail()
		throwing.$router.push.mockImplementation(() => {
			throw new Error('no route')
		})
		throwing.returnToIndex()
		expect(throwing.$emit).toHaveBeenCalledWith('navigate', { name: 'BudgetBBVMappings' })
	})

	it('summarises the chosen account with its balance in whole euros', () => {
		const vm = detail()
		expect(vm.glAccountSummary).toBe('')

		vm.onGlAccountSelected({ accountName: 'Personeel', accountType: 'expense', balance: 1234500, administrationId: 'adm-9' })

		expect(vm.glAccountSummary).toBe(`Personeel · expense · ${vm.formatEuro(1234500)}`)
		expect(vm.formatEuro(1234500)).toMatch(/12\.345/)
		expect(vm.formatEuro('n/a')).toBe('')
	})

	it('fills an empty administration from the chosen account or programme, never over one already set', () => {
		vi.useFakeTimers()
		const vm = detail({ id: 'm-7' }, { administrationId: '' })
		vm.onGlAccountSelected({ name: 'Rente', administrationId: 'adm-1' })
		vm.onProgrammeSelected({ programmeCode: 'P02', programmeName: 'Veiligheid', administrationId: 'adm-2' })

		expect(vm.form.administrationId).toBe('adm-1')
		expect(vm.glAccountSummary).toBe('Rente')
		expect(vm.programmeSummary).toBe('P02 · Veiligheid')

		vm.onProgrammeSelected(null)
		expect(vm.programmeSummary).toBe('')
		clearTimeout(vm.allocationCheckTimer)
		vi.useRealTimers()
	})

	it('titles the page by what it shows', () => {
		expect(detail({ id: 'new' }).pageTitle).toBe('New Budget Mapping')
		expect(detail({ id: 'm-7' }).pageTitle).toBe('GL 4000 → Programme P01')
		expect(detail({ id: 'm-7' }, { programmeCode: '' }).pageTitle).toBe('Budget Mapping')
		expect(detail({ id: 'new' }).saveLabel).toBe('Create')
		expect(detail({ id: 'm-7' }).saveLabel).toBe('Save')
		const saving = detail()
		saving.saving = true
		expect(saving.saveLabel).toBe('Saving…')
	})
})
