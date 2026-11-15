/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * pendingDraftSummary (src/utils/extractionConfidence.js): the row a pending
 * extraction draft becomes in a "pending reviews" list, with its label field,
 * the id as the fallback label and the overall confidence only when it is a
 * number.
 */

import { describe, expect, it } from 'vitest'
import { pendingDraftSummary } from '../../src/utils/extractionConfidence.js'

describe('pendingDraftSummary', () => {
	it('labels a draft by the field the list names and keeps its confidence', () => {
		expect(pendingDraftSummary({ id: 7, invoiceNumber: 'F-2026-001', overallConfidence: 0.72 }, 'invoiceNumber')).toEqual({
			id: '7',
			label: 'F-2026-001',
			overallConfidence: 0.72,
		})
	})

	it('falls back to the id when the label field is empty, and labels by id by default', () => {
		expect(pendingDraftSummary({ id: 'd-1', receiptNumber: '' }, 'receiptNumber').label).toBe('d-1')
		expect(pendingDraftSummary({ id: 'd-2', overallConfidence: 0 })).toEqual({ id: 'd-2', label: 'd-2', overallConfidence: 0 })
	})

	it('drops a confidence that is not a number', () => {
		expect(pendingDraftSummary({ id: 'd-3', overallConfidence: '0.9' }).overallConfidence).toBeNull()
		expect(pendingDraftSummary({ id: 'd-4' }).overallConfidence).toBeNull()
	})

	it('summarises a missing draft as an empty row', () => {
		expect(pendingDraftSummary(null, 'invoiceNumber')).toEqual({ id: '', label: '', overallConfidence: null })
	})
})
