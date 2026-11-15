/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for BillImportModal.vue's onSave(): the corrected
 * draft (REQ-RXC-004) or the new SupplierInvoice it writes, the toast,
 * refresh and events, and the error. Methods run bound to a fake `this`.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BillImportModal from '../../src/modals/BillImportModal.vue'

vi.mock('@nextcloud/axios', () => ({ default: { put: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {}, NcTextField: {}, NcCheckboxRadioSwitch: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake modal with a filled review form.
 *
 * @param {object} state canSave, isDraftReview and importedRecord.
 * @return {object}
 */
function modal(state) {
	const self = { open: true, emitted: [] }
	for (const [name, method] of Object.entries(BillImportModal.methods)) {
		self[name] = method.bind(self)
	}
	Object.assign(self, BillImportModal.data.call(self))
	self.$emit = (...args) => self.emitted.push(args)
	Object.assign(self, { canSave: true, isDraftReview: false, importedRecord: null }, state)
	self.form = { supplier: 'sup-1', invoiceNumber: 'F-1', invoiceDate: '2026-11-01', glAccount: '4000', amount: '121.005', vatAmount: 'x' }
	return self
}

describe('BillImportModal onSave', () => {
	beforeEach(() => {
		axios.put.mockReset()
		axios.post.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
	})

	it('commits the correction on an extraction draft', async () => {
		axios.put.mockResolvedValue({ data: { record: { id: 'si-1' } } })
		const vm = modal({ isDraftReview: true, importedRecord: { id: 'si-1', statusCode: 'draft', extra: 1 } })

		await vm.onSave()

		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/extraction/drafts/si-1?schema=SupplierInvoice', {
			supplierId: 'sup-1', invoiceNumber: 'F-1', invoiceDate: '2026-11-01', glAccount: '4000', totalInclVat: 12101, totalVat: 0,
		})
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.emitted).toEqual([['imported', { id: 'si-1' }], ['close']])
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Bill imported.')
		expect(emit.mock.calls[0][0]).toBe('cn:widget:refresh')
		expect(vm.busy).toBe(false)
	})

	it('creates a received SupplierInvoice otherwise, keeping the imported fields', async () => {
		axios.post.mockResolvedValue({ data: { object: { uuid: 'u-2' } } })
		const vm = modal({ importedRecord: { sourceDocumentUri: 'f.xml' } })

		await vm.onSave()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/SupplierInvoice', {
			sourceDocumentUri: 'f.xml', supplierId: 'sup-1', invoiceNumber: 'F-1', invoiceDate: '2026-11-01', glAccount: '4000', totalInclVat: 12101, totalVat: 0, statusCode: 'received',
		})
		expect(vm.emitted[0]).toEqual(['imported', { id: 'u-2' }])
	})

	it('creates when a draft review has no id, and answers an empty id without one back', async () => {
		axios.post.mockResolvedValue({ data: null })
		const vm = modal({ isDraftReview: true, importedRecord: { statusCode: 'matched' } })

		await vm.onSave()

		expect(axios.post.mock.calls[0][1].statusCode).toBe('matched')
		expect(vm.emitted[0]).toEqual(['imported', { id: '' }])
	})

	it('shows the error and stays open; does nothing when it cannot save or is busy', async () => {
		axios.post.mockRejectedValue({ response: { status: 500, data: {} } })
		const vm = modal({})
		await vm.onSave()
		expect([vm.emitted, showError.mock.calls.length, vm.error !== '', vm.busy]).toEqual([[], 1, true, false])

		const blocked = modal({ canSave: false })
		await blocked.onSave()
		const busy = modal({})
		busy.busy = true
		await busy.onSave()
		expect(axios.post).toHaveBeenCalledTimes(1)
	})
})
