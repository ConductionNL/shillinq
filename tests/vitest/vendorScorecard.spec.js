/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the vendor-performance formatting shared by the index and
 * detail views (src/components/vendor-performance/vendorScorecard.js).
 *
 * @spec openspec/specs/bookkeeping-vendor-performance/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))

const { formatBp, scoreBand, trendLabel } = await import('../../src/components/vendor-performance/vendorScorecard.js')

describe('vendorScorecard', () => {
	it('formats basis points as a two-decimal percentage', () => {
		expect(formatBp(9650)).toBe('96.50 %')
		expect(formatBp('8500')).toBe('85.00 %')
		expect(formatBp(0)).toBe('0.00 %')
	})

	it('renders an em dash for an absent or non-numeric rate', () => {
		expect(formatBp(null)).toBe('—')
		expect(formatBp(undefined)).toBe('—')
		expect(formatBp('n/a')).toBe('—')
	})

	it('bands a rate at 96 % and 85 %', () => {
		expect(scoreBand(9600)).toBe('high')
		expect(scoreBand(9599)).toBe('mid')
		expect(scoreBand(8500)).toBe('mid')
		expect(scoreBand(8499)).toBe('low')
		expect(scoreBand(null)).toBe('low')
	})

	it('labels each trend and reads an unknown trend as stable', () => {
		expect(trendLabel('improving')).toBe('shillinq:Improving')
		expect(trendLabel('stable')).toBe('shillinq:Stable')
		expect(trendLabel('declining')).toBe('shillinq:Declining')
		expect(trendLabel('sideways')).toBe('shillinq:Stable')
	})
})
