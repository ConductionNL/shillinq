/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The bookkeeping dialogs: payment block / release, mark as last invoice,
 * prepare a VAT return, post missed depreciation, import meter readings,
 * reopen a period and the per-booking notification overrides. Only HTTP and
 * the Nextcloud globals are stubbed; the request helpers in src/utils run
 * for real.
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BookingNotificationConfigModal from '../../src/modals/BookingNotificationConfigModal.vue'
import LastInvoiceModal from '../../src/modals/LastInvoiceModal.vue'
import MeterReadingImportModal from '../../src/modals/MeterReadingImportModal.vue'
import MissedDepreciationDialog from '../../src/modals/MissedDepreciationDialog.vue'
import PaymentBlockModal from '../../src/modals/PaymentBlockModal.vue'
import PrepareVatReturnModal from '../../src/modals/PrepareVatReturnModal.vue'
import ReopenPeriodDialog from '../../src/modals/ReopenPeriodDialog.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))

/**
 * A fake component instance: data, bound methods, live computed getters,
 * a recorded $emit and the given overrides.
 *
 * @param {object} Comp The component options.
 * @param {object} state Props and data overrides.
 * @return {object} The instance.
 */
function instance(Comp, state = {}) {
	const vm = { emitted: [] }
	vm.$emit = (...args) => vm.emitted.push(args)
	for (const [name, method] of Object.entries(Comp.methods || {})) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(Comp.computed || {})) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	Object.assign(vm, Comp.data ? Comp.data.call(vm) : {}, state)
	return vm
}

const httpError = (data, status) => Object.assign(new Error('x'), { response: { data, status } })

beforeEach(() => {
	for (const fn of [axios.get, axios.post, axios.patch, showSuccess, emit]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('PaymentBlockModal', () => {
	it('takes the id from the prop, else the route', () => {
		expect(instance(PaymentBlockModal, { objectId: 'p-1', $route: { params: { id: 'r' } } }).effectiveId).toBe('p-1')
		expect(instance(PaymentBlockModal, { $route: { params: { id: 'r-1' } } }).effectiveId).toBe('r-1')
		expect(instance(PaymentBlockModal).effectiveId).toBe('')
	})

	it('refuses a block without a reason and sends nothing', async () => {
		const vm = instance(PaymentBlockModal, { schema: 'APTransaction', mode: 'block', objectId: 'ap-1', reason: '  ' })
		await vm.submit()
		expect(vm.error).toBe('Give a reason for the payment block.')
		expect(vm.submitting).toBe(false)
		expect(axios.patch).not.toHaveBeenCalled()
	})

	it('blocks a transaction and refreshes its detail', async () => {
		const vm = instance(PaymentBlockModal, { schema: 'APTransaction', mode: 'block', objectId: 'ap-1', reason: ' disputed ', error: 'old' })
		axios.patch.mockResolvedValueOnce({ data: {} })
		await vm.submit()
		expect(axios.patch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/APTransaction/ap-1',
			{ paymentBlocked: true, paymentBlockReason: 'disputed' },
		)
		expect(showSuccess).toHaveBeenCalledWith('Payment blocked')
		expect(emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'APTransactionDetail' })
		expect(vm.emitted).toEqual([['close', { paymentBlocked: true, paymentBlockReason: 'disputed' }]])
		expect(vm.error).toBe('')
		expect(vm.submitting).toBe(false)
	})

	it('releases a payee and refreshes the payee detail', async () => {
		const vm = instance(PaymentBlockModal, { schema: 'Payee', mode: 'release', $route: { params: { id: 'pay-1' } } })
		vm.reason = 'IBAN verified'
		axios.patch.mockResolvedValueOnce({ data: {} })
		await vm.submit()
		expect(axios.patch.mock.calls[0]).toEqual([
			'/index.php/apps/openregister/api/objects/shillinq/Payee/pay-1',
			{ paymentBlocked: false, paymentBlockReleaseReason: 'IBAN verified' },
		])
		expect(showSuccess).toHaveBeenCalledWith('Payment released')
		expect(emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'PayeeDetail' })
	})

	it('shows why a save failed and stays open', async () => {
		const vm = instance(PaymentBlockModal, { schema: 'Invoice', mode: 'release', objectId: 'x' })
		vm.reason = 'Creditnota ontvangen'
		await vm.submit()
		expect(vm.error).toBe('The payment block could not be saved.')
		expect(axios.patch).not.toHaveBeenCalled()

		const other = instance(PaymentBlockModal, { schema: 'Payee', mode: 'release', objectId: 'p' })
		other.reason = 'Creditnota ontvangen'
		axios.patch.mockRejectedValueOnce(httpError({ message: 'Locked' }))
		await other.submit()
		expect(other.error).toBe('Locked')
		expect(other.emitted).toEqual([])
		expect(showSuccess).not.toHaveBeenCalled()
		other.onClose()
		expect(other.emitted).toEqual([['close']])
	})
})

describe('LastInvoiceModal', () => {
	const props = { administrationId: 'adm', invoiceId: 'si/1' }

	it('loads what would be released', async () => {
		axios.get.mockResolvedValueOnce({ data: { release: 5000 } })
		const vm = instance(LastInvoiceModal, props)
		await LastInvoiceModal.mounted.call(vm)
		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/supplier-invoices/si%2F1/last-invoice',
			{ params: { administrationId: 'adm' } },
		)
		expect(vm.preview).toEqual({ release: 5000 })
		expect(vm.euro(123456)).toBe('1,234.56')
	})

	it('reports a failed load', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const vm = instance(LastInvoiceModal, props)
		await LastInvoiceModal.mounted.call(vm)
		expect(vm.error).toBe('The commitment could not be loaded.')
		expect(vm.preview).toBe(null)
	})

	it('marks the invoice, then closes reporting it done', async () => {
		const vm = instance(LastInvoiceModal, { ...props, error: 'old' })
		axios.post.mockImplementationOnce(async () => {
			expect(vm.submitting).toBe(true)
			expect(vm.error).toBe('')
			return { data: { released: 5000 } }
		})
		await vm.submit()
		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/supplier-invoices/si%2F1/last-invoice',
			{ administrationId: 'adm' },
		)
		expect(vm.preview).toEqual({ released: 5000 })
		expect(vm.done).toBe(true)
		expect(vm.submitting).toBe(false)
		vm.close()
		expect(vm.emitted).toEqual([['close', true]])
	})

	it('keeps the preview when marking is refused and closes not done', async () => {
		const vm = instance(LastInvoiceModal, { ...props, preview: { release: 1 } })
		axios.post.mockRejectedValueOnce(httpError({ message: 'Already closed' }))
		await vm.submit()
		expect(vm.error).toBe('Already closed')
		expect(vm.preview).toEqual({ release: 1 })
		expect(vm.done).toBe(false)
		vm.close()
		expect(vm.emitted).toEqual([['close', false]])
	})
})

