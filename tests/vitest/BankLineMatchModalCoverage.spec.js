/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BankLineMatchModal.vue driven through its template: the tab buttons, the
 * invoice search and switches, the plan radios, the ledger fields and the
 * confirm and cancel buttons wired to the real handlers, with what each tab
 * then shows. Only HTTP, the toast, the event bus and the @nextcloud/vue
 * components are replaced; src/utils/paymentPlanApi.js runs.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showSuccess: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return {
		NcButton: standIn('NcButton'),
		NcCheckboxRadioSwitch: standIn('NcCheckboxRadioSwitch'),
		NcDialog: standIn('NcDialog'),
		NcSelect: standIn('NcSelect'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: BankLineMatchModal } = await import('../../src/modals/BankLineMatchModal.vue')

const LINE = { id: 'bl 1', amount: 121, administrationId: 'adm-1', valueDate: '2026-11-02', counterpartyName: 'Hostbaar', reference: 'R-9' }
const INVOICES = [
	{ id: 'i1', number: 'F-1', counterparty: 'Alfa', amount: 21 },
	{ id: 'i2', number: 'F-2', counterparty: 'Hostbaar', amount: 121 },
]
const PLANS = [
	{ planId: 'p1', planNumber: 'BR-1', customerName: 'Jansen', nextDueAmount: 50, confidence: 'high' },
	{ planId: 'p2', planNumber: 'BR-2', customerName: 'Pietersen', nextDueAmount: 25, confidence: 'medium' },
]

/**
 * Render the modal for one line with its data laid over.
 *
 * @param {object} data Data to lay over.
 * @param {object} [options] Extra render options (`props`, `slots`).
 * @return {Promise<object>} The rendered view.
 */
function render(data, options = {}) {
	return renderWithStandIns(BankLineMatchModal, { ...options, data, props: { lineId: 'bl 1', ...(options.props || {}) } })
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
 * The text a component vnode's default slot renders.
 *
 * @param {object} vnode A component vnode.
 * @return {string} The text.
 */
function slotText(vnode) {
	return [].concat(vnode.children.default()).map(vnodeText).join(' ').replace(/\s+/g, ' ').trim()
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
	bus.emit.mockReset()
})

describe('BankLineMatchModal tabs', () => {
	it('shows the line and switches tabs from their buttons', async () => {
		const view = await render({ line: LINE })

		expect(vnodeText(view.byTestId('bank-line-match-summary'))).toBe('2026-11-02 · Hostbaar · EUR 121.00 · R-9')
		expect(['invoices', 'ledger', 'plan'].map((tab) => view.byTestId('bank-line-match-tab-' + tab).props.variant)).toEqual(['primary', 'secondary', 'secondary'])
		expect(['invoices', 'ledger', 'plan'].map((tab) => slotText(view.byTestId('bank-line-match-tab-' + tab)))).toEqual(['Invoices', 'Ledger account', 'Payment plan'])

		view.byTestId('bank-line-match-tab-ledger').props.onClick()
		const ledger = await view.rerender()
		expect(ledger.byTestId('bank-line-match-tab-ledger').props['aria-selected']).toBe('true')
		expect(standInById(ledger, 'NcSelect', 'bank-line-match-account')).toBeDefined()
		expect(ledger.byTestId('bank-line-match-search')).toBeUndefined()

		ledger.byTestId('bank-line-match-tab-plan').props.onClick()
		const plan = await ledger.rerender()
		expect(plan.byTestId('bank-line-match-tab-plan').props.variant).toBe('primary')
		expect(plan.html).toContain('No active payment plan fits this line.')

		plan.byTestId('bank-line-match-tab-invoices').props.onClick()
		expect(plan.vm.tab).toBe('invoices')
	})

	it('shows the load error instead of the line, and no plan tab for money out', async () => {
		const missing = await render({ loadError: 'Bank line not found' })
		expect(missing.byTestId('bank-line-match-summary')).toBeUndefined()
		expect(missing.find((vnode) => vnode.props?.role === 'alert').map(vnodeText)).toEqual(['Bank line not found'])
		expect(missing.byTestId('bank-line-match-confirm').props.disabled).toBe(true)

		const debit = await render({ line: { ...LINE, amount: -5 } })
		expect(debit.byTestId('bank-line-match-tab-plan')).toBeUndefined()
	})
})

describe('BankLineMatchModal invoices', () => {
	it('searches, selects and matches the chosen invoices, then closes with the answer', async () => {
		const onClose = vi.fn()
		const view = await render({ line: LINE, invoices: INVOICES }, { props: { onClose } })
		expect(view.find((vnode) => vnode.type?.name === 'NcCheckboxRadioSwitch').map(slotText)).toEqual(['F-2 · Hostbaar · EUR 121.00', 'F-1 · Alfa · EUR 21.00'])

		standInById(view, 'NcTextField', 'bank-line-match-search')['onUpdate:modelValue']('alfa')
		const searched = await view.rerender()
		expect(searched.standIns('NcCheckboxRadioSwitch').map((attrs) => attrs['data-testid'])).toEqual(['bank-line-match-invoice-F-1'])

		standInById(searched, 'NcCheckboxRadioSwitch', 'bank-line-match-invoice-F-1')['onUpdate:modelValue'](true)
		const chosen = await searched.rerender()
		expect(standInById(chosen, 'NcCheckboxRadioSwitch', 'bank-line-match-invoice-F-1').modelValue).toBe(true)
		expect(vnodeText(chosen.byTestId('bank-line-match-selected-total'))).toBe('Selected: EUR 21.00')
		expect(chosen.byTestId('bank-line-match-confirm').props.disabled).toBe(false)

		http.post.mockResolvedValue({ data: { remainder: 100 } })
		const done = await renderWithStandIns(BankLineMatchModal, { data: JSON.parse(JSON.stringify(chosen.vm.$data)), props: { lineId: 'bl 1', onClose } })
		await done.byTestId('bank-line-match-confirm').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/bank-lines/bl%201/match', { targets: ['i1'] })
		expect(toast.showSuccess).toHaveBeenCalledWith('Part payment recorded, EUR 100.00 remains open.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledWith({ remainder: 100 })
	})

	it('says when no invoice is open, and shows a refused match', async () => {
		const empty = await render({ line: LINE })
		expect(empty.html).toContain('No open invoices found.')

		const view = await render({ line: LINE, invoices: INVOICES, selected: ['i2'] })
		http.post.mockRejectedValue({ response: { data: { message: 'Selection exceeds the line' } } })
		await view.byTestId('bank-line-match-confirm').props.onClick()
		const failed = await view.rerender()
		expect(vnodeText(failed.byTestId('bank-line-match-error'))).toBe('Selection exceeds the line')
		expect(failed.byTestId('bank-line-match-confirm').props.disabled).toBe(false)
	})
})

describe('BankLineMatchModal ledger account', () => {
	it('books the line to an account with a VAT split from its fields', async () => {
		const onClose = vi.fn()
		const view = await render({ line: LINE, tab: 'ledger', accounts: [{ accountNumber: '4000', name: 'Kosten' }, { accountNumber: '1500', name: 'BTW' }] }, { props: { onClose } })
		const account = standInById(view, 'NcSelect', 'bank-line-match-account')
		expect(account.options).toEqual([{ value: '4000', label: '4000 Kosten' }, { value: '1500', label: '1500 BTW' }])
		expect(standInById(view, 'NcSelect', 'bank-line-match-vat-rate').options.map((option) => option.label)).toEqual(['0%', '9%', '21%'])
		expect(standInById(view, 'NcSelect', 'bank-line-match-vat-account')).toBeUndefined()

		account['onUpdate:modelValue']({ value: '4000' })
		standInById(view, 'NcSelect', 'bank-line-match-vat-rate')['onUpdate:modelValue']({ value: 21 })
		standInById(view, 'NcTextField', 'bank-line-match-description')['onUpdate:modelValue']('Hosting')
		const vat = await view.rerender()
		expect(vat.byTestId('bank-line-match-confirm').props.disabled).toBe(true)
		standInById(vat, 'NcSelect', 'bank-line-match-vat-account')['onUpdate:modelValue']({ value: '1500' })
		const ready = await vat.rerender()
		expect(ready.byTestId('bank-line-match-confirm').props.disabled).toBe(false)

		http.post.mockResolvedValue({ data: { remainder: 0 } })
		const done = await renderWithStandIns(BankLineMatchModal, { data: JSON.parse(JSON.stringify(ready.vm.$data)), props: { lineId: 'bl 1', onClose } })
		await done.byTestId('bank-line-match-confirm').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/bank-lines/bl%201/match', {
			ledgerAccount: { accountNumber: '4000', vatRate: 21, vatAccountNumber: '1500', description: 'Hosting' },
		})
		expect(toast.showSuccess).toHaveBeenCalledWith('Bank line matched.')
		expect(onClose).toHaveBeenCalledWith({ remainder: 0 })
	})
})

