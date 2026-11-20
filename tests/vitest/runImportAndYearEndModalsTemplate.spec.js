/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Four modals driven through their templates: the payment-run proposal (its
 * fields, the created run, the left-out invoices), the commitment carry-over
 * (the preview, the shortfalls, the carry and the reload on a year change),
 * the meter-reading import (the file, the row count, the refused rows) and the
 * missed-depreciation dialog (the months, the total, the post). Only HTTP, the
 * toast, the event bus and the @nextcloud/vue components are replaced; the API
 * helpers run.
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
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (all, key) => encodeURIComponent(params[key])),
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
const { default: CarryOverCommitmentsModal } = await import('../../src/modals/CarryOverCommitmentsModal.vue')
const { default: MeterReadingImportModal } = await import('../../src/modals/MeterReadingImportModal.vue')
const { default: MissedDepreciationDialog } = await import('../../src/modals/MissedDepreciationDialog.vue')

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
 * The first button stand-in without a test id: the dialog's close or cancel.
 *
 * @param {object} view The rendered view.
 * @return {object} Its attributes.
 */
function plainButton(view) {
	return view.standIns('NcButton').find((attrs) => attrs['data-testid'] === undefined)
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toast.showSuccess.mockReset()
	bus.emit.mockReset()
})

describe('ProposePaymentRunModal template', () => {
	it('enables the proposal only with an administration and a paying IBAN', async () => {
		const empty = await renderWithStandIns(ProposePaymentRunModal, { data: { administrationId: 'a' } })
		expect(empty.byTestId('propose-submit').props.disabled).toBe(true)
		const ready = await renderWithStandIns(ProposePaymentRunModal, { data: { administrationId: 'a', debtorAccountIban: 'NL01' } })
		expect(ready.byTestId('propose-submit').props.disabled).toBe(false)
	})

	it('proposes from the fields and shows the run, its link and the left-out invoices', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(ProposePaymentRunModal, { props: { onClose }, data: { administrationId: 'adm-1' } })
		standInById(view, 'NcTextField', 'propose-due')['onUpdate:modelValue']('2026-11-30')
		standInById(view, 'NcTextField', 'propose-execution')['onUpdate:modelValue']('2026-12-01')
		standInById(view, 'NcTextField', 'propose-iban')['onUpdate:modelValue']('nl91 abna 0417 1643 00')
		standInById(view, 'NcCheckboxRadioSwitch', 'propose-on-due-date')['onUpdate:modelValue'](true)
		const answer = {
			paymentRun: { id: 'run 1', runNumber: 'PR-7', paymentLines: [{}, {}], totalAmount: '1500' },
			skipped: [{ apTransactionRef: 't1', invoiceNumber: 'AP-1', reason: 'payee-blocked', detail: 'fraud check' }, { apTransactionRef: 't2', invoiceNumber: 'AP-2', reason: 'not-euro' }],
		}
		http.post.mockResolvedValue({ data: answer })

		await view.byTestId('propose-submit').props.onClick()
		const done = await view.rerender()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-runs/propose', {
			administrationId: 'adm-1',
			dueOnOrBefore: '2026-11-30',
			debtorAccountIban: 'NL91ABNA0417164300',
			executionDate: '2026-12-01',
			payOnDueDate: true,
		})
		expect(done.html).toContain('Draft run PR-7 with 2 payments, total EUR 1500.00.')
		expect(done.byTestId('propose-open-run').props.href).toBe('/index.php/apps/shillinq/bookkeeping/payment-runs/run%201')
		expect(done.find((vnode) => vnode.type === 'li').map(vnodeText)).toEqual(['AP-1: Supplier blocked: fraud check', 'AP-2: Not in euro'])
		expect(done.byTestId('propose-submit')).toBeUndefined()

		plainButton(done).onClick()
		expect(onClose).toHaveBeenCalledWith(answer)
	})

	it('says when no invoice can be paid, from a 422 answer', async () => {
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: { administrationId: 'a', debtorAccountIban: 'NL01' } })
		http.post.mockRejectedValue({ response: { status: 422, data: { paymentRun: null, skipped: [] } } })

		await view.vm.submit()
		const done = await view.rerender()

		expect(vnodeText(done.byTestId('propose-nothing'))).toBe('No invoice can be paid by that date.')
		expect(done.byTestId('propose-skipped')).toBeUndefined()
	})

	it('names a refused proposal and a failed administration load', async () => {
		const view = await renderWithStandIns(ProposePaymentRunModal, { data: { administrationId: 'a', debtorAccountIban: 'NL01' } })
		http.post.mockRejectedValue({ response: { status: 500, data: { message: 'Ledger locked' } } })
		await view.vm.submit()
		expect(vnodeText((await view.rerender()).byTestId('propose-error'))).toBe('Ledger locked')

		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 9 } })
		await ProposePaymentRunModal.mounted.call(view.vm)
		expect(view.vm.administrationId).toBe('9')

		http.get.mockRejectedValueOnce(new Error('down'))
		await ProposePaymentRunModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('The administration could not be loaded.')
	})

	it('closes with null before anything is proposed', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(ProposePaymentRunModal, { props: { onClose } })
		plainButton(view).onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose.mock.calls).toEqual([[null], [null]])
	})
})

