/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The supplier side of Relations both ways (src/utils/relationActions.js):
 * the linked customer's page found from a supplier, the header action that
 * opens it or says the supplier is not linked yet, and the export dialog.
 * Only HTTP, the toasts, the dialog spawner and the location are replaced.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showInfo: vi.fn(), showSuccess: vi.fn() }))
const dialog = vi.hoisted(() => ({ spawnDialog: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, vars = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (all, key) => encodeURIComponent(vars[key])),
}))
vi.mock('@nextcloud/vue/functions/dialog', () => dialog)
vi.mock('../../src/modals/RelationsExportDialog.vue', () => ({ default: { name: 'RelationsExportDialog' } }))

const { linkedCustomerUrl, openPayeeRelation, openRelationsExport } = await import('../../src/utils/relationActions.js')
const { default: RelationsExportDialog } = await import('../../src/modals/RelationsExportDialog.vue')

let assign

beforeEach(() => {
	vi.clearAllMocks()
	assign = vi.fn()
	vi.stubGlobal('window', { location: { pathname: '/apps/shillinq/bookkeeping/payees/payee-from-url', assign } })
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('linkedCustomerUrl', () => {
	it('answers the linked customer page of a supplier', async () => {
		http.get.mockResolvedValue({ data: { customer: { id: 'cust 1' } } })

		await expect(linkedCustomerUrl('payee-1')).resolves.toBe('/index.php/apps/shillinq/bookkeeping/customers/cust%201')
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/relations/payee/payee-1/both-sides')
	})

	it.each([
		['no customer', { customer: null }],
		['a customer without id', { customer: {} }],
		['no body', null],
	])('answers null for %s', async (label, data) => {
		http.get.mockResolvedValue({ data })
		await expect(linkedCustomerUrl('payee-1')).resolves.toBeNull()
	})
})

describe('openPayeeRelation', () => {
	it('opens the linked customer of the supplier on the page', async () => {
		http.get.mockResolvedValue({ data: { customer: { id: 'cust-2' } } })

		await expect(openPayeeRelation({ item: { id: 'payee-2' } })).resolves.toBe(true)

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/relations/payee/payee-2/both-sides')
		expect(assign).toHaveBeenCalledWith('/index.php/apps/shillinq/bookkeeping/customers/cust-2')
		expect(toast.showInfo).not.toHaveBeenCalled()
	})

	it('takes the supplier from the address when the scope has no item', async () => {
		http.get.mockResolvedValue({ data: { customer: { id: 'cust-3' } } })

		await expect(openPayeeRelation(undefined)).resolves.toBe(true)

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/relations/payee/payee-from-url/both-sides')
	})

	it('says the supplier is not linked yet and goes nowhere', async () => {
		http.get.mockResolvedValue({ data: {} })

		await expect(openPayeeRelation({ item: { id: 'payee-4' } })).resolves.toBe(false)

		expect(toast.showInfo).toHaveBeenCalledWith('This supplier is not linked to a customer yet. Link it from the customer page or from Relations both ways.')
		expect(assign).not.toHaveBeenCalled()
	})

	it('shows the server reason, or a fallback, when the lookup fails', async () => {
		http.get.mockRejectedValueOnce({ response: { data: { error: 'Geen toegang' } } })
		await expect(openPayeeRelation({ item: { id: 'payee-5' } })).resolves.toBe(false)
		expect(toast.showError).toHaveBeenLastCalledWith('Geen toegang')

		http.get.mockRejectedValueOnce(new Error('offline'))
		await expect(openPayeeRelation({ item: { id: 'payee-5' } })).resolves.toBe(false)
		expect(toast.showError).toHaveBeenLastCalledWith('The linked customer could not be found.')
		expect(assign).not.toHaveBeenCalled()
	})
})

describe('openRelationsExport', () => {
	it('opens the export dialog already open and hands back what it closes with', () => {
		dialog.spawnDialog.mockReturnValue('closed')

		expect(openRelationsExport()).toBe('closed')
		expect(dialog.spawnDialog).toHaveBeenCalledWith(RelationsExportDialog, { open: true })
	})
})
