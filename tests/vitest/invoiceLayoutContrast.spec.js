// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// sales-invoice-document REQ-SID-001: the invoice layout contrast check.

import { describe, expect, it } from 'vitest'
import { checkPrimaryColor, contrastRatio, hexToRgb, MIN_CONTRAST } from '../../src/utils/invoiceLayoutContrast.js'

describe('invoice layout contrast check', () => {
	it('parses six- and three-digit colours and refuses anything else', () => {
		expect(hexToRgb('#1B4F72')).toEqual([27, 79, 114])
		expect(hexToRgb('#fff')).toEqual([255, 255, 255])
		expect(hexToRgb('blue')).toBeNull()
		expect(hexToRgb('#12345')).toBeNull()
		expect(hexToRgb(undefined)).toBeNull()
	})

	it('measures the WCAG ratio: black on white is 21, white on white 1', () => {
		expect(contrastRatio('#000000')).toBeCloseTo(21, 5)
		expect(contrastRatio('#FFFFFF')).toBeCloseTo(1, 5)
		expect(contrastRatio('#777777')).toBeCloseTo(4.48, 2)
		expect(contrastRatio('#FFFFFF', '#000000')).toBeCloseTo(21, 5)
	})

	it('accepts the Van Dijk primary colour', () => {
		const verdict = checkPrimaryColor('#1B4F72')
		expect(verdict.ok).toBe(true)
		expect(verdict.ratio).toBeGreaterThan(8)
	})

	it('refuses a colour just below 4.5:1 and a malformed one', () => {
		expect(MIN_CONTRAST).toBe(4.5)
		expect(checkPrimaryColor('#777777').ok).toBe(false)
		expect(checkPrimaryColor('#767676').ok).toBe(true)
		expect(checkPrimaryColor('#F39C12').ok).toBe(false)
		expect(checkPrimaryColor('not-a-colour')).toEqual({ ok: false, ratio: null })
	})
})
