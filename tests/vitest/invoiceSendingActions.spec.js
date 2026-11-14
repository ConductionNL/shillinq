/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Send by email action's helpers (sales-invoice-sending task 2.3): which
 * invoice can be sent, the endpoint, the line that says how it went out, and
 * what a send's answer means for the page.
 *
 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
 */

import { describe, expect, it } from 'vitest'
import {
	canSendInvoice,
	invoiceIdOf,
	mapInvoiceSendResult,
	sendingLine,
	sendInvoiceEndpoint,
} from '../../src/components/ar-invoice/arEInvoiceActions.js'

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))

describe('the Send by email action', () => {
	it('is open for an issued or overdue invoice only', () => {
		expect(canSendInvoice({ lifecycleState: 'issued' })).toBe(true)
		expect(canSendInvoice({ lifecycleState: 'overdue' })).toBe(true)
		expect(canSendInvoice({ lifecycleState: 'draft' })).toBe(false)
		expect(canSendInvoice({ lifecycleState: 'paid' })).toBe(false)
		expect(canSendInvoice(null)).toBe(false)
	})

	it('posts to the invoice id, else its uuid', () => {
		expect(invoiceIdOf({ id: 'inv-1', uuid: 'u-1' })).toBe('inv-1')
		expect(invoiceIdOf({ uuid: 'u-1' })).toBe('u-1')
		expect(invoiceIdOf({ '@self': { id: 's-1' } })).toBe('s-1')
		expect(invoiceIdOf(undefined)).toBe('')
		expect(sendInvoiceEndpoint('a b')).toBe('/apps/shillinq/api/ar-invoices/a%20b/send')
	})
})

describe('the line under the action', () => {
	it('names the address of a mailed invoice', () => {
		const line = sendingLine({ sentVia: 'email', sentTo: 'facturen@dekorenaar.example', sentAt: '2026-10-01T08:30:00Z' }, t)
		expect(line).toMatch(/^Sent by email to facturen@dekorenaar\.example on .+\.$/)
	})

	it('says a post invoice is printed by hand, and shows an error before anything else', () => {
		expect(sendingLine({ sentVia: 'post', sentAt: null }, t)).toBe('Delivery method post: the letter is printed and posted by hand.')
		expect(sendingLine({ sentVia: 'none' }, t)).toBe('Delivery method none: the invoice is not sent from here.')
		expect(sendingLine({ sentVia: 'email', sentAt: '2026-09-01T10:00:00Z', lastError: 'Connection refused' }, t))
			.toBe('The invoice could not be sent: Connection refused')
		expect(sendingLine(null, t)).toBe('')
		expect(sendingLine({}, t)).toBe('')
	})
})

describe('a send answer', () => {
	it('keeps the stored sending group of a send that went out', () => {
		const sending = { sentVia: 'email', sentTo: 'a@b.example' }
		expect(mapInvoiceSendResult({ status: 'sent', sending, error: '' })).toEqual({ sending, offerEmail: false, error: '' })
	})

	it('offers the mail on the Peppol fallback and records nothing', () => {
		expect(mapInvoiceSendResult({ status: 'fallback', sending: [], error: 'No Peppol participant.' }))
			.toEqual({ sending: null, offerEmail: true, error: 'No Peppol participant.' })
	})

	it('shows the error of a failed send', () => {
		expect(mapInvoiceSendResult({ status: 'failed', sending: { lastError: 'x' }, error: 'The mail could not be sent: x' }).error)
			.toBe('The mail could not be sent: x')
	})
})
