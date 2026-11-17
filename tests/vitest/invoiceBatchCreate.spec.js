/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * New invoice batch (sales-invoice-sending task 3.3): the request one set of
 * lines and the selected customers become, the reasons the form is not ready,
 * and the outcome counts of the created batch.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const {
	MAX_BATCH_CUSTOMERS,
	batchOutcomeCounts,
	batchRequestProblem,
	buildBatchRequest,
	createInvoiceBatch,
	emptyBatchLine,
	newBatchId,
	pricedLines,
} = await import('../../src/utils/invoiceBatchCreate.js')

/**
 * The translator, as the identity.
 *
 * @param {string} app The app.
 * @param {string} text The text.
 * @return {string} The text.
 */
function t(app, text) {
	return text
}

/**
 * The dialog state of the spec's scenario.
 *
 * @param {object} overrides Fields to change.
 * @return {object} The state.
 */
function form(overrides = {}) {
	return {
	administrationId: 'adm-kade',
	batchId: 'BATCH-OKT26',
	customers: [{ value: 'cust-korenaar', label: 'Bakkerij De Korenaar B.V.' }, { value: 'cust-voorbeeld', label: 'Gemeente Voorbeeld' }, 'cust-linde'],
	lines: [{ description: ' Onderhoudscontract oktober 2026 ', quantity: '1', unitPrice: '250,00', vatRate: 21 }],
	invoiceDate: '2026-10-01',
	dueDate: '',
	issue: true,
	send: true,
	...overrides,
	}
}

describe('buildBatchRequest', () => {
	it('bills the lines to every selected customer, due by their own terms', () => {
		expect(buildBatchRequest(form())).toEqual({
			administrationId: 'adm-kade',
			batchId: 'BATCH-OKT26',
			customerIds: ['cust-korenaar', 'cust-voorbeeld', 'cust-linde'],
			charge: {
				invoiceDate: '2026-10-01',
				lines: [{ description: 'Onderhoudscontract oktober 2026', quantity: 1, unitPrice: 250, vatRate: 21 }],
			},
			issue: true,
			send: true,
		})
	})

	it('carries a given due date and never sends what it does not issue', () => {
		const body = buildBatchRequest(form({ dueDate: '2026-11-30', issue: false, send: true }))
		expect(body.charge.dueDate).toBe('2026-11-30')
		expect(body.issue).toBe(false)
		expect(body.send).toBe(false)
	})
})

describe('pricedLines', () => {
	it('drops a line without a description, a price or a quantity', () => {
		expect(pricedLines([
			emptyBatchLine(),
			{ description: 'No price', unitPrice: '0' },
			{ description: 'No quantity', quantity: '0', unitPrice: '5' },
			{ description: 'Kept', unitPrice: 12.5, vatRate: 9 },
		])).toEqual([{ description: 'Kept', quantity: 1, unitPrice: 12.5, vatRate: 9 }])
	})
})

describe('batchRequestProblem', () => {
	it('is empty for a complete batch', () => {
		expect(batchRequestProblem(buildBatchRequest(form()), t)).toBe('')
	})

	it('names what is missing first', () => {
		expect(batchRequestProblem(buildBatchRequest(form({ customers: [] })), t)).toBe('Select at least one customer.')
		expect(batchRequestProblem(buildBatchRequest(form({ invoiceDate: '' })), t)).toBe('Enter a valid invoice date.')
		expect(batchRequestProblem(buildBatchRequest(form({ dueDate: '2026-09-30' })), t)).toBe('The due date is a date on or after the invoice date.')
		expect(batchRequestProblem(buildBatchRequest(form({ lines: [emptyBatchLine()] })), t)).toBe('Add at least one line with a description and a price.')
	})

	it('refuses more customers than one batch bills', () => {
		const customers = Array.from({ length: MAX_BATCH_CUSTOMERS + 1 }, (_, i) => 'cust-' + i)
		expect(batchRequestProblem(buildBatchRequest(form({ customers })), t)).toBe('A batch bills at most 200 customers.')
	})
})

describe('newBatchId', () => {
	it('is BATCH- and twelve upper-case hexadecimal characters', () => {
		expect(newBatchId(() => 0.999)).toBe('BATCH-FFFFFFFFFFFF')
		expect(newBatchId()).toMatch(/^BATCH-[0-9A-F]{12}$/)
	})
})

describe('batchOutcomeCounts', () => {
	it('counts the outcomes per status', () => {
		expect(batchOutcomeCounts({
			outcomes: [{ status: 'created' }, { status: 'existing' }, { status: 'existing' }, { status: 'failed' }, { status: 'other' }],
		})).toEqual({ created: 1, existing: 2, skipped: 0, failed: 1 })
		expect(batchOutcomeCounts(null)).toEqual({ created: 0, existing: 0, skipped: 0, failed: 0 })
	})
})

describe('createInvoiceBatch', () => {
	beforeEach(() => vi.clearAllMocks())

	it('posts the body to the batch endpoint and answers the batch', async () => {
		http.post.mockResolvedValue({ data: { batchId: 'BATCH-OKT26', outcomes: [] } })
		const body = buildBatchRequest(form())

		await expect(createInvoiceBatch(body)).resolves.toEqual({ batchId: 'BATCH-OKT26', outcomes: [] })
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/ar-invoices/batch', body)
	})
})
