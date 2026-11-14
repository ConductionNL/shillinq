/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * SpreadOverPeriodsModal.vue and src/utils/deferralApi.js: the invoice lines
 * offered for an AP and an AR invoice, the request the form sends to preview
 * and to create the schedule, the releases shown, and the messages on a
 * refusal. Only HTTP, the router and the @nextcloud/vue components are replaced.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcDialog: standIn('NcDialog'),
		NcSelect: standIn('NcSelect'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: SpreadOverPeriodsModal } = await import('../../src/modals/SpreadOverPeriodsModal.vue')
const { spreadableLines } = await import('../../src/utils/deferralApi.js')

const SUPPLIER_INVOICE = {
	id: 'si-1',
	invoiceNumber: 'INK-2026-0007',
	administrationId: 'adm-consultancy-nl',
	lines: [
		{ lineNumber: 1, description: 'Jaarlicentie', lineExtension: 120000, accountNumber: '4300' },
		{ lineNumber: 2, description: 'Installatie', lineExtension: 25000 },
	],
}

const SALES_INVOICE = {
	id: 'ar-1',
	invoiceNumber: 'VK-2026-0100',
	administrationId: 'adm-consultancy-nl',
	invoicingPeriodStart: '2026-01-01T00:00:00Z',
	invoicingPeriodEnd: '2026-06-30',
	invoiceLines: [{ lineId: 'L1', itemName: 'Onderhoudscontract', netAmount: 600.5 }],
}

const PREVIEW = {
	totalCents: 120000,
	releases: Array.from({ length: 12 }, (_, i) => ({ periodId: `2026-${String(i + 1).padStart(2, '0')}`, days: 30, amountCents: 10000 })),
}

/**
 * The attributes of the stand-in carrying a test id.
 *
 * @param {object} view The rendered view.
 * @param {string} name The stand-in's component name.
 * @param {string} testId The data-testid.
 * @return {object|undefined} Its attributes.
 */
function standInById(view, name, testId) {
	return view.standIns(name).find((attrs) => attrs['data-testid'] === testId)
}

/**
 * Render the modal and run its mount step by hand (a server render does not).
 *
 * @param {object} props The props.
 * @param {object} data Extra data.
 * @return {Promise<object>} The view.
 */
