/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Eight modals rendered the way the server renders them: their dialog and
 * button slots as markup (`slots: 'ssr'`), and the plain-element dialogs
 * through their own templates. Each state the user can meet is drawn and its
 * text read back: the payment-plan actions, the payment-run reconcile with
 * its chosen file and result, the refund-paid form, the reopen-period and
 * delete-mapping confirmations, the missed-depreciation list, the booking
 * notification settings and the VAT-return preparation. Only HTTP, the
 * toast, the event bus, the file reader and the @nextcloud/vue components are
 * replaced.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showSuccess: vi.fn(), showError: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (all, key) => encodeURIComponent(params[key])),
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

const { default: PaymentPlanActionModal } = await import('../../src/modals/PaymentPlanActionModal.vue')
const { default: PaymentRunReconcileModal } = await import('../../src/modals/PaymentRunReconcileModal.vue')
const { default: RefundPaidModal } = await import('../../src/modals/RefundPaidModal.vue')
const { default: ReopenPeriodDialog } = await import('../../src/modals/ReopenPeriodDialog.vue')
const { default: MissedDepreciationDialog } = await import('../../src/modals/MissedDepreciationDialog.vue')
const { default: BookingNotificationConfigModal } = await import('../../src/modals/BookingNotificationConfigModal.vue')
const { default: DeleteBudgetMappingDialog } = await import('../../src/modals/DeleteBudgetMappingDialog.vue')
const { default: PrepareVatReturnModal } = await import('../../src/modals/PrepareVatReturnModal.vue')

/**
 * Markup with Vue's fragment comments removed and whitespace collapsed.
 *
 * @param {string} html The rendered HTML.
 * @return {string} The text-comparable markup.
 */
function flat(html) {
	return html
		.replace(/<!--[[\]]?-->/g, '')
		.replace(/ data-v-[0-9a-f]+/g, '')
		.replace(/\s+/g, ' ')
		.replace(/>\s+/g, '>')
		.replace(/\s+</g, '<')
}

/**
 * The text inside each NcButton stand-in, in order.
 *
 * @param {string} html The rendered HTML.
 * @return {Array<string>} The button texts.
 */
function buttonTexts(html) {
	return [...flat(html).matchAll(/<stand-in data-stand-in="NcButton">([^<]*)<\/stand-in>/g)].map((m) => m[1].trim())
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
	toast.showError.mockReset()
	bus.emit.mockReset()
})

describe('PaymentPlanActionModal rendered by the server', () => {
	it('explains the activation and names the action on its button', async () => {
		const view = await renderWithStandIns(PaymentPlanActionModal, { slots: 'ssr', props: { open: true, mode: 'activate', objectId: 'p1' } })
		expect(flat(view.html)).toContain('Reminders for the invoices on this plan pause, and the customer is mailed the schedule.')
		expect(buttonTexts(view.html)).toEqual(['Close', 'Activate plan'])
		expect(view.standIns('NcTextField')).toHaveLength(0)
	})

	it('draws the three payment fields for a settle and the reason for a cancel', async () => {
		const settle = await renderWithStandIns(PaymentPlanActionModal, { slots: 'ssr', props: { open: true, mode: 'settle', objectId: 'p1' } })
		expect(settle.standIns('NcTextField').map((attrs) => attrs.label)).toEqual(['Amount', 'Paid on', 'Payment reference'])
		expect(buttonTexts(settle.html)).toEqual(['Close', 'Record a payment'])

		const cancel = await renderWithStandIns(PaymentPlanActionModal, { slots: 'ssr', props: { open: true, mode: 'cancel', objectId: 'p1' } })
		expect(flat(cancel.html)).toContain('Reminders for the invoices on this plan resume.')
		expect(cancel.standIns('NcTextField').map((attrs) => attrs.label)).toEqual(['Reason'])
		expect(buttonTexts(cancel.html)).toEqual(['Close', 'Cancel plan'])
	})

	it('shows the error as an alert only when there is one', async () => {
		const failed = await renderWithStandIns(PaymentPlanActionModal, { slots: 'ssr', props: { open: true, mode: 'activate' }, data: { error: 'Plan has no instalments' } })
		expect(flat(failed.html)).toMatch(/role="alert" data-testid="payment-plan-action-error">Plan has no instalments</)

		const clean = await renderWithStandIns(PaymentPlanActionModal, { slots: 'ssr', props: { open: true, mode: 'activate' } })
		expect(clean.html).not.toContain('payment-plan-action-error')
	})

	it('has no plan id without a prop or a route', async () => {
		const view = await renderWithStandIns(PaymentPlanActionModal, { slots: 'ssr', props: { open: true, mode: 'activate' } })
		expect(view.vm.effectiveId).toBe('')
	})
})