describe('PrepareVatReturnModal', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-05-20T12:00:00Z'))
	})

	it('defaults to the last finished quarter and resets per period kind', () => {
		const vm = instance(PrepareVatReturnModal)
		expect([vm.period, vm.periodYear, vm.periodNumber]).toEqual(['quarter', '2026', '1'])
		expect(vm.periodNumberLabel).toBe('Quarter (1 to 4)')
		vm.period = 'month'
		vm.resetPeriod()
		expect([vm.periodYear, vm.periodNumber]).toEqual(['2026', '4'])
		expect(vm.periodNumberLabel).toBe('Month (1 to 12)')
		vm.period = 'year'
		vm.resetPeriod()
		expect([vm.periodYear, vm.periodNumber]).toEqual(['2025', '1'])
		expect(vm.periodOptions.map((o) => o.id)).toEqual(['quarter', 'month', 'year'])
		expect(vm.regimeOptions.map((o) => o.id)).toEqual(['standard', 'kor', 'reverse-charge'])
	})

	it('allows a submit only with an administration, a year and a period number', () => {
		expect(instance(PrepareVatReturnModal).canSubmit).toBe(false)
		expect(instance(PrepareVatReturnModal, { administrationId: 'a' }).canSubmit).toBe(true)
		expect(instance(PrepareVatReturnModal, { administrationId: 'a', periodYear: '0' }).canSubmit).toBe(false)
		expect(instance(PrepareVatReturnModal, { administrationId: 'a', periodNumber: '' }).canSubmit).toBe(false)
		expect(instance(PrepareVatReturnModal, { administrationId: 'a', period: 'year', periodNumber: '' }).canSubmit).toBe(true)
	})

	it('reads the active administration, or says to choose one', async () => {
		axios.get.mockResolvedValueOnce({ data: { activeAdministrationId: 7 } })
		const vm = instance(PrepareVatReturnModal)
		await PrepareVatReturnModal.mounted.call(vm)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		expect(vm.administrationId).toBe('7')
		expect(vm.error).toBe('')

		axios.get.mockResolvedValueOnce({ data: {} })
		const none = instance(PrepareVatReturnModal)
		await PrepareVatReturnModal.mounted.call(none)
		expect(none.error).toBe('Choose an administration first.')

		axios.get.mockRejectedValueOnce(new Error('down'))
		const failed = instance(PrepareVatReturnModal)
		await PrepareVatReturnModal.mounted.call(failed)
		expect(failed.error).toBe('The administration could not be loaded.')
	})

	it('prepares the return, links it and closes with it', async () => {
		const vm = instance(PrepareVatReturnModal, { administrationId: 'adm', regime: 'kor', error: 'old' })
		expect(vm.returnUrl).toBe('/index.php/apps/shillinq/vat-returns/')
		axios.post.mockResolvedValueOnce({ data: { data: { '@self': { id: 'vr 1' } } } })
		await vm.submit()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/vat-returns', {
			administrationId: 'adm',
			period: 'quarter',
			periodYear: 2026,
			periodNumber: 1,
			regime: 'kor',
		})
		expect(vm.error).toBe('')
		expect(vm.submitting).toBe(false)
		expect(vm.returnUrl).toBe('/index.php/apps/shillinq/vat-returns/vr%201')
		vm.result = { id: 'vr-2' }
		expect(vm.returnUrl).toBe('/index.php/apps/shillinq/vat-returns/vr-2')
		vm.close()
		expect(vm.emitted).toEqual([['close', { id: 'vr-2' }]])
	})

	it('explains a refused return by its status', async () => {
		const cases = [
			[httpError({ error: 'period-in-future' }, 400), 'That period has not ended yet. Prepare a return for a period that is over.'],
			[httpError({ error: 'bad' }, 400), 'Choose a period kind, a year from 2020 and a period number that fits it.'],
			[httpError({}, 403), 'You cannot prepare a return for this administration.'],
			[new Error('down'), 'The return could not be prepared. Try again, or ask your administrator to check the log.'],
		]
		for (const [failure, message] of cases) {
			const vm = instance(PrepareVatReturnModal, { administrationId: 'adm' })
			axios.post.mockRejectedValueOnce(failure)
			await vm.submit()
			expect(vm.error).toBe(message)
			expect(vm.result).toBe(null)
			expect(vm.submitting).toBe(false)
		}
	})
})

