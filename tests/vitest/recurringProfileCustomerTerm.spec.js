/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * A new recurring profile takes its payment term from its customer
 * (sales-invoice-sending task 1.3, REQ-SIS-005): the typed customer reference
 * is matched to a customer of the active administration, and that customer's
 * paymentTermDays becomes the profile's default term.
 *
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-customer-carries-a-payment-term-and-a-delivery-method-req-sis-005
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/api/administrationApi.js', () => ({
	fetchAdministrationContext: vi.fn(async () => ({ activeAdministrationId: 'adm-kade' })),
}))

const { customerTermDays, matchCustomer } = await import('../../src/modals/recurringInvoiceProfile.js')
const { default: Modal } = await import('../../src/modals/RecurringInvoiceProfileModal.vue')

const CUSTOMERS = [
	{ id: 'c-1', customerId: 'C-001', legalName: 'Bakkerij De Korenaar B.V.', paymentTermDays: 14 },
	{ id: 'c-2', customerId: 'C-002', legalName: 'Gemeente Voorbeeld', tradeName: 'Voorbeeld', paymentTermDays: 30 },
	{ id: 'c-3', customerId: 'C-003', legalName: 'Stichting Zonder Termijn' },
]

/**
 * The modal's state for one call of applyCustomerTerm().
 *
 * @param {object} overrides Fields to change.
 * @return {object} The component instance stand-in.
 */
function instance(overrides = {}) {
	return {
		recordId: '',
		termTouched: false,
		form: { customerReference: ' bakkerij de korenaar b.v. ', paymentTermsDays: 30 },
		...overrides,
	}
}

describe('matchCustomer and customerTermDays', () => {
	it('matches the code, the legal name or the trade name, ignoring case and spaces', () => {
		expect(matchCustomer(CUSTOMERS, ' c-001 ')?.id).toBe('c-1')
		expect(matchCustomer(CUSTOMERS, 'Gemeente Voorbeeld')?.id).toBe('c-2')
		expect(matchCustomer(CUSTOMERS, 'voorbeeld')?.id).toBe('c-2')
		expect(matchCustomer(CUSTOMERS, 'Korenaar')).toBeNull()
		expect(matchCustomer(CUSTOMERS, '')).toBeNull()
	})

	it('answers the customer term, or null when it has none or an impossible one', () => {
		expect(customerTermDays(CUSTOMERS[0])).toBe(14)
		expect(customerTermDays(CUSTOMERS[2])).toBeNull()
		expect(customerTermDays({ paymentTermDays: 0 })).toBe(0)
		expect(customerTermDays({ paymentTermDays: 400 })).toBeNull()
		expect(customerTermDays(null)).toBeNull()
	})
})

describe('applyCustomerTerm', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		http.get.mockResolvedValue({ data: { results: CUSTOMERS } })
	})

	it('gives a new profile the 14-day term of its customer', async () => {
		const vm = instance()
		await Modal.methods.applyCustomerTerm.call(vm)

		expect(vm.form.paymentTermsDays).toBe(14)
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/CustomerMaster',
			{ params: { administrationId: 'adm-kade', _limit: 500 } },
		)
	})

	it('keeps a term the user typed, an edited profile\'s term, and the default for an unknown customer', async () => {
		const typed = instance({ termTouched: true, form: { customerReference: 'C-001', paymentTermsDays: 45 } })
		await Modal.methods.applyCustomerTerm.call(typed)
		expect(typed.form.paymentTermsDays).toBe(45)

		const edited = instance({ recordId: 'p-1', form: { customerReference: 'C-001', paymentTermsDays: 60 } })
		await Modal.methods.applyCustomerTerm.call(edited)
		expect(edited.form.paymentTermsDays).toBe(60)
		expect(http.get).not.toHaveBeenCalled()

		const unknown = instance({ form: { customerReference: 'Nobody B.V.', paymentTermsDays: 30 } })
		await Modal.methods.applyCustomerTerm.call(unknown)
		expect(unknown.form.paymentTermsDays).toBe(30)
	})

	it('keeps the default when the customers cannot be loaded', async () => {
		http.get.mockRejectedValue(new Error('offline'))
		const vm = instance()
		await Modal.methods.applyCustomerTerm.call(vm)
		expect(vm.form.paymentTermsDays).toBe(30)
	})
})