describe('PaymentRunReconcileModal rendered by the server', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(PaymentRunReconcileModal, { slots: 'ssr', props: { open: false } })
		expect(view.standIns('NcDialog')).toHaveLength(0)
	})

	it('offers the file input and a reconcile button that waits for a file', async () => {
		const view = await renderWithStandIns(PaymentRunReconcileModal, { slots: 'ssr', props: { open: true, paymentRunId: 'run-1' } })
		const html = flat(view.html)
		expect(html).toContain('accept=".xml,application/xml,text/xml"')
		expect(html).toContain('aria-label="Choose a CAMT.053 bank statement file"')
		expect(html).not.toContain('prr__filename')
		expect(html).not.toContain('prr__result')
		expect(buttonTexts(view.html)).toEqual(['Close', 'Reconcile'])
		expect(view.standIns('NcButton')[1].disabled).toBe(true)
	})

	it('names the chosen file and reports a full match', async () => {
		const view = await renderWithStandIns(PaymentRunReconcileModal, {
			slots: 'ssr',
			props: { open: true, paymentRunId: 'run-1' },
			data: { fileName: 'camt.xml', fileContents: '<xml/>', result: { result: 'full' } },
		})
		const html = flat(view.html)
		expect(html).toContain('Selected: camt.xml')
		expect(html).toContain('class="prr__result--ok prr__result"')
		expect(html).toContain('Reconciled — all lines matched.')
		expect(view.standIns('NcButton')[1].disabled).toBe(false)
	})

	it('shows the mismatch note of a partial match', async () => {
		const view = await renderWithStandIns(PaymentRunReconcileModal, {
			slots: 'ssr',
			props: { open: true },
			data: { result: { result: 'partial', mismatchNote: '2 of 5 lines unmatched' } },
		})
		const html = flat(view.html)
		expect(html).toContain('class="prr__result--warn prr__result"')
		expect(html).toContain('2 of 5 lines unmatched')
		expect(html).not.toContain('Reconciled — all lines matched.')
	})

	describe('reading the chosen file', () => {
		const realReader = globalThis.FileReader

		afterEach(() => {
			globalThis.FileReader = realReader
		})

		it('keeps the text the reader returns, and an empty text for no result', async () => {
			const readers = []
			globalThis.FileReader = class {
				readAsText(file) {
					this.file = file
					readers.push(this)
				}
			}
			const view = await renderWithStandIns(PaymentRunReconcileModal, { props: { open: true } })

			view.vm.onFileSelected({ target: { files: [{ name: 'statement.xml' }] } })
			expect(view.vm.fileName).toBe('statement.xml')
			expect(readers[0].file).toEqual({ name: 'statement.xml' })
			readers[0].result = '<Document/>'
			readers[0].onload()
			expect(view.vm.fileContents).toBe('<Document/>')

			readers[0].result = null
			readers[0].onload()
			expect(view.vm.fileContents).toBe('')
		})
	})
})

