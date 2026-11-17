/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ProposePaymentRunModal.vue driven through its template: the run fields
 * before proposing, the created draft run with its link, the invoices left
 * out with their reasons, nothing to pay, the error line and the buttons
 * wired to the real handlers. Only HTTP and the @nextcloud/vue components
 * are replaced; src/utils/paymentRunApi.js and downPaymentApi.js run.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

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
		NcCheckboxRadioSwitch: standIn('NcCheckboxRadioSwitch'),
		NcDialog: standIn('NcDialog'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: ProposePaymentRunModal } = await import('../../src/modals/ProposePaymentRunModal.vue')

const READY = { administrationId: 'adm-1', dueOnOrBefore: '2026-12-01', executionDate: '2026-12-02', debtorAccountIban: 'nl91 abna 0417 1643 00' }

const RESULT = {
	paymentRun: { id: 'run 1', runNumber: 'BR-4', totalAmount: '150.5', paymentLines: [{}, {}] },
	skipped: [
		{ apTransactionRef: 'ap-1', invoiceNumber: 'I-1', reason: 'invoice-blocked', detail: 'Disputed amount' },
		{ apTransactionRef: 'ap-2', invoiceNumber: 'I-2', reason: 'no-iban' },
	],
}

/**
 * The text of the element carrying a test id.
 *
 * @param {object} view The rendered view.
 * @param {string} id The data-testid.
 * @return {string} Its text.
 */
function textOf(view, id) {
	return vnodeText(view.byTestId(id))
}

/**
 * The label of every action button, in order.
 *
 * @param {object} view The rendered view.
 * @return {Array<string>} The labels.
 */
function buttons(view) {
	return view.find((vnode) => vnode.type?.name === 'NcButton').map((vnode) => [].concat(vnode.children.default()).map(vnodeText).join(' ').trim())
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('ProposePaymentRunModal before proposing', () => {
	it('asks for the dates, the IBAN and the due-date switch', async () => {
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: READY })
		expect(view.standIns('NcDialog')[0].name).toBe('Propose payment run')
		expect(view.standIns('NcTextField').map((field) => field.label)).toEqual(['Due on or before', 'Execution date', 'Pay from IBAN'])
		expect(view.byTestId('propose-iban').props.modelValue).toBe('nl91 abna 0417 1643 00')
		expect(view.byTestId('propose-on-due-date').props.modelValue).toBe(false)
		expect(view.byTestId('propose-submit').props.disabled).toBe(false)
		expect(buttons(view)).toEqual(['Cancel', 'Propose'])
	})

	it.each([
		['no administration', { administrationId: '' }],
		['no due date', { dueOnOrBefore: '' }],
		['no execution date', { executionDate: '' }],
		['a blank IBAN', { debtorAccountIban: '   ' }],
		['a proposal under way', { submitting: true }],
	])('cannot propose with %s', async (label, over) => {
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: { ...READY, ...over } })
		expect(view.byTestId('propose-submit').props.disabled).toBe(true)
	})

	it('loads the active administration on mount, or says why it could not', async () => {
		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 3 } })
		const self = { ...ProposePaymentRunModal.data() }
		await ProposePaymentRunModal.mounted.call(self)
		expect(self.administrationId).toBe('3')

		http.get.mockRejectedValueOnce({ response: { data: { message: 'Geen administratie' } } })
		const failed = { ...ProposePaymentRunModal.data() }
		await ProposePaymentRunModal.mounted.call(failed)
		expect(failed.error).toBe('Geen administratie')
	})
})

describe('ProposePaymentRunModal after proposing', () => {
	it('names the draft run, links it and lists what was left out', async () => {
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: { result: RESULT } })

		expect(textOf(view, 'propose-created')).toBe('Draft run BR-4 with 2 payments, total EUR 150.50. Open the payment run')
		expect(view.byTestId('propose-open-run').props.href).toBe('/index.php/apps/shillinq/bookkeeping/payment-runs/run%201')
		expect(textOf(view, 'propose-skipped')).toBe('I-1: Invoice blocked: Disputed amount I-2: Supplier has no IBAN')
		expect(view.byTestId('propose-iban')).toBeUndefined()
		expect(buttons(view)).toEqual(['Close'])
	})

	it('says when no invoice can be paid, without a left-out list', async () => {
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: { result: { paymentRun: null, skipped: [] } } })
		expect(textOf(view, 'propose-nothing')).toBe('No invoice can be paid by that date.')
		expect(view.byTestId('propose-skipped')).toBeUndefined()
	})

	it('writes the same states when the slots render the server way', async () => {
		const html = async (data) => (await renderWithStandIns(ProposePaymentRunModal, { data, slots: 'ssr' })).html.replace(/<!--[[\]]-->/g, '')

		const created = await html({ result: RESULT, error: 'Let op' })
		expect(created).toContain('Draft run BR-4 with 2 payments, total EUR 150.50.')
		expect(created).toContain('href="/index.php/apps/shillinq/bookkeeping/payment-runs/run%201"')
		expect(created).toContain('Supplier has no IBAN')
		expect(created).toContain('Let op')
		expect(created).toContain('Close')

		const nothing = await html({ result: { skipped: [] } })
		expect(nothing).toContain('No invoice can be paid by that date.')
		expect(nothing).not.toContain('Left out')

		const before = await html(READY)
		expect(before).toContain('Pay each invoice on its due date')
		expect(before).toContain('Propose')
		expect(before).toContain('Cancel')
	})
})

describe('ProposePaymentRunModal handlers', () => {
	it('sends the fields with the IBAN normalised, then closes with the run', async () => {
		http.post.mockResolvedValue({ data: RESULT })
		const onClose = vi.fn()
		const view = await renderWithStandIns(ProposePaymentRunModal, { props: { onClose }, data: READY })

		view.byTestId('propose-on-due-date').props['onUpdate:modelValue'](true)
		view.byTestId('propose-due').props['onUpdate:modelValue']('2026-12-10')
		view.byTestId('propose-execution').props['onUpdate:modelValue']('2026-12-11')
		view.byTestId('propose-iban').props['onUpdate:modelValue']('nl02 rabo 0123 4567 89')
		await view.byTestId('propose-submit').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-runs/propose', {
			administrationId: 'adm-1',
			dueOnOrBefore: '2026-12-10',
			debtorAccountIban: 'NL02RABO0123456789',
			executionDate: '2026-12-11',
			payOnDueDate: true,
		})
		expect(view.vm.result).toEqual(RESULT)
		expect(view.vm.submitting).toBe(false)

		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledWith(RESULT)
	})

	it('keeps the form with the reason when the proposal fails', async () => {
		http.post.mockRejectedValue(new Error('500'))
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: READY })

		await view.byTestId('propose-submit').props.onClick()

		expect(view.vm.result).toBeNull()
		expect(view.vm.submitting).toBe(false)
		expect(view.vm.error).toBe('The payment run could not be proposed.')
		const again = await view.rerender()
		expect(textOf(again, 'propose-error')).toBe('The payment run could not be proposed.')
	})
})
