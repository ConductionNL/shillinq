/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * sales-billable-rate-per-hour: a time and material invoice prices each hour
 * by its own rate, so the Generate invoice page neither shows nor sends one
 * rate card for it; a mixed invoice still does (REQ-BRPH-005).
 *
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.2
 */

import { describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import Generator from '../../src/components/invoice/InvoiceGenerator.vue'
import { euro, RATE_CARD_MODELS, rateLabel, tierLabel } from '../../src/utils/billableRate.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))

/**
 * A stand-in generator instance.
 *
 * @param {object} form The form fields to set.
 * @return {object} The instance with its computed properties.
 */
function instance(form) {
	const base = Generator.data()
	const self = { ...base, form: { ...base.form, ...form } }
	for (const [name, fn] of Object.entries(Generator.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(Generator.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

/**
 * The generator rendered with a form and rate cards.
 *
 * @param {object} form The form fields to set.
 * @return {Promise<string>} The HTML.
 */
function render(form) {
	const component = {
		...Generator,
		data() {
			const base = Generator.data()
			return { ...base, form: { ...base.form, ...form } }
		},
	}
	return renderToString(createSSRApp(component, { rateCards: [{ id: 'rc-standaard', label: 'Standaard 2026' }] }))
}

describe('the rate card on the Generate invoice page', () => {
	it('is taken only by the mixed model', () => {
		expect(RATE_CARD_MODELS).toEqual(['mixed'])
	})

	it('is hidden for time and material and shown for mixed', async () => {
		expect(await render({ billingModel: 't_and_m' })).not.toContain('Standaard 2026')
		expect(await render({ billingModel: 'mixed' })).toContain('Standaard 2026')
	})

	it('is not sent for time and material, even when one was picked under another model', () => {
		const payload = instance({ billingModel: 't_and_m', rateCardId: 'rc-standaard' }).payload
		expect(payload.rateCardId).toBe('')
		expect(payload.billingModel).toBe('t_and_m')
	})

	it('is sent for mixed', () => {
		expect(instance({ billingModel: 'mixed', rateCardId: 'rc-standaard' }).payload.rateCardId).toBe('rc-standaard')
	})
})

describe('how an hour\'s rate reads', () => {
	it('names the amount, the unit and the tier', () => {
		expect(rateLabel({ status: 'rated', rate: 130, unit: 'hourly', tier: 'user' })).toBe(`${euro(130)} per hour · Personal rate`)
		expect(rateLabel({ status: 'rated', rate: 960, unit: 'daily', tier: 'blended' })).toBe(`${euro(960)} per day · Standard rate`)
	})

	it('says no rate, and nothing without a resolution', () => {
		expect(rateLabel({ status: 'no-rate', reason: 'no-assignment' })).toBe('No rate')
		expect(rateLabel(undefined)).toBe('')
	})

	it('names every tier and nothing else', () => {
		expect(['user', 'role', 'project', 'client', 'blended', 'other'].map(tierLabel))
			.toEqual(['Personal rate', 'Role rate', 'Project rate', 'Client rate', 'Standard rate', ''])
	})

	it('shows an em dash for a missing amount', () => {
		expect([euro(null), euro(''), euro('x')]).toEqual(['—', '—', '—'])
		expect(euro(1510)).toContain('1')
	})
})
