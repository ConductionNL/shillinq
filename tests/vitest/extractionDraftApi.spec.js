/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the extraction-draft calls shared by BillImportModal and
 * ReceiptCapture (src/utils/extractionDraftApi.js): the suggested-account
 * request, the (re-)extraction request and the review hint.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const showSuccess = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (...args) => showSuccess(...args) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { draftReviewHint, requestExtraction, suggestGlAccount } = await import('../../src/utils/extractionDraftApi.js')

describe('extractionDraftApi', () => {
	beforeEach(() => {
		post.mockReset()
		showSuccess.mockReset()
	})

	it('posts to the suggest-account proxy and summarises the suggestion', async () => {
		post.mockResolvedValue({ data: { suggestion: { code: ' 4400 ', label: 'Kantoor', confidence: 0.7 } } })

		const summary = await suggestGlAccount('d-1', 'SupplierInvoice')

		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/extraction/drafts/d-1/suggest-account?schema=SupplierInvoice')
		expect(summary.code).toBe('4400')
		expect(summary.label).toBe('Kantoor')
	})

	it('answers null when the proxy has no suggestion or fails', async () => {
		post.mockResolvedValueOnce({ data: { suggestion: null } })
		expect(await suggestGlAccount('d-2', 'Receipt')).toBeNull()

		post.mockRejectedValueOnce(new Error('down'))
		expect(await suggestGlAccount('d-3', 'Receipt')).toBeNull()
	})

	it('posts the extraction request and confirms it', async () => {
		post.mockResolvedValue({})

		await requestExtraction({ documentUri: 'files/a.pdf', docType: 'receipt', id: 'r-1' })

		expect(post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/extraction/request', { documentUri: 'files/a.pdf', docType: 'receipt', id: 'r-1' })
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Extraction requested. The draft will update once docudesk responds.')
	})

	it('rejects without confirming when the extraction request fails', async () => {
		post.mockRejectedValue(new Error('refused'))

		await expect(requestExtraction({ documentUri: 'x', docType: 'receipt', id: 'r-2' })).rejects.toThrow('refused')
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('words the review hint by whether review is required', () => {
		expect(draftReviewHint(true)).toBe('shillinq:Some fields have low confidence — please review before confirming.')
		expect(draftReviewHint(false)).toBe('shillinq:Extraction confidence is high. Review and confirm.')
	})
})