describe('CarryOverCommitmentsModal template', () => {
	const preview = {
		fromYear: 2026,
		toYear: 2027,
		total: 1234567,
		lines: [
			{ lineId: 'l1', commitmentNumber: 'C-1', programme: 'Roads', remaining: 1000000 },
			{ lineId: 'l2', commitmentNumber: 'C-2', programme: 'Parks', remaining: 234567 },
		],
		shortfalls: [{ programme: 'Roads', shortfall: 50000, commitments: ['C-1', 'C-9'] }],
	}

	it('lists the lines that continue and the budgets that fall short', async () => {
		const view = await renderWithStandIns(CarryOverCommitmentsModal, { data: { preview } })
		expect(view.html).toContain('2 lines continue in 2027, EUR 12,345.67 in total.')
		expect(view.find((vnode) => vnode.type === 'li').map(vnodeText)).toEqual([
			'C-1, Roads: EUR 10,000.00',
			'C-2, Parks: EUR 2,345.67',
			'Programme Roads is EUR 500.00 short (C-1, C-9).',
		])
		expect(view.html).toContain('Budgets that fall short in 2027')
		expect(view.byTestId('carry-over-submit').props.disabled).toBe(false)
	})

	it('says when the year has no open lines and keeps the carry off', async () => {
		const view = await renderWithStandIns(CarryOverCommitmentsModal, { data: { preview: { fromYear: 2025, toYear: 2026, total: 0, lines: [], shortfalls: [] } } })
		expect(vnodeText(view.byTestId('carry-over-nothing'))).toBe('No open commitment lines in 2025.')
		expect(view.byTestId('carry-over-submit').props.disabled).toBe(true)
	})

	it('reloads the preview when the year changes', async () => {
		const view = await renderWithStandIns(CarryOverCommitmentsModal, { data: { administrationId: 'adm-1' } })
		const year = standInById(view, 'NcTextField', 'carry-over-year')
		year['onUpdate:modelValue']('2025')
		http.get.mockResolvedValue({ data: preview })

		await year.onChange()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/commitments/carry-over', { params: { administrationId: 'adm-1', fromYear: 2025 } })
		expect(view.vm.preview).toEqual(preview)
	})

	it('drops the preview and names a failed load', async () => {
		const view = await renderWithStandIns(CarryOverCommitmentsModal, { data: { preview } })
		http.get.mockRejectedValue({ response: { data: { message: 'No budget' } } })
		await view.vm.load()
		expect(view.vm.preview).toBeNull()
		expect(view.vm.error).toBe('No budget')
	})

	it('loads the administration and its preview when opened', async () => {
		const view = await renderWithStandIns(CarryOverCommitmentsModal)
		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 3 } }).mockResolvedValueOnce({ data: preview })
		await CarryOverCommitmentsModal.mounted.call(view.vm)
		expect(view.vm.administrationId).toBe('3')
		expect(view.vm.preview).toEqual(preview)

		http.get.mockRejectedValueOnce(new Error('down'))
		await CarryOverCommitmentsModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('The administration could not be loaded.')
	})

	it('carries the lines over, then shows the outcome and closes with true', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(CarryOverCommitmentsModal, { props: { onClose }, data: { administrationId: 'adm-1', fromYear: '2026', preview } })
		http.post.mockResolvedValue({ data: preview })

		await view.byTestId('carry-over-submit').props.onClick()
		const done = await view.rerender()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/commitments/carry-over', { administrationId: 'adm-1', fromYear: 2026 })
		expect(vnodeText(done.byTestId('carry-over-done'))).toBe('The open commitments now continue in 2027.')
		expect(standInById(done, 'NcTextField', 'carry-over-year').disabled).toBe(true)
		expect(done.byTestId('carry-over-submit')).toBeUndefined()
		plainButton(done).onClick()
		expect(onClose).toHaveBeenCalledWith(true)
	})

	it('names a refused carry and closes with false', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(CarryOverCommitmentsModal, { props: { onClose }, data: { preview } })
		http.post.mockRejectedValue(new Error('down'))
		await view.vm.submit()
		expect(vnodeText((await view.rerender()).byTestId('carry-over-error'))).toBe('The commitments could not be carried over.')
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledWith(false)
	})
})

