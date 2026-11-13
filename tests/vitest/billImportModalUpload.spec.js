/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BillImportModal.vue: opening resets and lists pending drafts, opening a
 * draft for review with its GL suggestion, re-requesting an extraction, and
 * the upload path per file type including the honest PDF deferral. Methods,
 * computeds and the `open` watcher run bound to a fake `this` seeded from
 * the real `data()`.
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BillImportModal from '../../src/modals/BillImportModal.vue'
import { requestExtraction, suggestGlAccount } from '../../src/utils/extractionDraftApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/utils/extractionDraftApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	requestExtraction: vi.fn(),
	suggestGlAccount: vi.fn(),
}))

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq/SupplierInvoice'

/**
 * A fake modal with computeds as getters and methods bound.
 *
 * @return {object}
 */
function modal() {
	const self = { emitted: [] }
	for (const [name, method] of Object.entries(BillImportModal.methods)) {
		self[name] = method.bind(self)
	}
	Object.assign(self, BillImportModal.data.call(self))
	for (const [name, getter] of Object.entries(BillImportModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	self.$emit = (...args) => self.emitted.push(args)
	return self
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	showError.mockReset()
	requestExtraction.mockReset()
	suggestGlAccount.mockReset()
})

describe('BillImportModal opening', () => {
	it('resets everything and lists the pending drafts when opened', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ id: 'd1', invoiceNumber: 'F-1', overallConfidence: 0.8 }, { id: 'd2', supplierId: 'S-2' }] } })
		const vm = modal()
		Object.assign(vm, { step: 'review', busy: true, error: 'x', pdfDeferred: true, importedRecord: {}, glSuggestion: {} })

		BillImportModal.watch.open.call(vm, true)
		await vi.waitFor(() => expect(vm.pendingDraftRows.length).toBe(2))

		expect([vm.step, vm.busy, vm.error, vm.pdfDeferred, vm.importedRecord, vm.glSuggestion]).toEqual(['upload', false, '', false, null, null])
		expect(axios.get).toHaveBeenCalledWith(OBJECTS, { params: { extractionStatus: 'pending-review' } })
		expect(vm.pendingDrafts).toEqual([
			{ id: 'd1', label: 'F-1', overallConfidence: 0.8 },
			{ id: 'd2', label: 'S-2', overallConfidence: null },
		])
	})

	it('does nothing when closed', () => {
		BillImportModal.watch.open.call(modal(), false)

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads drafts from objects or a bare list, and treats a failure as none', async () => {
		const vm = modal()
		axios.get.mockResolvedValueOnce({ data: { objects: [{ id: 'a' }] } })
		await vm.loadPendingDrafts()
		expect(vm.pendingDraftRows).toEqual([{ id: 'a' }])

		axios.get.mockResolvedValueOnce({ data: [{ id: 'b' }] })
		await vm.loadPendingDrafts()
		expect(vm.pendingDraftRows).toEqual([{ id: 'b' }])

		axios.get.mockResolvedValueOnce({ data: { results: {} } })
		await vm.loadPendingDrafts()
		expect(vm.pendingDraftRows).toEqual([])

		vm.pendingDraftRows = [{ id: 'old' }]
		axios.get.mockRejectedValueOnce(new Error('down'))
		await vm.loadPendingDrafts()
		expect(vm.pendingDraftRows).toEqual([])
	})
})

describe('BillImportModal drafts', () => {
	it('opens a draft for review and fetches its GL suggestion', async () => {
		const record = { id: 'd1', docudeskExtractionId: 'x-1', supplierId: 'S-1', invoiceNumber: 'F-1', totalInclVat: 12100, totalVat: 2100 }
		axios.get.mockResolvedValue({ data: { object: record } })
		suggestGlAccount.mockResolvedValue({ code: '4000' })
		const vm = modal()

		await vm.openDraft('d1')
		await vi.waitFor(() => expect(vm.glSuggestion).toEqual({ code: '4000' }))

		expect(axios.get).toHaveBeenCalledWith(`${OBJECTS}/d1`)
		expect(suggestGlAccount).toHaveBeenCalledWith('d1', 'SupplierInvoice')
		expect([vm.step, vm.busy, vm.importedRecord]).toEqual(['review', false, record])
		expect(vm.form).toEqual({ supplier: 'S-1', invoiceNumber: 'F-1', invoiceDate: '', amount: 121, vatAmount: 21, glAccount: '' })

		vm.onUseSuggestion()
		expect(vm.form.glAccount).toBe('4000')
	})

	it('asks no suggestion for a record without an extraction id, and keeps the account', async () => {
		axios.get.mockResolvedValue({ data: { id: 'd2', glAccount: '7000' } })
		const vm = modal()

		await vm.openDraft('d2')
		vm.onUseSuggestion()

		expect(suggestGlAccount).not.toHaveBeenCalled()
		expect([vm.glSuggestion, vm.form.glAccount]).toEqual([null, '7000'])
	})

	it('shows why a draft could not be opened', async () => {
		axios.get.mockRejectedValue({ response: { data: { message: 'Gone' } } })
		const vm = modal()

		await vm.openDraft('d3')

		expect([vm.error, vm.busy, vm.step]).toEqual(['Gone', false, 'upload'])
		expect(showError).toHaveBeenCalledWith('Gone')
	})

	it('re-requests the extraction of the source document', async () => {
		const vm = modal()
		vm.importedRecord = { id: 'd1', sourceDocumentUri: 'files/bill.pdf' }

		await vm.onRerequest()

		expect(requestExtraction).toHaveBeenCalledWith({ documentUri: 'files/bill.pdf', docType: 'supplier-invoice', id: 'd1' })
		expect([vm.busy, vm.error]).toEqual([false, ''])
	})

	it('re-requests nothing without a source document or while busy, and shows a failure', async () => {
		const vm = modal()
		vm.importedRecord = { id: 'd1' }
		await vm.onRerequest()

		vm.importedRecord = { sourceDocumentUri: 'files/bill.pdf' }
		vm.busy = true
		await vm.onRerequest()
		expect(requestExtraction).not.toHaveBeenCalled()

		vm.busy = false
		requestExtraction.mockRejectedValueOnce(new Error('Extractor offline'))
		await vm.onRerequest()
		expect(requestExtraction).toHaveBeenCalledWith({ documentUri: 'files/bill.pdf', docType: 'supplier-invoice', id: '' })
		expect([vm.error, vm.busy]).toEqual(['Extractor offline', false])
		expect(showError).toHaveBeenCalledWith('Extractor offline')
	})
})