describe('MissedDepreciationDialog', () => {
	it('reads the missed months for the asset', async () => {
		axios.get.mockResolvedValueOnce({ data: { rows: [{ month: '2026-01' }], total: 250 } })
		const vm = instance(MissedDepreciationDialog, { assetId: 'fa 1' })
		expect(vm.url).toBe('/index.php/apps/shillinq/api/fixed-assets/fa%201/missed-depreciation')
		await MissedDepreciationDialog.mounted.call(vm)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/fixed-assets/fa%201/missed-depreciation')
		expect(vm.rows).toEqual([{ month: '2026-01' }])
		expect(vm.total).toBe(250)
		expect(vm.loading).toBe(false)
	})

	it('treats an empty answer as nothing missed and reports a failed read', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const vm = instance(MissedDepreciationDialog, { assetId: 'a' })
		await MissedDepreciationDialog.mounted.call(vm)
		expect([vm.rows, vm.total]).toEqual([[], 0])

		axios.get.mockRejectedValueOnce(httpError({ error: 'Asset is disposed' }))
		const refused = instance(MissedDepreciationDialog, { assetId: 'a' })
		await MissedDepreciationDialog.mounted.call(refused)
		expect(refused.error).toBe('Asset is disposed')
		expect(refused.loading).toBe(false)

		axios.get.mockRejectedValueOnce(new Error('down'))
		const failed = instance(MissedDepreciationDialog, { assetId: 'a' })
		await MissedDepreciationDialog.mounted.call(failed)
		expect(failed.error).toBe('The missed depreciation could not be read.')
	})

	it('picks the server reason, else the fallback', () => {
		const vm = instance(MissedDepreciationDialog, { assetId: 'a' })
		expect(vm.reason(httpError({ error: 'E' }), 'F')).toBe('E')
		expect(vm.reason(httpError({}), 'F')).toBe('F')
		expect(vm.reason(null, 'F')).toBe('F')
		expect(vm.money(0)).toBe(vm.money(undefined))
		expect(vm.money(12.5)).toMatch(/12[.,]50/)
	})

	it('posts the missed months, refreshes the page and closes', async () => {
		axios.post.mockResolvedValueOnce({ data: { rows: [{}, {}, {}] } })
		const vm = instance(MissedDepreciationDialog, { assetId: 'fa-1' })
		await vm.post()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/fixed-assets/fa-1/missed-depreciation')
		expect(showSuccess).toHaveBeenCalledWith('Posted 3 months of depreciation.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(vm.emitted).toEqual([['close']])
		expect(vm.posting).toBe(false)
	})

	it('stays open with the reason when posting fails', async () => {
		axios.post.mockResolvedValueOnce({ data: {} })
		const empty = instance(MissedDepreciationDialog, { assetId: 'a' })
		await empty.post()
		expect(showSuccess).toHaveBeenCalledWith('Posted 0 months of depreciation.')

		axios.post.mockRejectedValueOnce(new Error('down'))
		const vm = instance(MissedDepreciationDialog, { assetId: 'a' })
		await vm.post()
		expect(vm.error).toBe('The missed depreciation could not be posted.')
		expect(vm.emitted).toEqual([])
		expect(vm.posting).toBe(false)
	})
})