describe('RefundPaidModal rendered by the server', () => {
	const request = { id: 'pr-1', administrationId: 'adm-1' }

	it('explains the step and offers cancel and mark paid', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { slots: 'ssr', props: { paymentRequest: request } })
		expect(flat(view.html)).toContain('Pay the refund by bank first. Then record the payment here.')
		expect(buttonTexts(view.html)).toEqual(['Cancel', 'Mark paid'])
		expect(view.html).not.toContain('refund-paid-error')
	})

	it('shows the error as an alert', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { slots: 'ssr', props: { paymentRequest: request }, data: { error: 'No access' } })
		expect(flat(view.html)).toMatch(/role="alert" data-testid="refund-paid-error">No access</)
	})

	it('loads the accounts on mount, reading them from results', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request } })
		http.get.mockResolvedValue({ data: { results: [{ accountNumber: '1100', name: 'Bank' }] } })

		await RefundPaidModal.mounted.call(view.vm)
		await vi.waitFor(() => expect(view.vm.loadingAccounts).toBe(false))

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Account', { params: { administrationId: 'adm-1', _limit: 1000 } })
		expect(view.vm.accountOptions).toEqual([{ value: '1100', label: '1100 Bank' }])
	})

	it('keeps no accounts when the answer is not a list', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request }, data: { accounts: [{ accountNumber: '1' }] } })
		http.get.mockResolvedValue({ data: { results: { accountNumber: '1100' } } })

		await view.vm.loadAccounts()

		expect(view.vm.accounts).toEqual([])
	})

	it('asks for both fields when the reference was never typed', async () => {
		const view = await renderWithStandIns(RefundPaidModal, { props: { paymentRequest: request }, data: { bankReference: null, bankAccount: { value: '1100' } } })

		await view.vm.submit()

		expect(view.vm.error).toBe('Fill in the bank reference and choose the bank account.')
		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('ReopenPeriodDialog template', () => {
	const globals = { t: (app, text) => text }

	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(ReopenPeriodDialog, {
			globals, props: { open: false } })
		expect(view.html).not.toContain('reopen-period-dialog')
	})

	it('names the period and keeps reopen off until a reason is typed', async () => {
		const view = await renderWithStandIns(ReopenPeriodDialog, {
			globals, props: { open: true, periodName: '2026-10' } })
		const html = flat(view.html)
		expect(html).toContain('Reopening period:<strong>2026-10</strong>')
		expect(html).toContain('placeholder="e.g. Adjusting journal for tax authority correction"')
		expect(html).toMatch(/disabled data-testid="reopen-period-dialog-confirm">Reopen</)
		expect(html).toContain('class="reopen-period-dialog__btn" data-testid="reopen-period-dialog-cancel">Cancel</button>')
		expect(html).not.toContain('reopen-period-dialog-error')

		const typed = await renderWithStandIns(ReopenPeriodDialog, {
			globals, props: { open: true }, data: { closeReason: 'Correction' } })
		expect(flat(typed.html)).toMatch(/--primary" data-testid="reopen-period-dialog-confirm">Reopen</)
		expect(typed.html).not.toContain('Reopening period:')
	})

	it('locks the form and says Working… while submitting, with the error shown', async () => {
		const view = await renderWithStandIns(ReopenPeriodDialog, {
			globals,
			props: { open: true, submitting: true, error: 'Period is locked' },
			data: { closeReason: 'Correction' },
		})
		const html = flat(view.html)
		expect(html).toMatch(/disabled data-testid="reopen-period-dialog-reason"[^>]*>Correction<\/textarea>/)
		expect(html).toMatch(/disabled data-testid="reopen-period-dialog-cancel">Cancel</)
		expect(html).toMatch(/disabled data-testid="reopen-period-dialog-confirm">Working…</)
		expect(html).toMatch(/data-testid="reopen-period-dialog-error">Period is locked</)
	})
})

describe('MissedDepreciationDialog rendered by the server', () => {
	it('says it is loading and keeps post off', async () => {
		const view = await renderWithStandIns(MissedDepreciationDialog, { slots: 'ssr', props: { open: true, assetId: 'a1' } })
		expect(flat(view.html)).toContain('<p>Loading</p>')
		expect(buttonTexts(view.html)).toEqual(['Cancel', 'Post 0 months'])
		expect(view.standIns('NcButton')[1].disabled).toBe(true)
	})

	it('shows a read error instead of the rows', async () => {
		const view = await renderWithStandIns(MissedDepreciationDialog, { slots: 'ssr', props: { open: true, assetId: 'a1' }, data: { loading: false, error: 'No access' } })
		expect(flat(view.html)).toContain('<p class="missed-depreciation__error">No access</p>')
		expect(view.html).not.toContain('missed-depreciation-rows')
	})

	it('says every month is posted when nothing is missed', async () => {
		const view = await renderWithStandIns(MissedDepreciationDialog, { slots: 'ssr', props: { open: true, assetId: 'a1' }, data: { loading: false } })
		expect(flat(view.html)).toContain('Every month that ended is posted.')
		expect(view.standIns('NcButton')[1].disabled).toBe(true)
	})

	it('lists each missed month with its amount and the total, and counts them on the button', async () => {
		const rows = [{ period: '2026-08', amount: 100 }, { period: '2026-09', amount: 50.5 }]
		const view = await renderWithStandIns(MissedDepreciationDialog, { slots: 'ssr', props: { open: true, assetId: 'a1' }, data: { loading: false, rows, total: 150.5 } })
		const money = (value) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(value)
		const html = flat(view.html)
		expect(html).toContain(`<td>2026-08</td><td>${money(100)}</td>`)
		expect(html).toContain(`<td>2026-09</td><td>${money(50.5)}</td>`)
		expect(html).toContain(`<th scope="row">Total</th><td>${money(150.5)}</td>`)
		expect(buttonTexts(view.html)).toEqual(['Cancel', 'Post 2 months'])
		expect(view.standIns('NcButton')[1].disabled).toBe(false)
	})

	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(MissedDepreciationDialog, { slots: 'ssr', props: { open: false, assetId: 'a1' } })
		expect(view.standIns('NcDialog')).toHaveLength(0)
	})
})

