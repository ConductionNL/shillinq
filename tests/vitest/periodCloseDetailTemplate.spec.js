/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What the period close page shows for each state of a period: the loading
 * and error lines, the header chips and close/audit lines, the one action
 * the state allows, the checklist, the AI flags, the reopen history and the
 * trial balance link. Rendered from the component's real template.
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('../../src/modals/ReopenPeriodDialog.vue', () => ({ default: {} }))

const { default: PeriodCloseDetail } = await import('../../src/components/period-close/PeriodCloseDetail.vue')

const components = { NcButton: standIn('NcButton'), ReopenPeriodDialog: standIn('ReopenPeriodDialog') }

/**
 * Render the page for a period.
 *
 * @param {object} data Preset data.
 * @return {Promise<string>} The HTML.
 */
const render = (data) => renderTemplate(PeriodCloseDetail, { props: { id: 'p-1' }, data: { loading: false, ...data }, components })

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * The labels of the buttons rendered.
 *
 * @param {string} html The HTML.
 * @return {Array<string>} The labels.
 */
const buttons = (html) => [...html.matchAll(/<cn-stub data-name="NcButton"[^>]*>([\s\S]*?)<\/cn-stub>/g)].map((m) => text(m[1]))

beforeAll(() => {
	globalThis.t = (_app, value) => value
})

describe('PeriodCloseDetail template', () => {
	it('says it is loading, or why it could not load', async () => {
		expect(text(await render({ loading: true }))).toContain('Loading period…')
		const failed = await render({ loadError: 'Period not found.' })
		expect(text(failed)).toContain('Period not found.')
		expect(failed).not.toContain('period-close-detail__body')
	})

	it('shows an open period with its chips, only the start-close action, and empty lists', async () => {
		const html = await render({
			period: { name: 'Q1 2026', periodId: '2026-Q1', state: 'open', administrationId: 'adm-1', fiscalYear: 2026, startDate: '2026-01-01', endDate: '2026-03-31' },
		})
		const shown = text(html)

		expect(shown).toContain('Q1 2026')
		expect(shown).toContain('Open')
		expect(shown).toContain('Period: 2026-Q1')
		expect(shown).toContain('Administration: adm-1')
		expect(shown).toContain('Fiscal year: 2026')
		expect(shown).toContain('2026-01-01 – 2026-03-31')
		expect(shown).not.toContain('Closed at')
		expect(buttons(html)).toEqual(['Start close', 'Add item'])
		expect(shown).toContain('No checklist items yet.')
		expect(shown).toContain('No close assistant flags raised.')
		expect(html).not.toContain('period-close-detail-history')
		expect(html).toContain('href="/index.php/apps/shillinq/trial-balance?periodId=2026-Q1"')
	})

	it('shows a closed period with who closed and locked it, its two actions and the notices', async () => {
		const html = await render({
			period: { periodId: '2026-Q1', state: 'closed', closedAt: 'not-a-date', closedBy: 'boekhouder', auditLockedAt: 'also-not', auditLockedBy: 'controller' },
			transitionError: 'Transition failed.',
			transitionNotice: 'Period closed.',
		})
		expect(buttons(await render({ period: { state: 'open' }, transitioning: true }))).toEqual(['Working…', 'Add item'])
		const shown = text(html)

		expect(shown).toContain('2026-Q1')
		expect(shown).toContain('Closed at: not-a-date — boekhouder')
		expect(shown).toContain('Audit locked at: also-not — controller')
		expect(shown).not.toContain('Administration:')
		expect(buttons(html)).toEqual(['Reopen', 'Lock for audit'])
		expect(shown).toContain('Transition failed.')
		expect(shown).toContain('Period closed.')
	})

	it('lists the checklist with category labels, the flags and the reopen history', async () => {
		const html = await render({
			period: {
				name: 'Q2',
				periodId: '2026-Q2',
				state: 'closing',
				taskChecklistItems: [
					{ id: 'c1', category: 'ap', description: 'Open invoices', status: 'resolved', resolvedAt: '2026-07-01', resolvedBy: 'jan' },
					{ id: 'c2', category: 'bank', description: 'Reconcile', status: 'open' },
					{ id: 'c3', category: 'payroll', description: 'Salaries' },
					{ id: 'c4', description: 'Misc' },
				],
				aiFlags: [
					{ id: 'f1', title: 'Unusual accrual', description: 'Twice last quarter', count: 3, severity: 'error' },
					{ code: 'DUP', count: 0 },
				],
				reopenedHistory: [
					{ reopenedAt: 'x', reopenedBy: 'anna', closeReason: 'Late invoice', originalClosedAt: 'y', originalClosedBy: 'jan' },
					{ reopenedAt: '' },
				],
			},
		})
		const shown = text(html)

		expect(shown).toContain('Accounts payable')
		expect(shown).toContain('Bank reconciliation')
		expect(shown).toContain('payroll')
		expect(shown).toContain('Other')
		expect(shown).toContain('Unusual accrual — Twice last quarter (3)')
		expect(html).toContain('period-close-detail__flag--error')
		expect(html).toContain('period-close-detail__flag--warning')
		expect(html).toContain('data-testid="period-close-detail-flag-DUP"')
		expect(shown).toContain('DUP (0)')
		expect(shown).toContain('Reopen history')
		expect(shown).toContain('x — anna')
		expect(shown).toContain('Close reason: Late invoice')
		expect(shown).toContain('Original close: y — jan')
		expect(html).toContain('data-testid="period-close-detail-history-1"')
		expect(buttons(html)).toEqual(['Close period', 'Resolve', 'Resolve', 'Resolve', 'Resolve', 'Add item'])
	})
})