describe('MeterReadingImportModal', () => {
	it('reads the active administration or reports why not', async () => {
		axios.get.mockResolvedValueOnce({ data: { activeAdministrationId: 3 } })
		const vm = instance(MeterReadingImportModal)
		await MeterReadingImportModal.mounted.call(vm)
		expect(vm.administrationId).toBe('3')

		axios.get.mockRejectedValueOnce(httpError({ message: 'No access' }))
		const failed = instance(MeterReadingImportModal)
		await MeterReadingImportModal.mounted.call(failed)
		expect(failed.error).toBe('No access')
		expect(failed.administrationId).toBe('')
	})

	it('parses the chosen file into rows', async () => {
		const vm = instance(MeterReadingImportModal, { administrationId: 'a', error: 'old' })
		expect(vm.canSubmit).toBe(false)
		const file = { text: async () => 'meterId;quantity;other\nM-1;12;x\nM-2;"3,5";y\n' }
		await vm.onFile({ target: { files: [file] } })
		expect(vm.rows).toEqual([{ meterId: 'M-1', quantity: '12' }, { meterId: 'M-2', quantity: '3,5' }])
		expect(vm.error).toBe('')
		expect(vm.canSubmit).toBe(true)
		expect(instance(MeterReadingImportModal, { rows: vm.rows }).canSubmit).toBe(false)
	})

	it('says so when the file has no readings, and clears on no file', async () => {
		const vm = instance(MeterReadingImportModal)
		await vm.onFile({ target: { files: [{ text: async () => 'meterId,quantity\n' }] } })
		expect(vm.rows).toEqual([])
		expect(vm.error).toBe('The file has no readings below its header line.')
		vm.rows = [{ meterId: 'x' }]
		await vm.onFile({ target: { files: [] } })
		expect(vm.rows).toEqual([])
		expect(vm.error).toBe('')
		await vm.onFile(undefined)
		expect(vm.rows).toEqual([])
	})

	it('imports the rows and closes with the result', async () => {
		const rows = [{ meterId: 'M-1' }]
		const vm = instance(MeterReadingImportModal, { administrationId: 'adm', rows })
		axios.post.mockResolvedValueOnce({ data: { imported: 1 } })
		await vm.submit()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/meter-readings/import', { administrationId: 'adm', rows })
		expect(vm.result).toEqual({ imported: 1 })
		expect(vm.submitting).toBe(false)
		vm.close()
		expect(vm.emitted).toEqual([['close', { imported: 1 }]])
	})

	it('reports a refused import', async () => {
		const vm = instance(MeterReadingImportModal, { administrationId: 'adm', rows: [{}] })
		axios.post.mockRejectedValueOnce(new Error('down'))
		await vm.submit()
		expect(vm.error).toBe('The readings could not be imported.')
		expect(vm.result).toBe(null)
		expect(vm.submitting).toBe(false)
	})
})