describe('MeterReadingImportModal template', () => {
	/**
	 * A change event carrying one file with this text.
	 *
	 * @param {string|null} text The file text, or null for no file.
	 * @return {object} The event.
	 */
	function fileEvent(text) {
		return { target: { files: text === null ? [] : [{ text: async () => text }] } }
	}

	it('reads the chosen file into rows and counts them', async () => {
		const view = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'a' } })
		expect(view.byTestId('meter-reading-submit').props.disabled).toBe(true)
		expect(view.byTestId('meter-reading-count')).toBeUndefined()
		await view.byTestId('meter-reading-file').props.onChange(fileEvent('customerId;resourceType;quantity;periodStart;periodEnd;extra\nc1;water;12;2026-10-01;2026-10-31;x\nc2;gas;3;2026-10-01;2026-10-31;y'))
		const again = await view.rerender()

		expect(view.vm.rows).toEqual([
			{ customerId: 'c1', resourceType: 'water', quantity: '12', periodStart: '2026-10-01', periodEnd: '2026-10-31' },
			{ customerId: 'c2', resourceType: 'gas', quantity: '3', periodStart: '2026-10-01', periodEnd: '2026-10-31' },
		])
		expect(vnodeText(again.byTestId('meter-reading-count'))).toBe('2 rows to import.')
		expect(again.byTestId('meter-reading-submit').props.disabled).toBe(false)
	})

	it('names a file without readings and clears the rows when no file is chosen', async () => {
		const view = await renderWithStandIns(MeterReadingImportModal, { data: { rows: [{ customerId: 'old' }] } })
		await view.byTestId('meter-reading-file').props.onChange(fileEvent('customerId,quantity'))
		expect(view.vm.error).toBe('The file has no readings below its header line.')

		await view.vm.onFile(fileEvent(null))
		expect(view.vm.rows).toEqual([])
		expect(view.vm.error).toBe('')
	})

	it('imports the rows and lists the refused ones', async () => {
		const onClose = vi.fn()
		const rows = [{ customerId: 'c1' }]
		const view = await renderWithStandIns(MeterReadingImportModal, { props: { onClose }, data: { administrationId: 'adm-1', rows } })
		const outcome = { created: ['r1', 'r2'], refused: [{ row: 3, reason: 'unknown customer' }] }
		http.post.mockResolvedValue({ data: outcome })

		await view.byTestId('meter-reading-submit').props.onClick()
		const done = await view.rerender()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/meter-readings/import', { administrationId: 'adm-1', rows })
		expect(vnodeText(done.byTestId('meter-reading-created'))).toBe('2 readings imported. Rate them to see their amount.')
		expect(done.find((vnode) => vnode.type === 'li').map(vnodeText)).toEqual(['Row 3: unknown customer'])
		plainButton(done).onClick()
		expect(onClose).toHaveBeenCalledWith(outcome)
	})

	it('names a refused import and a failed administration load', async () => {
		const view = await renderWithStandIns(MeterReadingImportModal, { data: { administrationId: 'a', rows: [{}] } })
		http.post.mockRejectedValue({ response: { data: { message: 'Too many rows' } } })
		await view.vm.submit()
		expect(vnodeText((await view.rerender()).byTestId('meter-reading-error'))).toBe('Too many rows')

		http.get.mockResolvedValueOnce({ data: { activeAdministrationId: 5 } })
		await MeterReadingImportModal.mounted.call(view.vm)
		expect(view.vm.administrationId).toBe('5')
		http.get.mockRejectedValueOnce(new Error('down'))
		await MeterReadingImportModal.mounted.call(view.vm)
		expect(view.vm.error).toBe('The administration could not be loaded.')
	})
})