describe('PeriodCloseDetail formatting', () => {
	const m = PeriodCloseDetail.methods

	it('formats dates as their day and keeps what it cannot read', () => {
		expect(m.formatDate('')).toBe('—')
		expect(m.formatDate('2026-03-31T22:00:00Z')).toBe('2026-03-31')
		expect(m.formatDate('soon')).toBe('soon')
		expect(m.formatTimestamp(null)).toBe('—')
		expect(m.formatTimestamp('soon')).toBe('soon')
		expect(m.formatTimestamp('2026-03-31T22:00:00Z')).toBe(new Date('2026-03-31T22:00:00Z').toLocaleString())
	})

	it('names each checklist category', () => {
		expect(['ap', 'ar', 'bank', 'expense', 'expense-claims', 'vat', 'accruals', 'other', 'payroll', undefined].map((c) => m.categoryLabel(c)))
			.toEqual(['Accounts payable', 'Accounts receivable', 'Bank reconciliation', 'Expense claims', 'Expense claims', 'VAT', 'Accruals', 'Other', 'payroll', 'Other'])
	})
})

describe('PeriodCloseDetail record and reopen dialog', () => {
	const recordId = (self) => PeriodCloseDetail.computed.recordId.call({ id: '', periodId: '', ...self })

	it('takes the period from the id prop, else periodId, else the route', () => {
		expect(recordId({ id: 'a', periodId: 'b', $route: { params: { id: 'c' } } })).toBe('a')
		expect(recordId({ periodId: 'b', $route: { params: { id: 'c' } } })).toBe('b')
		expect(recordId({ $route: { params: { id: 'c' } } })).toBe('c')
		expect(recordId({})).toBe('')
	})

	it('loads on creation and again only when the record changes to another one', () => {
		const self = { load: vi.fn() }
		PeriodCloseDetail.created.call(self)
		PeriodCloseDetail.watch.recordId.call(self, 'p-2', 'p-1')
		PeriodCloseDetail.watch.recordId.call(self, 'p-2', 'p-2')
		PeriodCloseDetail.watch.recordId.call(self, '', 'p-2')
		expect(self.load).toHaveBeenCalledTimes(2)
	})

	it('opens the reopen dialog clean, and keeps it open while a reopen is being sent', () => {
		const self = { reopenError: 'old', reopenDialogOpen: false, reopenSubmitting: false }
		PeriodCloseDetail.methods.openReopenDialog.call(self)
		expect([self.reopenDialogOpen, self.reopenError]).toEqual([true, ''])

		self.reopenError = 'Reopen failed.'
		self.reopenSubmitting = true
		PeriodCloseDetail.methods.closeReopenDialog.call(self)
		expect([self.reopenDialogOpen, self.reopenError]).toEqual([true, 'Reopen failed.'])

		self.reopenSubmitting = false
		PeriodCloseDetail.methods.closeReopenDialog.call(self)
		expect([self.reopenDialogOpen, self.reopenError]).toEqual([false, ''])
	})
})
