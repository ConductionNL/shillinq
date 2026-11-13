/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The small presentational components in src/components/: the delivery
 * status chip, the budget grid cell, the extraction confidence badge, the
 * invoice line review and the recurring-profile launcher. Each one's
 * computed/method logic is called with a stand-in `this`, as Vue would.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import BudgetGridCell from '../../src/components/BudgetGridCell.vue'
import DeliveryStatusChip from '../../src/components/DeliveryStatusChip.vue'
import FieldConfidenceBadge from '../../src/components/FieldConfidenceBadge.vue'
import InvoiceLineItemReview from '../../src/components/invoice/InvoiceLineItemReview.vue'

const bus = vi.hoisted(() => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('../../src/modals/RecurringInvoiceProfileModal.vue', () => ({
	default: { name: 'RecurringInvoiceProfileModal', render: () => null },
}))

const t = (_app, text) => text

/**
 * Evaluate a computed property against a stand-in instance.
 *
 * @param {object} component The component options.
 * @param {string} name The computed name.
 * @param {object} self The instance fields.
 * @return {*} The value.
 */
function computed(component, name, self) {
	const ctx = { t, ...self }
	// Let computed properties read each other, as on a real instance.
	for (const [key, fn] of Object.entries(component.computed || {})) {
		if (!(key in ctx)) {
			Object.defineProperty(ctx, key, { get: () => fn.call(ctx), configurable: true })
		}
	}
	return component.computed[name].call(ctx)
}

describe('DeliveryStatusChip', () => {
	it.each([
		['not-sent', 'Not sent'],
		['queued', 'Queued'],
		['sent', 'Sent'],
		['delivered', 'Delivered'],
		['rejected', 'Rejected'],
		['failed', 'Failed'],
	])('labels %s as %s', (status, label) => {
		expect(computed(DeliveryStatusChip, 'label', { status })).toBe(label)
	})

	it('shows an unknown status as it is and defaults to not sent', () => {
		expect(computed(DeliveryStatusChip, 'label', { status: 'bounced' })).toBe('bounced')
		expect(DeliveryStatusChip.props.status.default).toBe('not-sent')
	})
})

describe('BudgetGridCell', () => {
	it('reads the favourable state of the cell', () => {
		expect(computed(BudgetGridCell, 'deviationState', { cell: { favorable: 1 } })).toBe(true)
		expect(computed(BudgetGridCell, 'deviationState', { cell: { favorable: 0 } })).toBe(false)
		expect(computed(BudgetGridCell, 'deviationState', { cell: { favorable: null } })).toBeNull()
		expect(computed(BudgetGridCell, 'deviationState', { cell: null })).toBeNull()
	})

	it('labels only a favourable deviation as favourable', () => {
		expect(computed(BudgetGridCell, 'deviationLabel', { cell: { favorable: true } })).toBe('Favorable:')
		expect(computed(BudgetGridCell, 'deviationLabel', { cell: { favorable: false } })).toBe('Unfavorable:')
		expect(computed(BudgetGridCell, 'deviationLabel', { cell: {} })).toBe('Unfavorable:')
	})

	it('formats amounts in cents, a dash for none', () => {
		expect(BudgetGridCell.methods.formatAmount(null)).toBe('—')
		expect(BudgetGridCell.methods.formatAmount(12345)).toContain('123')
		expect(BudgetGridCell.methods.formatAmount(12345)).toContain('45')
	})
})

describe('FieldConfidenceBadge', () => {
	const badge = (props) => ({
		label: computed(FieldConfidenceBadge, 'label', { reviewThreshold: 0.8, corrected: false, field: '', ...props }),
		badgeClass: computed(FieldConfidenceBadge, 'badgeClass', { reviewThreshold: 0.8, corrected: false, field: '', ...props }),
		percent: computed(FieldConfidenceBadge, 'percentLabel', { reviewThreshold: 0.8, corrected: false, field: '', ...props }),
		testId: computed(FieldConfidenceBadge, 'testId', { reviewThreshold: 0.8, corrected: false, field: '', ...props }),
	})

	it('marks a confident extraction as extracted', () => {
		expect(badge({ confidence: 0.93 })).toEqual({ label: 'Extracted', badgeClass: 'fcb--ok', percent: '93%', testId: 'fcb' })
	})

	it('asks for review below the threshold, but not at it', () => {
		expect(badge({ confidence: 0.79 })).toMatchObject({ label: 'Needs review', badgeClass: 'fcb--review', percent: '79%' })
		expect(badge({ confidence: 0.8 })).toMatchObject({ label: 'Extracted', badgeClass: 'fcb--ok' })
		expect(badge({ confidence: 0.5, reviewThreshold: 0.4 }).label).toBe('Extracted')
	})

	it('treats a missing or non-finite confidence as manual entry', () => {
		expect(badge({ confidence: null })).toMatchObject({ label: 'Manual', badgeClass: 'fcb--ok', percent: '—' })
		expect(badge({ confidence: Number.NaN })).toMatchObject({ label: 'Manual', percent: '—' })
		expect(badge({ confidence: Infinity }).label).toBe('Manual')
	})

	it('lets a correction win over the confidence', () => {
		expect(badge({ confidence: 0.1, corrected: true })).toMatchObject({ label: 'Corrected', badgeClass: 'fcb--corrected', percent: '10%' })
	})

	it('names its test id after the field', () => {
		expect(badge({ confidence: 1, field: 'iban' }).testId).toBe('fcb-iban')
	})
})

describe('InvoiceLineItemReview', () => {
	const m = InvoiceLineItemReview.methods

	it('labels each line source, the raw type for an unknown one', () => {
		expect(m.sourceLabel('time_entry')).toBe('Time entry')
		expect(m.sourceLabel('expense')).toBe('Expense')
		expect(m.sourceLabel('retainer_charge')).toBe('Retainer charge')
		expect(m.sourceLabel('fixed_fee')).toBe('Fixed fee')
		expect(m.sourceLabel('manual')).toBe('Manual')
		expect(m.sourceLabel('mileage')).toBe('mileage')
	})

	it('formats money in Dutch notation with two decimals', () => {
		expect(m.formatMoney(1234.5)).toBe('1.234,50')
		expect(m.formatMoney(null)).toBe('0,00')
		expect(m.formatMoney('7')).toBe('7,00')
	})

	it('formats units, empty for none', () => {
		expect(m.formatUnits(1.5)).toBe('1,5')
		expect(m.formatUnits(0)).toBe('0')
		expect(m.formatUnits(null)).toBe('')
		expect(m.formatUnits(undefined)).toBe('')
	})

	it('formats the applied rate from cents, empty without one', () => {
		expect(m.formatRate({ rateCents: 9550 })).toBe('€ 95,50')
		expect(m.formatRate({ rateCents: 0 })).toBe('€ 0,00')
		expect(m.formatRate({})).toBe('')
		expect(m.formatRate(null)).toBe('')
	})

	it('defaults to no lines and a zero summary', () => {
		expect(InvoiceLineItemReview.props.lines.default()).toEqual([])
		expect(InvoiceLineItemReview.props.summary.default()).toEqual({ netAmount: 0, vatAmount: 0, grossAmount: 0 })
	})
})

describe('RecurringInvoiceProfileLauncher', () => {
	let Launcher

	beforeEach(async () => {
		bus.emit.mockReset()
		Launcher = (await import('../../src/components/invoice/RecurringInvoiceProfileLauncher.vue')).default
	})

	it('starts closed', () => {
		expect(Launcher.data()).toEqual({ open: false })
	})

	it('closes and asks the widgets to refresh after a save', () => {
		const self = { open: true }
		Launcher.methods.onSaved.call(self)
		expect(self.open).toBe(false)
		expect(bus.emit).toHaveBeenCalledWith('cn:widget:refresh', {})
	})
})
