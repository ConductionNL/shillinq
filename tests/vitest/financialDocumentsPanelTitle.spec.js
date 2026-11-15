/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The heading of one row on the financial documents leaf panel: the kind in
 * words, then the number and the amount when present. A kind the panel has no
 * word for keeps its raw name instead of dropping out, and a null amount is
 * left off rather than written as zero.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */

import { describe, expect, it } from 'vitest'
import ShillinqFinancialDocumentsPanel from '../../src/integrations/ShillinqFinancialDocumentsPanel.vue'
import { formatAmount } from '../../src/integrations/leafApi.js'

const { titleOf } = ShillinqFinancialDocumentsPanel.methods

describe('ShillinqFinancialDocumentsPanel titleOf', () => {
	it('names each known kind in words', () => {
		expect(titleOf({ kind: 'invoice', amount: null })).toBe('Invoice')
		expect(titleOf({ kind: 'credit-note', number: 'CN-7', amount: null })).toBe('Credit note CN-7')
		expect(titleOf({ kind: 'payment-request', amount: null })).toBe('Payment request')
		expect(titleOf({ kind: 'payout', amount: null })).toBe('Payout')
		expect(titleOf({ kind: 'quote', number: 'Q-1', amount: null })).toBe('Quote Q-1')
	})

	it('keeps the raw kind when it has no word for it', () => {
		expect(titleOf({ kind: 'deposit', number: 'D-3', amount: null })).toBe('deposit D-3')
	})

	it('adds the amount in the row currency, and leaves an unreadable one off', () => {
		expect(titleOf({ kind: 'invoice', number: 'F-2', amount: 12.5, currency: 'USD' }))
			.toBe(`Invoice F-2 ${formatAmount(12.5, 'USD')}`)
		expect(titleOf({ kind: 'invoice', number: 'F-3', amount: 'n/a' })).toBe('Invoice F-3')
	})
})
