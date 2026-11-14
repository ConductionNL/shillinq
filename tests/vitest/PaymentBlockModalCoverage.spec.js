/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * PaymentBlockModal.vue driven through its template: the block and release
 * wording, the reason field, the error line, and the buttons and dialog
 * close wired to the real handlers. Only HTTP, the toast, the event bus and
 * the @nextcloud/vue components are replaced; src/utils/paymentRunApi.js runs.
 *
 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/specs/payment-control-guards/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
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
		NcDialog: standIn('NcDialog'),
		NcTextField: standIn('NcTextField'),
	}
})

const { default: PaymentBlockModal } = await import('../../src/modals/PaymentBlockModal.vue')

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
	return [].concat(vnode.children.default()).map(vnodeText).join(' ').trim()
}

beforeEach(() => {
	http.patch.mockReset()
	toast.showSuccess.mockReset()
	bus.emit.mockReset()
})

describe('PaymentBlockModal template', () => {
	it('renders nothing while closed', async () => {
		const view = await renderWithStandIns(PaymentBlockModal, { props: { schema: 'APTransaction', objectId: 'ap-1' } })
		expect(view.standIns('NcDialog')).toEqual([])
		expect(view.html).not.toContain('Block payment')
	})

	it('asks a reason to block and patches the invoice with it', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(PaymentBlockModal, { props: { open: true, schema: 'APTransaction', objectId: 'ap 1', onClose } })

		expect(view.standIns('NcDialog')[0].name).toBe('Block payment')
		expect(view.html).toContain('No payment run pays this while the block is on.')
		expect(slotText(view.byTestId('payment-block-submit'))).toBe('Block payment')
		expect(view.byTestId('payment-block-submit').props.disabled).toBe(false)

		standInById(view, 'NcTextField', 'payment-block-reason')['onUpdate:modelValue']('  fraud check  ')
		expect(view.vm.reason).toBe('  fraud check  ')
		http.patch.mockResolvedValue({ data: {} })

		await view.byTestId('payment-block-submit').props.onClick()

		expect(http.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/APTransaction/ap%201', { paymentBlocked: true, paymentBlockReason: 'fraud check' })
		expect(toast.showSuccess).toHaveBeenCalledWith('Payment blocked')
		expect(bus.emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'APTransactionDetail' })
		expect(onClose).toHaveBeenCalledWith({ paymentBlocked: true, paymentBlockReason: 'fraud check' })
	})

	it('refuses a block without a reason and shows why', async () => {
		const view = await renderWithStandIns(PaymentBlockModal, { props: { open: true, schema: 'Payee', objectId: 'p-1' } })

		await view.byTestId('payment-block-submit').props.onClick()
		const done = await view.rerender()

		expect(http.patch).not.toHaveBeenCalled()
		expect(vnodeText(done.byTestId('payment-block-error'))).toBe('Give a reason for the payment block.')
	})

	it('releases a supplier with a confirmation only, and names a failed save', async () => {
		const onClose = vi.fn()
		const view = await renderWithStandIns(PaymentBlockModal, { props: { open: true, schema: 'Payee', mode: 'release', objectId: 'p-2', onClose } })

		expect(view.standIns('NcDialog')[0].name).toBe('Release payment')
		expect(view.html).toContain('Payment runs can pay this again from the next proposal on.')
		expect(standInById(view, 'NcTextField', 'payment-block-reason')).toBeUndefined()
		expect(slotText(view.byTestId('payment-block-submit'))).toBe('Release payment')

		http.patch.mockRejectedValueOnce({ response: { data: { message: 'Locked' } } })
		await view.byTestId('payment-block-submit').props.onClick()
		expect(vnodeText((await view.rerender()).byTestId('payment-block-error'))).toBe('Locked')
		expect(onClose).not.toHaveBeenCalled()

		http.patch.mockResolvedValueOnce({ data: {} })
		await view.byTestId('payment-block-submit').props.onClick()
		expect(http.patch).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/shillinq/Payee/p-2', { paymentBlocked: false, paymentBlockReason: null })
		expect(toast.showSuccess).toHaveBeenCalledWith('Payment released')
		expect(bus.emit).toHaveBeenCalledWith('cn:widget:refresh', { widget: 'PayeeDetail' })
		expect(onClose).toHaveBeenCalledWith({ paymentBlocked: false, paymentBlockReason: null })
	})

	it('disables the submit while saving and closes without saving from Cancel and the dialog', async () => {
		const onClose = vi.fn()
		const busy = await renderWithStandIns(PaymentBlockModal, { props: { open: true, schema: 'Payee' }, data: { submitting: true } })
		expect(busy.byTestId('payment-block-submit').props.disabled).toBe(true)

		const view = await renderWithStandIns(PaymentBlockModal, { props: { open: true, schema: 'Payee', onClose } })
		view.find((vnode) => vnode.props && !vnode.props['data-testid'] && typeof vnode.props.onClick === 'function')[0].props.onClick()
		view.standIns('NcDialog')[0].onClosing()
		expect(onClose.mock.calls).toEqual([[], []])
		expect(http.patch).not.toHaveBeenCalled()
	})

	it('server-renders each mode with its wording, field and error', async () => {
		const release = await renderWithStandIns(PaymentBlockModal, { slots: 'ssr', props: { open: true, schema: 'Payee', mode: 'release' }, data: { error: 'Locked' } })
		expect(release.html).toContain('Payment runs can pay this again from the next proposal on.')
		expect(release.html).toMatch(/data-testid="payment-block-error"[^>]*>\s*Locked/)
		expect(release.html).toMatch(/Cancel[\s\S]*Release payment/)
		expect(release.html).not.toContain('payment-block-reason')

		const block = await renderWithStandIns(PaymentBlockModal, { slots: 'ssr', props: { open: true, schema: 'Payee' } })
		expect(block.html).toContain('No payment run pays this while the block is on.')
		expect(block.html).toMatch(/Cancel[\s\S]*Block payment/)
		expect(block.html).not.toContain('payment-block-error')
		expect(block.standIns('NcTextField')[0]['data-testid']).toBe('payment-block-reason')
	})

	it('falls back to the route id, and saves nothing without an id', async () => {
		http.patch.mockResolvedValue({ data: {} })
		const routed = await renderWithStandIns(PaymentBlockModal, {
			props: { open: true, schema: 'APTransaction', mode: 'release' },
			globals: { $route: { params: { id: 'ap-9' } } },
		})
		await routed.byTestId('payment-block-submit').props.onClick()
		expect(http.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/APTransaction/ap-9', { paymentBlocked: false, paymentBlockReason: null })

		const orphan = await renderWithStandIns(PaymentBlockModal, { props: { open: true, schema: 'APTransaction', mode: 'release' } })
		await orphan.byTestId('payment-block-submit').props.onClick()
		expect(orphan.vm.error).toBe('The payment block could not be saved.')
		expect(http.patch).toHaveBeenCalledTimes(1)
	})
})