describe('BankLineMatchModal payment plan', () => {
	it('lists the plans, picks one from its radio and pays it', async () => {
		const onClose = vi.fn()
		const view = await render({ line: LINE, tab: 'plan', plans: PLANS }, { props: { onClose } })
		expect(view.find((vnode) => vnode.type?.name === 'NcCheckboxRadioSwitch').map(slotText)).toEqual([
			'BR-1 · Jansen · EUR 50.00 · Reference found',
			'BR-2 · Pietersen · EUR 25.00 · Amount and account fit',
		])
		expect(view.byTestId('bank-line-match-confirm').props.disabled).toBe(true)

		standInById(view, 'NcCheckboxRadioSwitch', 'bank-line-match-plan-BR-2')['onUpdate:modelValue']('p2')
		const chosen = await view.rerender()
		expect(standInById(chosen, 'NcCheckboxRadioSwitch', 'bank-line-match-plan-BR-2').modelValue).toBe('p2')

		http.post.mockResolvedValue({ data: { planId: 'p2' } })
		await chosen.byTestId('bank-line-match-confirm').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-plans/p2/bank-line', { lineId: 'bl 1' })
		expect(toast.showSuccess).toHaveBeenCalledWith('Bank line paid to the payment plan.')
	})

	it('loads the line, its lists and preselects the plan whose reference was found', async () => {
		const view = await render({})
		http.get.mockImplementation((url) => {
			if (url.endsWith('/BankStatementLine/bl%201')) {
				return Promise.resolve({ data: LINE })
			}
			if (url.endsWith('/payment-plans')) {
				return Promise.resolve({ data: { candidates: PLANS } })
			}
			if (url.endsWith('/Account')) {
				return Promise.resolve({ data: { results: [{ accountNumber: '4000' }] } })
			}
			return Promise.resolve({ data: { results: [{ id: 'i9', invoiceNumber: 'F-9', buyerName: 'Hostbaar', lifecycleState: 'issued', amountDue: 121 }] } })
		})

		await BankLineMatchModal.mounted.call(view.vm)

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/bank-lines/bl%201/payment-plans')
		expect([view.vm.planId, view.vm.invoices.map((row) => row.number), view.vm.accounts.length]).toEqual(['p1', ['F-9'], 1])
	})
})