describe('BillImportModal upload', () => {
	it('imports an e-invoice and moves to review with the first record', async () => {
		axios.post.mockResolvedValue({ data: { records: [{ supplierId: 'S-9', invoiceNumber: 'U-1', totalInclVat: 500 }] } })
		const vm = modal()

		await vm.handleFile(new File(['<Invoice/>'], 'bill.XML'))

		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/shillinq/api/v1/supplier-invoices/import')
		expect([body.get('format'), body.get('file').name]).toEqual(['ubl', 'bill.XML'])
		expect([vm.step, vm.busy, vm.form.supplier, vm.form.amount]).toEqual(['review', false, 'S-9', 5])
	})

	it('takes a single record answer, and an empty answer as an empty record', async () => {
		const vm = modal()
		axios.post.mockResolvedValueOnce({ data: { record: { invoiceNumber: 'C-1' } } })
		await vm.handleFile(new File(['a;b'], 'bills.csv'))
		expect([axios.post.mock.calls[0][1].get('format'), vm.importedRecord]).toEqual(['csv', { invoiceNumber: 'C-1' }])

		axios.post.mockResolvedValueOnce({ data: null })
		await vm.handleFile(new File(['a;b'], 'bills.csv'))
		expect(vm.importedRecord).toEqual({})
	})

	it('defers a PDF without posting it', async () => {
		const vm = modal()

		await vm.handleFile(new File(['%PDF'], 'scan.pdf'))

		expect(axios.post).not.toHaveBeenCalled()
		expect([vm.pdfDeferred, vm.error]).toEqual([true, 'PDF OCR extraction is not yet available. Please upload a UBL/e-invoice XML or CSV.'])
	})

	it('refuses an unsupported file without posting it', async () => {
		const vm = modal()

		await vm.handleFile(new File(['x'], 'photo.png'))

		expect(axios.post).not.toHaveBeenCalled()
		expect([vm.pdfDeferred, vm.error]).toEqual([false, 'Unsupported file. Upload a UBL/e-invoice XML or CSV.'])
	})

	it('marks a server-side PDF deferral and shows every failure', async () => {
		const vm = modal()
		axios.post.mockRejectedValueOnce({ response: { status: 422, data: { deferred: 'pdf-ocr', error: 'OCR unavailable' } } })
		await vm.handleFile(new File(['<x/>'], 'bill.ubl'))
		expect([vm.pdfDeferred, vm.error, vm.busy, vm.step]).toEqual([true, 'OCR unavailable', false, 'upload'])

		axios.post.mockRejectedValueOnce({ response: { status: 409, data: {} } })
		await vm.handleFile(new File(['<x/>'], 'bill.xml'))
		expect([vm.pdfDeferred, vm.error]).toEqual([false, 'This invoice number already exists for this supplier'])
		expect(showError).toHaveBeenCalledTimes(2)
	})

	it('takes the file from a drop or a file input, and ignores an empty one', async () => {
		const vm = modal()
		const handled = []
		vm.handleFile = (file) => handled.push(file.name)
		const file = new File(['<x/>'], 'a.xml')

		vm.onDrop({ dataTransfer: { files: [file] } })
		vm.onFileSelected({ target: { files: [file] } })
		vm.onDrop({ dataTransfer: { files: [] } })
		vm.onFileSelected({})

		expect(handled).toEqual(['a.xml', 'a.xml'])
	})
})

describe('BillImportModal state', () => {
	it('can re-request only with a source document, and reads confidence from the record', () => {
		const vm = modal()
		expect(vm.canRerequest).toBe(false)
		vm.importedRecord = { sourceDocumentUri: 'files/a.pdf' }
		expect(vm.canRerequest).toBe(true)
		expect(typeof vm.reviewHint).toBe('string')
		expect(vm.isDraftReview).toBe(false)
	})

	it('closes unless busy', () => {
		const vm = modal()
		vm.busy = true
		vm.onClose()
		expect(vm.emitted).toEqual([])

		vm.busy = false
		vm.onClose()
		expect(vm.emitted).toEqual([['close']])
	})
})