async function render(props, data = {}) {
	const view = await renderWithStandIns(SpreadOverPeriodsModal, { props: { open: true, ...props }, data })
	await SpreadOverPeriodsModal.mounted.call(view.vm)
	return view
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('spreadableLines', () => {
	it('reads an AP invoice line in cents with its account', () => {
		expect(spreadableLines('ap', SUPPLIER_INVOICE)).toEqual([
			{ key: '1', label: 'Jaarlicentie', cents: 120000, account: '4300', reference: 'ap-invoice:INK-2026-0007#line-1' },
			{ key: '2', label: 'Installatie', cents: 25000, account: '', reference: 'ap-invoice:INK-2026-0007#line-2' },
		])
	})

	it('reads an AR invoice line from euros', () => {
		expect(spreadableLines('ar', SALES_INVOICE)).toEqual([
			{ key: 'L1', label: 'Onderhoudscontract', cents: 60050, account: '', reference: 'ar-invoice:VK-2026-0100#line-L1' },
		])
		expect(spreadableLines('ar', {})).toEqual([])
		expect(spreadableLines('ap', null)).toEqual([])
	})
})

describe('SpreadOverPeriodsModal', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(SpreadOverPeriodsModal, { props: { invoice: SUPPLIER_INVOICE } })
		expect(view.standIns('NcDialog')).toEqual([])
	})

	it('preselects the AP line with its account and the default balance account', async () => {
		const view = await render({ side: 'ap', invoice: SUPPLIER_INVOICE, lineKey: '1' })

		expect(view.vm.chosenLine.key).toBe('1')
		expect(view.vm.form.resultAccount).toBe('4300')
		expect(view.vm.form.balanceAccount).toBe('1900')
		expect(view.vm.body).toEqual({
			administrationId: 'adm-consultancy-nl',
			sourceReference: 'ap-invoice:INK-2026-0007#line-1',
			description: 'INK-2026-0007 Jaarlicentie',
			kind: 'prepaid-cost',
			resultAccount: '4300',
			balanceAccount: '1900',
			totalCents: 120000,
			periodFrom: '',
			periodTo: '',
		})
		expect(view.vm.complete).toBe(false)
		expect(http.get).not.toHaveBeenCalled()
	})

	it('loads an AR invoice by the route id and takes its service period', async () => {
		http.get.mockResolvedValue({ data: SALES_INVOICE })
		const view = await render({ side: 'ar', objectId: 'ar-1' })

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/ARInvoice/ar-1')
		expect(view.vm.chosenLine.key).toBe('L1')
		expect(view.vm.body).toMatchObject({ kind: 'deferred-revenue', resultAccount: '8000', balanceAccount: '1600', totalCents: 60050, periodFrom: '2026-01-01', periodTo: '2026-06-30' })
		expect(view.vm.complete).toBe(true)
	})

	it('shows the releases, then spreads the line', async () => {
		const view = await render({ side: 'ap', invoice: SUPPLIER_INVOICE, lineKey: '1' }, { form: { resultAccount: '4300', balanceAccount: '1900', periodFrom: '2026-01-01', periodTo: '2026-12-31' } })
		http.post.mockResolvedValueOnce({ data: { data: PREVIEW } })

		await standInById(view, 'NcButton', 'spread-periods-preview').onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/deferral-schedules/preview', expect.objectContaining({ totalCents: 120000, periodFrom: '2026-01-01', periodTo: '2026-12-31', balanceAccount: '1900' }))
		const shown = await view.rerender()
		expect(shown.html).toContain('12 releases, EUR 1200.00 in total')
		expect(shown.html).toContain('EUR 100.00')
		expect(standInById(shown, 'NcButton', 'spread-periods-preview')).toBeUndefined()

		http.post.mockResolvedValueOnce({ data: { data: { ...PREVIEW, id: 's-1', openingJournalEntryId: 'je-1' } } })
		await view.vm.submit()
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/deferral-schedules', expect.objectContaining({ sourceReference: 'ap-invoice:INK-2026-0007#line-1', kind: 'prepaid-cost' }))
		expect(view.vm.done).toBe(true)
		const done = await view.rerender()
		expect(done.html).toContain('The line is spread over 12 periods.')
	})

	it('shows the server refusal and keeps the form', async () => {
		const view = await render({ side: 'ap', invoice: SUPPLIER_INVOICE, lineKey: '1' }, { form: { resultAccount: '4300', balanceAccount: '1900', periodFrom: '2026-01-01', periodTo: '2026-01-31' } })
		http.post.mockRejectedValue({ response: { data: { message: 'The service period must span more than one period' } } })

		await view.vm.showPreview()

		expect(view.vm.error).toBe('The service period must span more than one period')
		expect(view.vm.preview).toBeNull()
		expect(view.vm.busy).toBe(false)

		http.post.mockRejectedValue(new Error('down'))
		await view.vm.showPreview()
		expect(view.vm.error).toBe('The releases could not be worked out.')
	})

	it('says when the invoice could not be loaded', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const view = await render({ side: 'ar', objectId: 'nope' })

		expect(view.vm.error).toBe('The invoice could not be loaded.')
	})

	it('emits close with whether the line was spread', async () => {
		const emitted = []
		const view = await render({ side: 'ap', invoice: SUPPLIER_INVOICE, onClose: (value) => emitted.push(['close', value]) })

		view.vm.close()
		view.vm.done = true
		view.vm.close()

		expect(emitted).toEqual([['close', false], ['close', true]])
	})
})
