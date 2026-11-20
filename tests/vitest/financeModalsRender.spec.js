/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The dunning next-run, payment-run proposal and commitment carry-over
 * dialogs: what each renders (server-side, no DOM) in each state, and
 * what their loads and submits send and do with the answer.
 *
 * @spec openspec/specs/dunning/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const toasts = vi.hoisted(() => ({ success: [] }))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (m) => toasts.success.push(m), showError: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'label', 'disabled', 'modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-label': this.label, 'data-testid': this.$attrs['data-testid'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return { NcDialog: slotted('NcDialog'), NcButton: slotted('NcButton', 'button'), NcTextField: slotted('NcTextField'), NcCheckboxRadioSwitch: slotted('NcCheckboxRadioSwitch') }
})

const { default: DunningNextRunModal } = await import('../../src/modals/DunningNextRunModal.vue')
const { default: ProposePaymentRunModal } = await import('../../src/modals/ProposePaymentRunModal.vue')
const { default: CarryOverCommitmentsModal } = await import('../../src/modals/CarryOverCommitmentsModal.vue')

/**
 * Render a dialog with its data replaced.
 *
 * @param {object} view The component.
 * @param {object} state The data.
 * @param {object} props The props.
 * @return {Promise<string>} The HTML.
 */
function render(view, state, props = {}) {
	return renderToString(createSSRApp({ ...view, data: () => state }, props))
}

/**
 * A plain instance of a dialog, its computed values readable.
 *
 * @param {object} view The component.
 * @param {object} over Props and data to set first.
 * @return {object} The instance.
 */
function instance(view, over = {}) {
	const self = { ...over, $emit: vi.fn() }
	Object.assign(self, view.data.call(self), over)
	for (const [name, method] of Object.entries(view.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(view.computed ?? {})) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.patch.mockReset()
	toasts.success = []
})

