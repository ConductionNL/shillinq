/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Offline unit tests for the DeadlineCalendarSettings helpers
 * (compliance-deadline-calendar REQ-CDC-006).
 *
 * @spec openspec/changes/compliance-deadline-calendar/specs/compliance-deadline-calendar/spec.md#req-cdc-006
 */

import { describe, expect, it } from 'vitest'
import {
	buildSavePayload,
	CATEGORY_META,
	normaliseSettings,
} from '../../src/views/deadlineCalendarSettingsHelpers.js'

describe('CATEGORY_META', () => {
	it('declares the four REQ-CDC-006 categories with AR opt-in default-off', () => {
		expect(CATEGORY_META.map((meta) => meta.id)).toEqual([
			'filing',
			'payment-run',
			'ar-due',
			'contract',
		])
		const arDue = CATEGORY_META.find((meta) => meta.id === 'ar-due')
		expect(arDue.defaultEnabled).toBe(false)
		const filing = CATEGORY_META.find((meta) => meta.id === 'filing')
		expect(filing.defaultEnabled).toBe(true)
		expect(filing.defaultLeadDays).toBe(10)
	})
})

describe('normaliseSettings', () => {
	it('maps a backend response onto the form model', () => {
		const rows = normaliseSettings({
			categories: {
				filing: { enabled: false, leadDays: 21 },
				'ar-due': { enabled: true, leadDays: 3 },
			},
		})
		const byId = Object.fromEntries(rows.map((row) => [row.id, row]))
		expect(byId.filing.enabled).toBe(false)
		expect(byId.filing.leadDays).toBe(21)
		expect(byId['ar-due'].enabled).toBe(true)
		expect(byId['ar-due'].leadDays).toBe(3)
		// Untouched categories fall back to defaults.
		expect(byId['payment-run'].enabled).toBe(true)
		expect(byId['payment-run'].leadDays).toBe(7)
	})

	it('falls back to documented defaults on null/malformed input', () => {
		for (const raw of [
			null,
			undefined,
			{},
			{ categories: 'nope' },
			{ categories: { filing: 'x' } },
		]) {
			const rows = normaliseSettings(raw)
			const byId = Object.fromEntries(rows.map((row) => [row.id, row]))
			expect(byId.filing.enabled).toBe(true)
			expect(byId['ar-due'].enabled).toBe(false)
			expect(byId.filing.leadDays).toBe(10)
			expect(byId.contract.leadDays).toBe(7)
		}
	})

	it('rejects negative or non-numeric lead days', () => {
		const rows = normaliseSettings({
			categories: {
				filing: { enabled: true, leadDays: -4 },
				contract: { enabled: true, leadDays: 'abc' },
			},
		})
		const byId = Object.fromEntries(rows.map((row) => [row.id, row]))
		expect(byId.filing.leadDays).toBe(10)
		expect(byId.contract.leadDays).toBe(7)
	})
})

describe('buildSavePayload', () => {
	it('emits only known categories with coerced values', () => {
		const payload = buildSavePayload([
			{ id: 'payment-run', enabled: false, leadDays: 5 },
			{ id: 'ar-due', enabled: 'truthy', leadDays: '9' },
			{ id: 'bogus', enabled: true, leadDays: 1 },
			null,
		])
		expect(payload).toEqual({
			categories: {
				'payment-run': { enabled: false, leadDays: 5 },
				'ar-due': { enabled: false, leadDays: 9 },
			},
		})
	})

	it('clamps invalid lead days to 0 and tolerates non-array input', () => {
		const payload = buildSavePayload([
			{ id: 'filing', enabled: true, leadDays: -3 },
		])
		expect(payload.categories.filing.leadDays).toBe(0)
		expect(buildSavePayload('nope')).toEqual({ categories: {} })
	})
})