describe('BookingNotificationConfigModal template', () => {
	const triggers = [
		{ slug: 'reminder', name: 'Reminder', triggerType: 'before-start', channels: ['email', 'sms'], status: 'enabled' },
		{ slug: 'cancelled', name: 'Cancelled', triggerType: 'on-cancel', channels: 'email', status: 'disabled' },
	]

	afterEach(() => {
		delete globalThis.window
	})

	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(BookingNotificationConfigModal, { props: { open: false, bookingId: 'b1', triggers } })
		expect(view.html).not.toContain('bk-notification-modal')
	})

	it('draws each trigger with its state, type and channel checkboxes', async () => {
		const view = await renderWithStandIns(BookingNotificationConfigModal, { props: { open: true, bookingId: 'b1', triggers } })
		const html = flat(view.html)
		expect(html).toMatch(/<input type="checkbox" checked data-testid="bk-notification-reminder-enabled"><span>Reminder<\/span>/)
		expect(html).toMatch(/<input type="checkbox" data-testid="bk-notification-cancelled-enabled"><span>Cancelled<\/span>/)
		expect(html).toContain('<span class="bk-notification-modal__type">before-start</span>')
		expect(html).toMatch(/checked data-testid="bk-notification-reminder-channel-email"><span>Email<\/span>/)
		expect(html).toMatch(/checked data-testid="bk-notification-reminder-channel-sms"><span>SMS<\/span>/)
		expect(html).toMatch(/<input type="checkbox" data-testid="bk-notification-reminder-channel-teams"><span>Teams<\/span>/)
		expect(html).not.toContain('checked data-testid="bk-notification-cancelled-channel-email"')
		expect(html).toMatch(/data-testid="bk-notification-save">Save</)
		expect(html).not.toContain('bk-notification-loading')
	})

	it('says it is loading and keeps save off', async () => {
		const view = await renderWithStandIns(BookingNotificationConfigModal, { props: { open: true, bookingId: 'b1', triggers }, data: { loading: true } })
		const html = flat(view.html)
		expect(html).toContain('data-testid="bk-notification-loading">Loading triggers…</p>')
		expect(html).not.toContain('bk-notification-list')
		expect(html).toMatch(/data-testid="bk-notification-save" disabled>Save</)
	})

	it('says Saving… while saving and shows an error instead of the list', async () => {
		const failing = {
			...BookingNotificationConfigModal,
			created() {
				this.error = 'Triggers could not be read'
			},
		}
		const view = await renderWithStandIns(failing, { props: { open: true, bookingId: 'b1', triggers }, data: { saving: true } })
		const html = flat(view.html)
		expect(html).toContain('data-testid="bk-notification-error">Triggers could not be read</p>')
		expect(html).not.toContain('bk-notification-list')
		expect(html).toMatch(/data-testid="bk-notification-save" disabled>Saving…</)
	})

	it('translates its labels through the page translator when there is one', async () => {
		globalThis.window = { t: (app, key) => `${app}:${key}` }
		const view = await renderWithStandIns(BookingNotificationConfigModal, { props: { open: true, bookingId: 'b1', triggers: [] } })
		expect(flat(view.html)).toContain('<h2 id="bk-notification-modal-title">shillinq:Notifications</h2>')
	})
})

