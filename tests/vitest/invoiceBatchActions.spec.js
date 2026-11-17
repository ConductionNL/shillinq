/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Send selected bulk action on Accounts Receivable (sales-invoice-sending
 * task 3.2): the selection goes to the send-batch endpoint in the active
 * administration, and the user is told it is queued or why it was not.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.2
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { MAX_BATCH_INVOICES, sendBatchEndpoint, sendSelectedInvoices } = await import('../../src/utils/invoiceBatchActions.js')

describe('sendSelectedInvoices', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		http.get.mockResolvedValue({ data: { activeAdministrationId: 'adm-1' } })
	})

	it('posts the selection to the send-batch endpoint in the active administration', async () => {
		http.post.mockResolvedValue({ data: { id: 'batch-1', state: 'queued' } })

		const batch = await sendSelectedInvoices({ selectedIds: ['inv-1', '', 'inv-2'] })

		expect(batch).toEqual({ id: 'batch-1', state: 'queued' })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		expect(http.post).toHaveBeenCalledWith('/index.php' + sendBatchEndpoint(), { administrationId: 'adm-1', invoiceIds: ['inv-1', 'inv-2'] })
		expect(toast.showSuccess).toHaveBeenCalledWith('2 invoices are being sent. You get a notification when the batch is done.')
	})

	it('sends nothing for an empty selection or more than 200 invoices', async () => {
		expect(await sendSelectedInvoices({ selectedIds: [] })).toBeUndefined()
		expect(await sendSelectedInvoices(undefined)).toBeUndefined()
		const many = Array.from({ length: MAX_BATCH_INVOICES + 1 }, (_, i) => 'inv-' + i)
		expect(await sendSelectedInvoices({ selectedIds: many })).toBeUndefined()

		expect(http.post).not.toHaveBeenCalled()
		expect(toast.showError).toHaveBeenCalledTimes(1)
		expect(toast.showError).toHaveBeenCalledWith('Select at most 200 invoices to send at once.')
	})

	it('shows the server\'s reason when the batch is refused', async () => {
		http.post.mockRejectedValue({ response: { data: { error: 'A batch sends at most 200 invoices.' } } })

		expect(await sendSelectedInvoices({ selectedIds: ['inv-1'] })).toBeUndefined()
		expect(toast.showError).toHaveBeenCalledWith('A batch sends at most 200 invoices.')

		http.post.mockRejectedValue(new Error('network'))
		await sendSelectedInvoices({ selectedIds: ['inv-1'] })
		expect(toast.showError).toHaveBeenLastCalledWith('The invoices could not be queued for sending.')
	})
})