describe('DunningNextRunModal', () => {
	const administration = (over = {}) => ({ administrationId: 'adm-1', id: 'obj-1', name: 'Gemeente', enabled: false, lastRun: null, rows: [], ...over })

	it('renders each administration with its switch state, last run and reminders', async () => {
		const html = await render(DunningNextRunModal, {
			administrations: [
				administration({ enabled: true, lastRun: { ranAt: '2026-11-02T03:00:00Z', locked: true }, rows: [{ invoiceId: 'i-1', invoiceNumber: 'F-1', customer: 'Jansen', dueDate: '2026-10-01', stageNr: 2, channel: 'EMAIL' }] }),
				administration({ administrationId: 'adm-2', name: '' }),
			],
			loading: false,
			submitting: false,
			error: '',
		})
		expect(html).toContain('data-name="Next run"')
		expect(html).toContain('Automatic reminders are on.')
		expect(html).toContain('Last run 2026-11-02: skipped, another run was still busy.')
		expect(html).toMatch(/<td[^>]*>F-1<\/td><td[^>]*>Jansen<\/td><td[^>]*>2026-10-01<\/td><td[^>]*>2<\/td><td[^>]*>Email<\/td>/)
		expect(html).toMatch(/<h3[^>]*>adm-2<\/h3>/)
		expect(html).toContain('Automatic reminders are off.')
		expect(html).toContain('The daily run has not run for this administration yet.')
		expect(html).toContain('No invoice gets a reminder in the next run.')
		expect(html).toContain('>Close<')
		expect(html).not.toContain('dunning-switch-on-submit')
	})

	it('offers switching on only for a switched-off administration', async () => {
		const off = await render(DunningNextRunModal, { administrations: [administration()], loading: false, submitting: false, error: '' }, { switchOn: true })
		expect(off).toContain('data-name="Check the next run, then switch reminders on"')
		expect(off).toMatch(/<button[^>]*data-testid="dunning-switch-on-submit"(?![^>]*disabled)/)
		expect(off).toContain('>Cancel<')
		const on = await render(DunningNextRunModal, { administrations: [administration({ enabled: true })], loading: false, submitting: false, error: '' }, { switchOn: true })
		expect(on).toMatch(/<button[^>]*data-testid="dunning-switch-on-submit"[^>]*disabled/)
	})

	it('renders the loading, failed and empty states', async () => {
		expect(await render(DunningNextRunModal, { administrations: [], loading: true, submitting: false, error: '' })).toContain('data-testid="dunning-next-run-loading"')
		const failed = await render(DunningNextRunModal, { administrations: [], loading: false, submitting: false, error: 'Nope' })
		expect(failed).toMatch(/role="alert"[^>]*>\s*Nope/)
		expect(failed).not.toContain('You have no administration to show.')
		expect(await render(DunningNextRunModal, { administrations: [], loading: false, submitting: false, error: '' })).toContain('You have no administration to show.')
	})

	it('loads the next run for one administration and reports a failure', async () => {
		const dialog = instance(DunningNextRunModal, { administrationId: 'adm-1', switchOn: false })
		axios.get.mockResolvedValueOnce({ data: { administrations: [administration()] } })
		await dialog.load()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/dunning/next-run', { params: { administrationId: 'adm-1' } })
		expect([dialog.administrations.length, dialog.loading]).toEqual([1, false])

		axios.get.mockRejectedValueOnce({ response: { data: { message: 'Not yours' } } })
		await dialog.load()
		expect([dialog.error, dialog.loading]).toEqual(['Not yours', false])
	})

	it('switches reminders on for the shown administration, then closes', async () => {
		const dialog = instance(DunningNextRunModal, { administrationId: 'adm-1', switchOn: true, administrations: [administration()] })
		axios.patch.mockResolvedValueOnce({ data: {} })
		await dialog.submit()
		expect(axios.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/Administration/obj-1', { dunningEnabled: true })
		expect(toasts.success).toEqual(['Reminders are on. The next daily run sends the list above.'])
		expect(dialog.$emit).toHaveBeenCalledWith('close', true)

		axios.patch.mockRejectedValueOnce(new Error('x'))
		await dialog.submit()
		expect([dialog.error, dialog.submitting]).toEqual(['Reminders could not be switched on.', false])
		dialog.onClose()
		expect(dialog.$emit).toHaveBeenLastCalledWith('close', false)

		const empty = instance(DunningNextRunModal, { administrationId: '', switchOn: true })
		await empty.submit()
		expect(axios.patch).toHaveBeenCalledTimes(2)
	})
})

describe('ProposePaymentRunModal', () => {
	const form = { administrationId: 'adm-1', dueOnOrBefore: '2026-12-01', executionDate: '2026-12-02', debtorAccountIban: 'nl91 abna 0417 1643 00', payOnDueDate: false, submitting: false, result: null, error: '' }

	it('renders the form until there is a result, the propose button gated', async () => {
		const html = await render(ProposePaymentRunModal, form)
		expect(html).toContain('data-label="Pay from IBAN"')
		expect(html).toMatch(/data-testid="propose-submit"(?![^>]*disabled)/)
		expect(await render(ProposePaymentRunModal, { ...form, debtorAccountIban: ' ' })).toMatch(/data-testid="propose-submit"[^>]*disabled/)
	})

	it('renders the created run with its link and what was left out', async () => {
		const html = await render(ProposePaymentRunModal, {
			...form,
			result: { paymentRun: { id: 'run 1', runNumber: 'BR-7', paymentLines: [1, 2], totalAmount: '150.5' }, skipped: [{ apTransactionRef: 'ap-1', invoiceNumber: 'F-9', reason: 'invoice-blocked', detail: 'dispute' }] },
		})
		expect(html).toContain('Draft run BR-7 with 2 payments, total EUR 150.50.')
		expect(html).toContain('href="/index.php/apps/shillinq/bookkeeping/payment-runs/run%201"')
		expect(html).toContain('F-9: Invoice blocked: dispute')
		expect(html).not.toContain('propose-submit')
		const nothing = await render(ProposePaymentRunModal, { ...form, result: { skipped: [] }, error: 'Late' })
		expect(nothing).toContain('No invoice can be paid by that date.')
		expect(nothing).toMatch(/role="alert"[^>]*>\s*Late/)
	})

	it('proposes with the normalised IBAN, takes a refusal as the result, and reports a failure', async () => {
		const dialog = instance(ProposePaymentRunModal, form)
		axios.post.mockResolvedValueOnce({ data: { paymentRun: { id: 'r' } } })
		await dialog.submit()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/payment-runs/propose', {
			administrationId: 'adm-1', dueOnOrBefore: '2026-12-01', debtorAccountIban: 'NL91ABNA0417164300', executionDate: '2026-12-02', payOnDueDate: false,
		})
		expect([dialog.result, dialog.submitting]).toEqual([{ paymentRun: { id: 'r' } }, false])

		axios.post.mockRejectedValueOnce({ response: { status: 422, data: { skipped: [] } } })
		await dialog.submit()
		expect(dialog.result).toEqual({ skipped: [] })
		axios.post.mockRejectedValueOnce({ response: { status: 500, data: {} } })
		await dialog.submit()
		expect(dialog.error).toBe('The payment run could not be proposed.')
		dialog.close()
		expect(dialog.$emit).toHaveBeenCalledWith('close', { skipped: [] })
	})

	it('reads the active administration on open', async () => {
		const dialog = instance(ProposePaymentRunModal)
		axios.get.mockResolvedValueOnce({ data: { activeAdministrationId: 42 } })
		await ProposePaymentRunModal.mounted.call(dialog)
		expect(dialog.administrationId).toBe('42')
		axios.get.mockRejectedValueOnce({ response: { data: { message: 'No context' } } })
		await ProposePaymentRunModal.mounted.call(dialog)
		expect(dialog.error).toBe('No context')
	})
})

