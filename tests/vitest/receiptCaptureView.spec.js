/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ReceiptCapture: loading a receipt into the review form, the GL-account
 * suggestion it fetches only for a known extraction, using that suggestion,
 * saving through the draft-confirm proxy or a plain object update, the
 * extraction re-request, and the computed flags the template reads. The
 * pure form helpers live in receiptCapture.spec.js.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { requestExtraction, suggestGlAccount } from '../../src/utils/extractionDraftApi.js'
import { reviewFormFromReceipt } from '../../src/views/receiptCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))
vi.mock('../../src/components/BudgetBBVMapping/GlAccountPicker.vue', () => ({ default: {} }))
vi.mock('../../src/utils/extractionDraftApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	requestExtraction: vi.fn(),
	suggestGlAccount: vi.fn(),
}))

const { default: ReceiptCapture } = await import('../../src/views/ReceiptCapture.vue')

const VALID = { amount: 12.5, currency: 'EUR', receiptDate: '2026-11-01', category: 'travel' }

/**
 * A fake instance with data, bound methods and live computed getters.
 *
 * @param {object} state Overrides.
 * @return {object} The instance.
 */
function view(state = {}) {
	const vm = { ...ReceiptCapture.data.call({}), id: 'r-1', ...state }
	for (const [name, fn] of Object.entries(ReceiptCapture.methods)) {
		vm[name] = typeof fn === 'function' ? fn.bind(vm) : fn
	}
	for (const [name, fn] of Object.entries(ReceiptCapture.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	return vm
}

beforeEach(() => {
	axios.get.mockReset()
	axios.put.mockReset()
	suggestGlAccount.mockReset()
	requestExtraction.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
})

describe('reload', () => {
	it('reads the receipt into the form and asks for a suggestion for a known extraction', async () => {
		const record = { ...VALID, docudeskExtractionId: 'ex-1', glAccount: '4000' }
		axios.get.mockResolvedValueOnce({ data: { object: record } })
		suggestGlAccount.mockResolvedValueOnce({ code: '4100' })
		const vm = view()
		await vm.reload()
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/shillinq/Receipt/r-1')
		expect(vm.record).toEqual(record)
		expect(vm.form).toEqual(reviewFormFromReceipt(record))
		await Promise.resolve()
		expect(suggestGlAccount).toHaveBeenCalledWith('r-1', 'Receipt')
		expect(vm.loading).toBe(false)
		expect(vm.loadError).toBe('')
	})

	it('takes a bare body and asks no suggestion without an extraction id', async () => {
		axios.get.mockResolvedValueOnce({ data: { ...VALID } })
		const vm = view()
		await vm.reload()
		expect(vm.record).toEqual(VALID)
		expect(suggestGlAccount).not.toHaveBeenCalled()
		expect(vm.glSuggestion).toBeNull()
	})

	it('reports an empty body and a server error', async () => {
		axios.get.mockResolvedValueOnce({ data: null })
		const vm = view()
		await vm.reload()
		expect(vm.loadError).toBe('Empty response')
		expect(vm.loading).toBe(false)

		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Not found' } } })
		await vm.reload()
		expect(vm.loadError).toBe('Not found')
	})
})

describe('suggestion', () => {
	it('fills the GL account from the suggestion, and nothing without one', () => {
		const vm = view({ glSuggestion: { code: '4100' } })
		vm.form.glAccount = '4000'
		vm.onUseSuggestion()
		expect(vm.form.glAccount).toBe('4100')

		const none = view({ glSuggestion: null })
		none.form.glAccount = '4000'
		none.onUseSuggestion()
		expect(none.form.glAccount).toBe('4000')
	})
})

describe('onSave', () => {
	it('does nothing when the form cannot be saved or a save is running', async () => {
		await view().onSave()
		await view({ form: reviewFormFromReceipt(VALID), busy: true }).onSave()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('confirms an extraction draft through the proxy', async () => {
		const record = { ...VALID, extractionStatus: 'pending-review' }
		const vm = view({ record, form: reviewFormFromReceipt(record) })
		axios.put.mockResolvedValueOnce({ data: { record: { ...record, extractionStatus: 'confirmed' } } })
		await vm.onSave()
		expect(axios.put.mock.calls[0][0]).toBe('/apps/shillinq/api/v1/extraction/drafts/r-1?schema=Receipt')
		expect(axios.put.mock.calls[0][1].category).toBe('travel')
		expect(vm.record.extractionStatus).toBe('confirmed')
		expect(showSuccess).toHaveBeenCalledWith('Receipt saved.')
		expect(vm.busy).toBe(false)
	})

	it('updates a plain receipt object, merging the form over the record', async () => {
		const record = { ...VALID, id: 'r-1', extra: 'kept' }
		const vm = view({ record, form: { ...reviewFormFromReceipt(record), category: 'meals' } })
		axios.put.mockResolvedValueOnce({ data: { object: { ...record, category: 'meals' } } })
		await vm.onSave()
		expect(axios.put.mock.calls[0][0]).toBe('/apps/openregister/api/objects/shillinq/Receipt/r-1')
		expect(axios.put.mock.calls[0][1].extra).toBe('kept')
		expect(axios.put.mock.calls[0][1].category).toBe('meals')
		expect(vm.form.category).toBe('meals')
	})

	it('shows why a save failed', async () => {
		const vm = view({ record: { ...VALID }, form: reviewFormFromReceipt(VALID) })
		axios.put.mockRejectedValueOnce({ response: { data: { message: 'Locked' } } })
		await vm.onSave()
		expect(vm.saveError).toBe('Locked')
		expect(showError).toHaveBeenCalledWith('Locked')
		expect(vm.busy).toBe(false)
	})
})

describe('onRerequest', () => {
	it('requests extraction of the source document', async () => {
		const vm = view({ record: { sourceDocumentUri: 'docudesk://r.pdf' } })
		await vm.onRerequest()
		expect(requestExtraction).toHaveBeenCalledWith({ documentUri: 'docudesk://r.pdf', docType: 'receipt', id: 'r-1' })
		expect(vm.busy).toBe(false)
	})

	it('does nothing without a source document or while busy', async () => {
		await view({ record: {} }).onRerequest()
		await view({ record: { sourceDocumentUri: 'x' }, busy: true }).onRerequest()
		expect(requestExtraction).not.toHaveBeenCalled()
	})

	it('shows why a re-request failed', async () => {
		requestExtraction.mockRejectedValueOnce(new Error('docudesk down'))
		const vm = view({ record: { sourceDocumentUri: 'x' } })
		await vm.onRerequest()
		expect(vm.saveError).toBe('docudesk down')
		expect(showError).toHaveBeenCalledWith('docudesk down')
	})
})

describe('computed flags', () => {
	it('derives the draft, review, source and save flags from the record and form', () => {
		const draft = view({ record: { extractionStatus: 'pending-review', overallConfidence: 0.1, sourceDocumentUri: 'u' }, form: reviewFormFromReceipt(VALID) })
		expect(draft.isDraftReview).toBe(true)
		expect(draft.requiresReview).toBe(true)
		expect(draft.canRerequest).toBe(true)
		expect(draft.sourceDocumentUri).toBe('u')
		expect(draft.canSave).toBe(true)

		const plain = view({ record: null })
		expect(plain.isDraftReview).toBe(false)
		expect(plain.requiresReview).toBe(false)
		expect(plain.canRerequest).toBe(false)
		expect(plain.sourceDocumentUri).toBe('')
		expect(plain.canSave).toBe(false)
	})

	it('knows whether the router has a receipts index', () => {
		expect(view({ $router: { options: { routes: [{ name: 'Receipts' }] } } }).hasIndexRoute).toBe(true)
		expect(view({ $router: { options: { routes: [{ name: 'Other' }] } } }).hasIndexRoute).toBe(false)
		expect(view().hasIndexRoute).toBe(false)
	})
})
