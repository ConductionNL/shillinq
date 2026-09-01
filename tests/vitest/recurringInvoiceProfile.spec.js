/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the RecurringInvoiceProfileModal helper layer
 * (src/modals/recurringInvoiceProfile.js): per-period net, validation
 * and the profile payload shape (always status draft on create).
 */

import { describe, expect, it } from 'vitest'
import {
	buildProfilePayload,
	defaultRecurringLine,
	perPeriodNet,
	validateProfile,
} from '../../src/modals/recurringInvoiceProfile.js'

describe('recurringInvoiceProfile — defaults + totals', () => {
	it('seeds a line with qty 1 and 21% VAT', () => {
		expect(defaultRecurringLine()).toEqual({
			description: '',
			quantity: 1,
			unitPrice: 0,
			vatCode: 21,
			revenueAccount: '',
		})
	})

	it('sums per-period net across lines', () => {
		expect(
			perPeriodNet([
				{ quantity: 1, unitPrice: 99 },
				{ quantity: 2, unitPrice: 10 },
			]),
		).toBe(119)
		expect(perPeriodNet([])).toBe(0)
	})
})

describe('recurringInvoiceProfile — validation', () => {
	const base = () => ({
		name: 'Hosting Acme',
		customerReference: 'contact:acme',
		startDate: '2027-01-01',
		invoiceDay: 1,
		lines: [{ description: 'Hosting', unitPrice: 99 }],
	})

	it('passes a complete profile', () => {
		expect(validateProfile(base())).toEqual([])
	})

	it('flags missing name, customer, line, date and bad invoice day', () => {
		expect(validateProfile({ ...base(), name: '' }).length).toBe(1)
		expect(validateProfile({ ...base(), customerReference: '' }).length).toBe(1)
		expect(
			validateProfile({
				...base(),
				lines: [{ description: '', unitPrice: 0 }],
			}).length,
		).toBe(1)
		expect(validateProfile({ ...base(), startDate: 'nope' }).length).toBe(1)
		expect(validateProfile({ ...base(), invoiceDay: 40 }).length).toBe(1)
	})

	it('requires a priced line, not just a description', () => {
		expect(
			validateProfile({
				...base(),
				lines: [{ description: 'Hosting', unitPrice: 0 }],
			}).length,
		).toBe(1)
	})
})

describe('recurringInvoiceProfile — payload', () => {
	it('builds a draft profile with normalised lines', () => {
		const payload = buildProfilePayload({
			name: ' Hosting Acme ',
			customerReference: 'contact:acme',
			frequency: 'monthly',
			interval: 1,
			startDate: '2027-01-01',
			invoiceDay: 1,
			issueMode: 'draft-for-review',
			paymentTermsDays: 30,
			lines: [
				{
					description: ' Hosting {month} ',
					quantity: 1,
					unitPrice: 99,
					vatCode: 21,
					revenueAccount: '8000',
				},
				{ description: '', unitPrice: 0 }, // dropped
			],
		})
		expect(payload.status).toBe('draft')
		expect(payload.name).toBe('Hosting Acme')
		expect(payload.lines).toHaveLength(1)
		expect(payload.lines[0].description).toBe('Hosting {month}')
		expect(payload.lines[0].vatCode).toBe(21)
	})

	it('maps occurrenceCount into endCondition + remainingOccurrences', () => {
		const payload = buildProfilePayload({
			name: 'x',
			customerReference: 'c',
			startDate: '2027-01-01',
			invoiceDay: 1,
			occurrenceCount: 12,
			lines: [{ description: 'x', unitPrice: 1, vatCode: 21 }],
		})
		expect(payload.endCondition).toEqual({ occurrenceCount: 12 })
		expect(payload.remainingOccurrences).toBe(12)
	})

	it('maps endDate into endCondition', () => {
		const payload = buildProfilePayload({
			name: 'x',
			customerReference: 'c',
			startDate: '2027-01-01',
			invoiceDay: 1,
			endDate: '2028-01-01',
			lines: [{ description: 'x', unitPrice: 1, vatCode: 21 }],
		})
		expect(payload.endCondition).toEqual({ endDate: '2028-01-01' })
	})
})