describe('CarryOverCommitmentsModal', () => {
	const preview = { fromYear: 2026, toYear: 2027, total: 123456, lines: [{ lineId: 'l-1', commitmentNumber: 'C-1', programme: 'P1', remaining: 100000 }], shortfalls: [{ programme: 'P1', shortfall: 5000, commitments: ['C-1', 'C-2'] }] }
	const state = { administrationId: 'adm-1', fromYear: '2026', preview: null, done: false, submitting: false, error: '' }

	it('renders the lines, the total and the budget shortfalls', async () => {
		const html = await render(CarryOverCommitmentsModal, { ...state, preview })
		expect(html).toContain('1 lines continue in 2027, EUR 1,234.56 in total.')
		expect(html).toMatch(/C-1, P1: EUR\s+1,000.00/)
		expect(html).toContain('Programme P1 is EUR 50.00 short (C-1, C-2).')
		expect(html).toMatch(/data-testid="carry-over-submit"(?![^>]*disabled)/)
	})

	it('renders nothing to carry, the finished state and a failure', async () => {
		const nothing = await render(CarryOverCommitmentsModal, { ...state, preview: { ...preview, lines: [], shortfalls: [] } })
		expect(nothing).toContain('No open commitment lines in 2026.')
		expect(nothing).toMatch(/data-testid="carry-over-submit"[^>]*disabled/)
		const done = await render(CarryOverCommitmentsModal, { ...state, preview: { ...preview, shortfalls: [] }, done: true, error: 'Oops' })
		expect(done).toContain('The open commitments now continue in 2027.')
		expect(done).not.toContain('carry-over-shortfalls')
		expect(done).not.toContain('carry-over-submit')
		expect(done).toMatch(/role="alert"[^>]*>\s*Oops/)
	})

	it('previews for the year, carries over and reports failures', async () => {
		const dialog = instance(CarryOverCommitmentsModal, state)
		axios.get.mockResolvedValueOnce({ data: preview })
		await dialog.load()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/commitments/carry-over', { params: { administrationId: 'adm-1', fromYear: 2026 } })
		expect(dialog.preview).toEqual(preview)

		axios.get.mockRejectedValueOnce(new Error('x'))
		await dialog.load()
		expect([dialog.preview, dialog.error]).toEqual([null, 'The open commitments could not be loaded.'])

		axios.post.mockResolvedValueOnce({ data: preview })
		await dialog.submit()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/commitments/carry-over', { administrationId: 'adm-1', fromYear: 2026 })
		expect([dialog.done, dialog.submitting, dialog.error]).toEqual([true, false, ''])
		dialog.close()
		expect(dialog.$emit).toHaveBeenCalledWith('close', true)

		axios.post.mockRejectedValueOnce({ response: { data: { message: 'Closed year' } } })
		await dialog.submit()
		expect(dialog.error).toBe('Closed year')
	})

	it('reads the administration and previews on open', async () => {
		const dialog = instance(CarryOverCommitmentsModal)
		axios.get.mockResolvedValueOnce({ data: { activeAdministrationId: 'adm-3' } }).mockResolvedValueOnce({ data: preview })
		await CarryOverCommitmentsModal.mounted.call(dialog)
		expect([dialog.administrationId, dialog.preview]).toEqual(['adm-3', preview])
		axios.get.mockRejectedValueOnce(new Error('x'))
		await CarryOverCommitmentsModal.mounted.call(dialog)
		expect(dialog.error).toBe('The administration could not be loaded.')
	})
})