describe('DeleteBudgetMappingDialog labels', () => {
	afterEach(() => {
		delete globalThis.t
	})

	it('translates through the global translator when there is one', async () => {
		globalThis.t = (app, key) => `${app}:${key}`
		const view = await renderWithStandIns(DeleteBudgetMappingDialog, { props: { open: true, mapping: { glAccountNumber: '4300' } } })
		const html = flat(view.html)
		expect(html).toContain('shillinq:Delete budget mapping?')
		expect(html).toContain('<dt>shillinq:GL account</dt><dd>4300</dd>')
	})
})

describe('PrepareVatReturnModal rendered by the server', () => {
	it('shows the hint and the period fields, without an error', async () => {
		const view = await renderWithStandIns(PrepareVatReturnModal, { slots: 'ssr', data: { administrationId: 'a' } })
		expect(flat(view.html)).toContain('The return adds up the booked ledger lines of the period per box.')
		expect(view.standIns('NcTextField').map((attrs) => attrs.label)).toEqual(['Year', 'Quarter (1 to 4)'])
		expect(buttonTexts(view.html)).toEqual(['Cancel', 'Prepare'])
		expect(view.html).not.toContain('prepare-error')
	})

	it('drops the number field for a year and shows the error', async () => {
		const view = await renderWithStandIns(PrepareVatReturnModal, { slots: 'ssr', data: { period: 'year', error: 'Choose an administration first.' } })
		expect(view.standIns('NcTextField').map((attrs) => attrs.label)).toEqual(['Year'])
		expect(flat(view.html)).toMatch(/role="alert" data-testid="prepare-error">Choose an administration first.</)
	})

	it('reports the draft with zero totals when it has none, and only a close button', async () => {
		const view = await renderWithStandIns(PrepareVatReturnModal, { slots: 'ssr', data: { result: { id: 'r1', returnNumber: 'BTW-26-Q3' } } })
		const html = flat(view.html)
		expect(html).toContain('Draft return BTW-26-Q3: BTW collected EUR 0.00, input tax EUR 0.00.')
		expect(html).toContain('href="/index.php/apps/shillinq/vat-returns/r1"')
		expect(html).toContain('Open the return')
		expect(buttonTexts(view.html)).toEqual(['Close'])
		expect(view.standIns('NcTextField')).toHaveLength(0)
	})
})
