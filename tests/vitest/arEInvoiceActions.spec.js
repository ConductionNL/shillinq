/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the AREInvoiceActions pure-logic helper layer
 * (src/components/ar-invoice/arEInvoiceActions.js, REQ-EINV-007):
 * the Send-action enable rule, delivery-status resolution with the
 * optimistic local override, the REST endpoint builder and the
 * send-result / error mappers.
 *
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-007
 */

import { describe, it, expect } from 'vitest'
import {
	canSendEInvoice,
	resolveDeliveryStatus,
	sendEInvoiceEndpoint,
	mapSendResult,
	extractSendErrorMessage,
} from '../../src/components/ar-invoice/arEInvoiceActions.js'

// The translate stub returns the source string — keys are English (house rule).
const t = (app, text) => text

describe('arEInvoiceActions — canSendEInvoice', () => {
	it('enables Send only for an issued invoice', () => {
		expect(canSendEInvoice({ lifecycleState: 'issued' })).toBe(true)
	})

	it('disables Send for draft/paid/overdue and missing objects', () => {
		expect(canSendEInvoice({ lifecycleState: 'draft' })).toBe(false)
		expect(canSendEInvoice({ lifecycleState: 'paid' })).toBe(false)
		expect(canSendEInvoice({ lifecycleState: 'overdue' })).toBe(false)
		expect(canSendEInvoice({})).toBe(false)
		expect(canSendEInvoice(null)).toBe(false)
		expect(canSendEInvoice(undefined)).toBe(false)
	})
})

describe('arEInvoiceActions — resolveDeliveryStatus', () => {
	it('prefers the optimistic local override after a successful send', () => {
		expect(resolveDeliveryStatus({ deliveryStatus: 'not-sent' }, 'queued')).toBe(
			'queued',
		)
	})

	it('falls back to the object field, then to not-sent', () => {
		expect(resolveDeliveryStatus({ deliveryStatus: 'delivered' }, null)).toBe(
			'delivered',
		)
		expect(resolveDeliveryStatus({}, null)).toBe('not-sent')
		expect(resolveDeliveryStatus(null, null)).toBe('not-sent')
	})
})

describe('arEInvoiceActions — sendEInvoiceEndpoint', () => {
	it('builds the send-einvoice REST path for an invoice number', () => {
		expect(sendEInvoiceEndpoint('2026-0060')).toBe(
			'/apps/shillinq/api/ar-invoices/2026-0060/send-einvoice',
		)
	})
})

describe('arEInvoiceActions — mapSendResult', () => {
	it('maps a queued response without a fallback notice', () => {
		const mapped = mapSendResult(
			{ deliveryStatus: 'queued', fallback: false },
			t,
		)
		expect(mapped.deliveryStatus).toBe('queued')
		expect(mapped.fallbackNotice).toBe('')
	})

	it('surfaces the PDF+email fallback notice when no Peppol participant exists', () => {
		const mapped = mapSendResult(
			{ deliveryStatus: 'not-sent', fallback: true },
			t,
		)
		expect(mapped.deliveryStatus).toBe('not-sent')
		expect(mapped.fallbackNotice).toBe(
			'No Peppol participant found for this debtor — use PDF + email instead.',
		)
	})

	it('defaults the status to queued on a shapeless success body', () => {
		expect(mapSendResult({}, t).deliveryStatus).toBe('queued')
		expect(mapSendResult(null, t).deliveryStatus).toBe('queued')
	})
})

describe('arEInvoiceActions — extractSendErrorMessage', () => {
	it('prefers the structured server error message', () => {
		const error = {
			response: {
				data: {
					error: 'E-invoice validation failed: KvK number must be exactly 8 digits',
				},
			},
		}
		expect(extractSendErrorMessage(error, t)).toBe(
			'E-invoice validation failed: KvK number must be exactly 8 digits',
		)
	})

	it('falls back to a generic message without a structured body', () => {
		expect(extractSendErrorMessage(new Error('network down'), t)).toBe(
			'Failed to send e-invoice',
		)
		expect(extractSendErrorMessage({}, t)).toBe('Failed to send e-invoice')
	})
})
