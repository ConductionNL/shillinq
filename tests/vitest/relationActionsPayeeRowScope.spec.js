/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The supplier side of Relations both ways: the linked customer's page URL,
 * the "open linked customer" header action on a supplier (from the row or
 * from the page URL), what it says when there is no link or the lookup fails,
 * and the export header action.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	get: vi.fn(),
	showError: vi.fn(),
	showInfo: vi.fn(),
	spawnDialog: vi.fn(() => 'dialog-result'),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (m, k) => String(params[k])),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/dialogs', () => ({ showError: mocks.showError, showInfo: mocks.showInfo, showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: mocks.spawnDialog }))
vi.mock('../../src/modals/RelationsExportDialog.vue', () => ({ default: { name: 'RelationsExportDialog' } }))
vi.mock('@nextcloud/axios', () => ({ default: { get: mocks.get, post: vi.fn() } }))

const { linkedCustomerUrl, openPayeeRelation, openRelationsExport } = await import('../../src/utils/relationActions.js')

let assign

beforeEach(() => {
	mocks.get.mockReset()
	mocks.showError.mockReset()
	mocks.showInfo.mockReset()
	assign = vi.fn()
	vi.stubGlobal('window', { location: { pathname: '/apps/shillinq/bookkeeping/payees/payee-from-url', assign } })
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('linkedCustomerUrl', () => {
	it('asks both sides of the supplier and answers the customer page', async () => {
		mocks.get.mockResolvedValueOnce({ data: { customer: { id: 'cust-7' } } })

		expect(await linkedCustomerUrl('payee-1')).toBe('/apps/shillinq/bookkeeping/customers/cust-7')
		expect(mocks.get).toHaveBeenCalledWith('/apps/shillinq/api/relations/payee/payee-1/both-sides')
	})

	it.each([
		['no customer', { customer: null }],
		['a customer without id', { customer: {} }],
		['no body', null],
	])('answers null for %s', async (name, data) => {
		mocks.get.mockResolvedValueOnce({ data })

		expect(await linkedCustomerUrl('payee-1')).toBeNull()
	})
})

describe('openPayeeRelation', () => {
	it('opens the linked customer of the row\'s supplier', async () => {
		mocks.get.mockResolvedValueOnce({ data: { customer: { id: 'cust-7' } } })

		expect(await openPayeeRelation({ item: { id: 'payee-1' } })).toBe(true)
		expect(mocks.get).toHaveBeenCalledWith('/apps/shillinq/api/relations/payee/payee-1/both-sides')
		expect(assign).toHaveBeenCalledWith('/apps/shillinq/bookkeeping/customers/cust-7')
	})

	it('reads the supplier from the page URL without a row', async () => {
		mocks.get.mockResolvedValueOnce({ data: { customer: { id: 'cust-8' } } })

		expect(await openPayeeRelation(undefined)).toBe(true)
		expect(mocks.get).toHaveBeenCalledWith('/apps/shillinq/api/relations/payee/payee-from-url/both-sides')
	})

	it('says the supplier is not linked yet and opens nothing', async () => {
		mocks.get.mockResolvedValueOnce({ data: { customer: null } })

		expect(await openPayeeRelation({ item: { id: 'payee-1' } })).toBe(false)
		expect(mocks.showInfo).toHaveBeenCalledWith('This supplier is not linked to a customer yet. Link it from the customer page or from Relations both ways.')
		expect(assign).not.toHaveBeenCalled()
	})

	it('shows the server\'s reason when the lookup fails', async () => {
		mocks.get.mockRejectedValueOnce({ response: { data: { error: 'Supplier not found' } } })

		expect(await openPayeeRelation({ item: { id: 'payee-x' } })).toBe(false)
		expect(mocks.showError).toHaveBeenCalledTimes(1)
		expect(mocks.showError.mock.calls[0][0]).toBe('Supplier not found')
		expect(assign).not.toHaveBeenCalled()
	})

	it('falls back to its own words when the failure carries none', async () => {
		mocks.get.mockRejectedValueOnce(new Error('Network Error'))

		expect(await openPayeeRelation({ item: { id: 'payee-x' } })).toBe(false)
		expect(mocks.showError).toHaveBeenCalledWith('The linked customer could not be found.')
	})
})

describe('openRelationsExport', () => {
	it('opens the export dialog and hands its result back', () => {
		expect(openRelationsExport()).toBe('dialog-result')
		expect(mocks.spawnDialog).toHaveBeenCalledWith({ name: 'RelationsExportDialog' }, { open: true })
	})
})
