/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Tests for BillImportModal.vue's onSave(): it confirms the draft the import
 * or the extraction wrote (REQ-RXC-004, REQ-BIM-003/004) and never creates a
 * second SupplierInvoice; the toast, refresh and events, and the error.
 * Methods run bound to a fake `this`.
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

	it('confirms nothing and says so when the invoice is not a draft under review', async () => {
		const vm = modal({ importedRecord: { sourceDocumentUri: 'f.xml' } })

		await vm.onSave()

		expect(axios.put).not.toHaveBeenCalled()
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.error).toBe('shillinq:This invoice is already confirmed.')
		expect([vm.emitted, vm.busy]).toEqual([[], false])
	})

	it('confirms nothing for a draft review without an id, and keeps the id when the answer carries none', async () => {
		const noId = modal({ isDraftReview: true, importedRecord: { statusCode: 'matched' } })
		await noId.onSave()
		expect(axios.put).not.toHaveBeenCalled()
		expect(noId.error).toBe('shillinq:This invoice is already confirmed.')

		axios.put.mockResolvedValue({ data: null })
		const vm = modal({ isDraftReview: true, importedRecord: { id: 'si-3', statusCode: 'draft' } })
		await vm.onSave()
		expect(axios.post).not.toHaveBeenCalled()
		expect(vm.emitted[0]).toEqual(['imported', { id: 'si-3' }])
	})

	it('shows the error and stays open; does nothing when it cannot save or is busy', async () => {
		axios.put.mockRejectedValue({ response: { status: 500, data: {} } })
		const vm = modal({ isDraftReview: true, importedRecord: { id: 'si-4', statusCode: 'draft' } })
		await vm.onSave()
		expect([vm.emitted, showError.mock.calls.length, vm.error !== '', vm.busy]).toEqual([[], 1, true, false])

		const blocked = modal({ canSave: false, isDraftReview: true, importedRecord: { id: 'si-4' } })
		await blocked.onSave()
		const busy = modal({ isDraftReview: true, importedRecord: { id: 'si-4' } })
		busy.busy = true
		await busy.onSave()
		expect(axios.put).toHaveBeenCalledTimes(1)
		expect(axios.post).not.toHaveBeenCalled()
	})
})