describe('ReopenPeriodDialog', () => {
	it('confirms only with a reason and while not submitting', () => {
		const vm = instance(ReopenPeriodDialog, { submitting: false })
		vm.onConfirm()
		expect(vm.emitted).toEqual([])
		vm.closeReason = '  correction  '
		expect(vm.canConfirm).toBe(true)
		vm.onConfirm()
		expect(vm.emitted).toEqual([['confirm', 'correction']])
		vm.submitting = true
		expect(vm.canConfirm).toBe(false)
		vm.onConfirm()
		vm.onCancel()
		expect(vm.emitted).toHaveLength(1)
		vm.submitting = false
		vm.onCancel()
		expect(vm.emitted[1]).toEqual(['cancel'])
	})

	it('clears the reason and focuses the input on open', () => {
		const focus = vi.fn()
		const vm = instance(ReopenPeriodDialog, { closeReason: 'old', $refs: { reasonInput: { focus } } })
		vm.$nextTick = (fn) => fn()
		ReopenPeriodDialog.watch.open.call(vm, false)
		expect(vm.closeReason).toBe('old')
		ReopenPeriodDialog.watch.open.call(vm, true)
		expect(vm.closeReason).toBe('')
		expect(focus).toHaveBeenCalledTimes(1)
		vm.$refs = {}
		expect(() => ReopenPeriodDialog.watch.open.call(vm, true)).not.toThrow()
	})
})

describe('BookingNotificationConfigModal', () => {
	const triggers = [
		{ slug: 'confirm', name: 'Confirm', triggerType: 'created', channels: ['email'], status: 'disabled', appliesToBookingSlug: 'b-1' },
		{ slug: 'remind', name: 'Remind', triggerType: 'before', channels: 'email' },
	]

	it('copies the triggers so edits do not touch the originals', () => {
		const vm = instance(BookingNotificationConfigModal, { bookingId: 'b-1', triggers, error: 'old' })
		BookingNotificationConfigModal.watch.triggers.handler.call(vm)
		expect(vm.editableTriggers).toEqual([
			{ slug: 'confirm', name: 'Confirm', triggerType: 'created', channels: ['email'], status: 'disabled', appliesToBookingSlug: 'b-1' },
			{ slug: 'remind', name: 'Remind', triggerType: 'before', channels: [], status: 'enabled', appliesToBookingSlug: null },
		])
		expect(vm.error).toBe('')
		vm.toggleChannel(vm.editableTriggers[0], 'sms', true)
		expect(triggers[0].channels).toEqual(['email'])
	})

	it('restores the originals when reopened', () => {
		const vm = instance(BookingNotificationConfigModal, { bookingId: 'b-1', triggers })
		vm.reset()
		vm.setEnabled(vm.editableTriggers[0], true)
		BookingNotificationConfigModal.watch.open.call(vm, false)
		expect(vm.editableTriggers[0].status).toBe('enabled')
		BookingNotificationConfigModal.watch.open.call(vm, true)
		expect(vm.editableTriggers[0].status).toBe('disabled')
		vm.triggers = null
		vm.reset()
		expect(vm.editableTriggers).toEqual([])
	})

	it('toggles channels once each and switches status', () => {
		const vm = instance(BookingNotificationConfigModal)
		const row = { channels: ['email'], status: 'enabled' }
		vm.toggleChannel(row, 'sms', true)
		vm.toggleChannel(row, 'sms', true)
		vm.toggleChannel(row, 'email', false)
		vm.toggleChannel(row, 'chat', false)
		expect(row.channels).toEqual(['sms'])
		vm.setEnabled(row, false)
		expect(row.status).toBe('disabled')
	})

	it('emits the save payload for the caller to persist', () => {
		const vm = instance(BookingNotificationConfigModal, { bookingId: 'b-1', triggers })
		vm.reset()
		vm.toggleChannel(vm.editableTriggers[1], 'teams', true)
		vm.save()
		expect(vm.emitted).toEqual([['save', {
			bookingId: 'b-1',
			updates: [
				{ slug: 'confirm', status: 'disabled', channels: ['email'] },
				{ slug: 'remind', status: 'enabled', channels: ['teams'] },
			],
		}]])
		expect(vm.saving).toBe(false)
	})

	it('labels channels and translates through the global t when present', () => {
		const vm = instance(BookingNotificationConfigModal)
		expect(['email', 'sms', 'chat', 'teams', 'slack', 'fax'].map(vm.channelLabel)).toEqual(['Email', 'SMS', 'Chat', 'Teams', 'Slack', 'fax'])
		expect(vm.label('Save')).toBe('Save')
		vi.stubGlobal('window', { t: (app, key) => `${app}:${key}` })
		try {
			expect(vm.label('Save')).toBe('shillinq:Save')
		} finally {
			vi.unstubAllGlobals()
		}
	})
})