describe('BankLineMatchModal closing and server render', () => {
	it('closes with null from Cancel and the dialog', async () => {
		const onClose = vi.fn()
		const view = await render({ line: LINE }, { props: { onClose } })
		const cancel = view.find((vnode) => vnode.type?.name === 'NcButton').find((vnode) => !vnode.props['data-testid'])
		expect(slotText(cancel)).toBe('Cancel')

		cancel.props.onClick()
		view.standIns('NcDialog')[0].onClosing()

		expect(onClose.mock.calls).toEqual([[null], [null]])
		expect(http.post).not.toHaveBeenCalled()
	})

	it('server-renders the load error and the empty invoice and plan tabs', async () => {
		const missing = (await render({ loadError: 'Bank line not found' }, { slots: 'ssr' })).html
		expect(missing).toMatch(/role="alert"[^>]*>\s*Bank line not found/)
		expect(missing).toContain('No open invoices found.')

		const plan = (await render({ line: LINE, tab: 'plan' }, { slots: 'ssr' })).html
		expect(plan).toContain('No active payment plan fits this line.')
		expect(plan).not.toContain('No open invoices found.')
	})

	it('server-renders the ledger tab with the VAT account and the error', async () => {
		const view = await render({ line: LINE, tab: 'ledger', vatRate: { value: 9 }, error: 'Too large' }, { slots: 'ssr' })
		const html = view.html
		expect(view.standIns('NcSelect').map((attrs) => attrs['data-testid'])).toEqual(['bank-line-match-account', 'bank-line-match-vat-rate', 'bank-line-match-vat-account'])
		expect(html).toMatch(/data-testid="bank-line-match-error"[^>]*>\s*Too large/)
		expect(html).toMatch(/Cancel[\s\S]*Confirm/)
	})
})