describe('MissedDepreciationDialog template', () => {
	it('renders nothing while closed and a loading line while reading', async () => {
		expect((await renderWithStandIns(MissedDepreciationDialog, { props: { open: false, assetId: 'a1' } })).standIns('NcDialog')).toHaveLength(0)
		const loading = await renderWithStandIns(MissedDepreciationDialog, { props: { open: true, assetId: 'a1' } })
		expect(loading.html).toContain('Loading')
		expect(loading.byTestId('missed-depreciation-post').props.disabled).toBe(true)
	})

	it('lists the months with their amounts and total', async () => {
		const view = await renderWithStandIns(MissedDepreciationDialog, {
			props: { open: true, assetId: 'a1' },
			data: { loading: false, rows: [{ period: '2026-09', amount: 100 }, { period: '2026-10', amount: 50.5 }], total: 150.5 },
		})
		const money = (amount) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(amount)
		expect(view.find((vnode) => vnode.type === 'td').map(vnodeText)).toEqual(['2026-09', money(100), '2026-10', money(50.5), money(150.5)])
		expect(view.byTestId('missed-depreciation-post').props.disabled).toBe(false)
	})

	it('says every month is posted, or shows the error', async () => {
		const none = await renderWithStandIns(MissedDepreciationDialog, { props: { open: true, assetId: 'a1' }, data: { loading: false } })
		expect(none.html).toContain('Every month that ended is posted.')
		const failed = await renderWithStandIns(MissedDepreciationDialog, { props: { open: true, assetId: 'a1' }, data: { loading: false, error: 'Asset sold' } })
		expect(failed.html).toContain('Asset sold')
		expect(failed.byTestId('missed-depreciation-rows')).toBeUndefined()
	})

	it('reads the months of the asset when opened', async () => {
		const view = await renderWithStandIns(MissedDepreciationDialog, { props: { open: true, assetId: 'a 1' } })
		http.get.mockResolvedValueOnce({ data: { rows: [{ period: '2026-10', amount: 5 }], total: 5 } })
		await MissedDepreciationDialog.mounted.call(view.vm)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/fixed-assets/a%201/missed-depreciation')
		expect(view.vm.rows).toEqual([{ period: '2026-10', amount: 5 }])
		expect(view.vm.total).toBe(5)
		expect(view.vm.loading).toBe(false)

		http.get.mockRejectedValueOnce({ response: { data: { error: 'Not depreciable' } } })
		await MissedDepreciationDialog.mounted.call(view.vm)
		expect(view.vm.error).toBe('Not depreciable')
	})

	it('posts the months, announces them and closes', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(MissedDepreciationDialog, {
			props: { open: true, assetId: 'a1', onClose },
			data: { loading: false, rows: [{ period: '2026-10', amount: 5 }] },
		})
		http.post.mockResolvedValue({ data: { rows: [{}, {}] } })

		await view.byTestId('missed-depreciation-post').props.onClick()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/fixed-assets/a1/missed-depreciation')
		expect(toast.showSuccess).toHaveBeenCalledWith('Posted 2 months of depreciation.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledTimes(1)
	})

	it('names a refused post and closes from cancel and the dialog', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(MissedDepreciationDialog, {
			props: { open: true, assetId: 'a1', onClose },
			data: { loading: false, rows: [{ period: '2026-10', amount: 5 }] },
		})
		http.post.mockRejectedValue(new Error('down'))
		await view.vm.post()
		expect(view.vm.error).toBe('The missed depreciation could not be posted.')
		expect(view.vm.posting).toBe(false)
		expect(onClose).not.toHaveBeenCalled()

		plainButton(view).onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledTimes(2)
	})
})
