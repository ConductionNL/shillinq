/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden table for the acts the financial documents panel offers on one row:
 * every kind and payment state, each grant on its own, the strict checks on
 * the grant and the restricted flag, and the placement narrowing.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */

import { describe, expect, it } from 'vitest'
import {
	actsForRow,
	ALL_ACTIONS,
} from '../../src/integrations/ShillinqFinancialDocumentsPanel.vue'

const KINDS = ['quote', 'invoice', 'credit-note', 'payment-request', 'payout']
const STATES = ['outstanding', 'paid', 'none', undefined]
const GRANTS = ['requestPayment', 'creditInvoice', 'administerPayments']

const EXPECTED = {
	'invoice|outstanding|requestPayment': ['request-payment'],
	'invoice|outstanding|creditInvoice': ['credit-invoice'],
	'invoice|paid|creditInvoice': ['credit-invoice'],
	'payment-request|outstanding|administerPayments': ['send-link', 'record-payment'],
}

describe('actsForRow golden table', () => {
	it('offers each act only for its kind, state and grant', () => {
		for (const kind of KINDS) {
			for (const paymentState of STATES) {
				for (const grant of GRANTS) {
					const key = `${kind}|${paymentState}|${grant}`
					expect(
						actsForRow({ kind, paymentState }, { [grant]: true }, ALL_ACTIONS),
						key,
					).toEqual(EXPECTED[key] || [])
				}
			}
		}
	})

	it('keeps the order of the acts when every grant is held', () => {
		const all = { requestPayment: true, creditInvoice: true, administerPayments: true }

		expect(actsForRow({ kind: 'invoice', paymentState: 'outstanding' }, all, ALL_ACTIONS))
			.toEqual(['request-payment', 'credit-invoice'])
		expect(actsForRow({ kind: 'invoice', paymentState: 'outstanding' }, all, ['credit-invoice', 'request-payment']))
			.toEqual(['request-payment', 'credit-invoice'])
		expect(actsForRow({ kind: 'payment-request', paymentState: 'outstanding' }, all, ['record-payment']))
			.toEqual(['record-payment'])
	})

	it('needs a grant that is exactly true and offers nothing without a row', () => {
		const row = { kind: 'invoice', paymentState: 'outstanding' }

		expect(actsForRow(row, { requestPayment: 'yes', creditInvoice: 1 }, ALL_ACTIONS)).toEqual([])
		expect(actsForRow(row, {}, ALL_ACTIONS)).toEqual([])
		expect(actsForRow(null, { requestPayment: true }, ALL_ACTIONS)).toEqual([])
		expect(actsForRow(undefined, { requestPayment: true }, ALL_ACTIONS)).toEqual([])
	})

	it('hides acts only on a row restricted exactly true', () => {
		const grants = { requestPayment: true }

		expect(actsForRow({ kind: 'invoice', paymentState: 'outstanding', restricted: true }, grants, ALL_ACTIONS))
			.toEqual([])
		expect(actsForRow({ kind: 'invoice', paymentState: 'outstanding', restricted: 'true' }, grants, ALL_ACTIONS))
			.toEqual(['request-payment'])
		expect(actsForRow({ kind: 'invoice', paymentState: 'outstanding', restricted: false }, grants, ALL_ACTIONS))
			.toEqual(['request-payment'])
	})
})
