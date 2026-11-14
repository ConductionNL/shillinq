/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * RecurringInvoiceProfileModal rendered with recording stand-ins for
 * @nextcloud/vue (tests/vitest/ssrCapture.js): the fields filled from the
 * form, the frequency / issue mode / VAT selects, the line rows, the
 * next-invoice preview with its tokens expanded, the error list and the
 * Cancel / Save buttons, pressed through the handlers the template bound.
 * The clock is fixed so the preview month and default start date hold.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'
import RecurringInvoiceProfileModal from '../../src/modals/RecurringInvoiceProfileModal.vue'
import { session, textOf } from './ssrCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const EURO = (amount) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount)
const line = (over) => ({ description: '', quantity: 1, unitPrice: 0, vatCode: 21, revenueAccount: '', ...over })

/**
 * Render the modal with part of its form replaced.
 *
 * @param {object} props The modal props, plus listeners.
 * @param {object} [state] Data to lay over the modal's own (form merged).
 * @return {Promise<{html: string, view: object, vm: object}>}
 */
async function render(props, state = {}) {
	const view = session()
	let vm = null
	const dialog = {
		name: 'NcDialog',
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			view.stubs.push({ name: 'NcDialog', attrs: ctx.$attrs, text: '' })
			push('<div data-stub="NcDialog">')
			for (const slot of Object.keys(ctx.$slots)) {
				view.slots.push(ctx.$slots[slot]())
				push(`<x-slot data-slot="${slot}">`)
				ssrRenderSlot(ctx.$slots, slot, {}, null, push, parent)
				push('</x-slot>')
			}
			push('</div>')
		},
	}
	const app = createSSRApp(
		{
			...RecurringInvoiceProfileModal,
			components: { NcDialog: dialog, NcButton: view.stub('NcButton', 'button'), NcSelect: view.stub('NcSelect') },
			mixins: [{
				created() {
					vm = this
					const { form, ...rest } = state
					Object.assign(this.$data, rest)
					Object.assign(this.form, form || {})
				},
			}],
		},
		props,
	)
	app.config.warnHandler = () => {}
	const html = (await renderToString(app)).replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, '')
	return { html, view, vm }
}

const select = (view, id) => view.attrsOf('NcSelect').filter((a) => a['data-testid'] === id)
const vnodeSelect = (view, id) => view.vnodes((vnode) => vnode.props?.['data-testid'] === id)

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2026-03-15T12:00:00Z'))
	axios.post.mockReset()
	axios.put.mockReset()
	showSuccess.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('RecurringInvoiceProfileModal rendering', () => {
	it('renders nothing while closed', async () => {
		const { html, view } = await render({ open: false })
		expect(html).not.toContain('recurring-profile')
		expect(view.attrsOf('NcDialog')).toEqual([])
	})

	it('opens on a blank profile: defaults, one line that cannot be removed, a zero preview', async () => {
		const { html, view } = await render({ open: true })

		expect(view.attrsOf('NcDialog')[0]).toMatchObject({ name: 'Recurring invoice profile', size: 'large' })
		expect(html).toMatch(/<input id="rip-name" value="" type="text" class="rip__input" data-testid="rip-name" placeholder="e.g. Hosting Acme">/)
		expect(html).toMatch(/<input id="rip-start-date" value="2026-03-15" type="date"/)
		expect(html).toMatch(/<input id="rip-payment-terms" value="30" type="number"/)
		expect(select(view, 'rip-frequency')[0]).toMatchObject({ inputLabel: 'Frequency', modelValue: { value: 'monthly', display: 'Monthly' } })
		expect(select(view, 'rip-issue-mode')[0].modelValue).toEqual({ value: 'draft-for-review', display: 'Draft for review' })
		expect(select(view, 'rip-line-vat').map((a) => a.modelValue)).toEqual([{ value: 21, display: '21%' }])
		expect(html.match(/data-testid="rip-line"/g)).toHaveLength(1)
		expect(html).toMatch(/<button type="button" class="rip__line-remove" disabled/)
		expect(html).toMatch(/<ul class="rip__preview-list"><\/ul>/)
		expect(html).toContain(`Per period (net): ${EURO(0)}`)
		expect(html).not.toContain('rip-errors')
		expect(view.labelled('NcButton', 'Save profile').disabled).toBe(false)
		expect(view.labelled('NcButton', 'Cancel').disabled).toBe(false)
	})

	it('shows the filled form, the lines and the preview with the tokens expanded', async () => {
		const { html, view } = await render({ open: true }, {
			form: {
				name: 'Hosting Acme',
				customerReference: 'acme',
				frequency: 'quarterly',
				interval: 2,
				invoiceDay: 5,
				issueMode: 'auto-issue',
				lines: [
					line({ description: 'Hosting {month} {year}', quantity: 2, unitPrice: 50, vatCode: 9 }),
					line({ description: 'Support {period}', quantity: 1, unitPrice: 20.5, vatCode: 0 }),
					line({ description: '   ', unitPrice: 0, vatCode: 'x' }),
				],
			},
		})

		expect(html).toMatch(/id="rip-name" value="Hosting Acme"/)
		expect(html).toMatch(/id="rip-customer" value="acme"/)
		expect(html).toMatch(/id="rip-interval" value="2"/)
		expect(html).toMatch(/id="rip-invoice-day" value="5"/)
		expect(select(view, 'rip-frequency')[0].modelValue.value).toBe('quarterly')
		expect(select(view, 'rip-issue-mode')[0].modelValue.value).toBe('auto-issue')
		expect(select(view, 'rip-line-vat').map((a) => a.modelValue.value)).toEqual([9, 0, 21])
		expect(html).toMatch(/value="Hosting \{month\} \{year\}" type="text" class="rip__input rip__input--desc"/)
		expect(html).toMatch(/class="rip__line-remove" aria-label="Remove line"/)
		const preview = [...html.matchAll(/<li>\s*([^<]*?)\s*<\/li>/g)].map((m) => m[1])
		expect(preview).toEqual(['Hosting March 2026', 'Support March 2026'])
		expect(html).toContain(`Per period (net): ${EURO(120.5)}`)
	})

	it('lists the errors, and disables both buttons while saving', async () => {
		const { html, view } = await render({ open: true }, { errors: ['A profile name is required.', 'A customer is required.'], saving: true })

		expect(html).toMatch(/<ul class="rip__errors" data-testid="rip-errors"><li>A profile name is required\.<\/li><li>A customer is required\.<\/li><\/ul>/)
		const save = view.labelled('NcButton', 'Saving…')
		expect([save.disabled, view.labelled('NcButton', 'Cancel').disabled]).toEqual([true, true])
	})
})

describe('RecurringInvoiceProfileModal handlers', () => {
	it('sets frequency, issue mode and VAT through the selects, keeping the old value on a clear', async () => {
		const { view, vm } = await render({ open: true }, { form: { lines: [line(), line()] } })

		select(view, 'rip-frequency')[0]['onUpdate:modelValue']({ value: 'weekly' })
		select(view, 'rip-issue-mode')[0]['onUpdate:modelValue']({ value: 'auto-issue' })
		select(view, 'rip-issue-mode')[0]['onUpdate:modelValue'](null)
		select(view, 'rip-line-vat')[0]['onUpdate:modelValue']({ value: '9' })
		select(view, 'rip-line-vat')[1]['onUpdate:modelValue'](null)

		expect([vm.form.frequency, vm.form.issueMode]).toEqual(['weekly', 'auto-issue'])
		expect(vm.form.lines.map((l) => l.vatCode)).toEqual([9, 21])
	})

	it('sets them through the vnodes the dialog slot builds too', async () => {
		const { view, vm } = await render({ open: true }, { form: { lines: [line({ vatCode: 9 })] } })

		vnodeSelect(view, 'rip-frequency')[0].props['onUpdate:modelValue']({ value: 'annually' })
		vnodeSelect(view, 'rip-issue-mode')[0].props['onUpdate:modelValue']({ value: 'auto-issue' })
		vnodeSelect(view, 'rip-line-vat')[0].props['onUpdate:modelValue']({ value: 0 })

		expect([vm.form.frequency, vm.form.issueMode, vm.form.lines[0].vatCode]).toEqual(['annually', 'auto-issue', 0])
	})

	it('writes the inputs back and adds and removes lines through the slot vnodes', async () => {
		const { view, vm } = await render({ open: true }, { form: { lines: [line({ description: 'A' }), line({ description: 'B' })] } })
		const input = (id) => view.vnodes((vnode) => vnode.props?.['data-testid'] === id)
		const write = (id, value, index = 0) => input(id)[index].props['onUpdate:modelValue'](value)

		write('rip-name', 'Hosting')
		write('rip-customer', 'acme')
		write('rip-interval', 3)
		write('rip-invoice-day', 28)
		write('rip-start-date', '2026-04-01')
		write('rip-payment-terms', 14)
		write('rip-line-description', 'B2', 1)
		write('rip-line-quantity', 4, 1)
		write('rip-line-unit-price', 12.5, 1)

		expect(vm.form).toMatchObject({ name: 'Hosting', customerReference: 'acme', interval: 3, invoiceDay: 28, startDate: '2026-04-01', paymentTermsDays: 14 })
		expect(vm.form.lines[1]).toMatchObject({ description: 'B2', quantity: 4, unitPrice: 12.5 })

		input('rip-add-line')[0].props.onClick()
		expect(vm.form.lines.map((l) => l.description)).toEqual(['A', 'B2', ''])
		input('rip-line-remove')[0].props.onClick()
		expect(vm.form.lines.map((l) => l.description)).toEqual(['B2', ''])
	})

	it('closes and saves through the action vnodes', async () => {
		const onClose = vi.fn()
		const { view, vm } = await render({ open: true, onClose })
		const buttons = view.vnodes((vnode) => vnode.type?.name === 'NcButton')

		expect(buttons.map((b) => b.props['data-testid'])).toEqual(['rip-cancel', 'rip-save'])
		buttons[0].props.onClick()
		expect(onClose).toHaveBeenCalledTimes(1)
		await buttons[1].props.onClick()
		expect(vm.errors).toHaveLength(3)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('labels the Save vnode while saving', async () => {
		const { view } = await render({ open: true }, { saving: true })
		const [, save] = view.vnodes((vnode) => vnode.type?.name === 'NcButton')
		expect([save.props.disabled, textOf(save.children.default())]).toEqual([true, 'Saving…'])
	})

	it('closes through Cancel and the dialog closing, but not while saving', async () => {
		const onClose = vi.fn()
		const { view } = await render({ open: true, onClose })
		view.labelled('NcButton', 'Cancel').onClick()
		view.attrsOf('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledTimes(2)

		const busy = vi.fn()
		const saving = await render({ open: true, onClose: busy }, { saving: true })
		saving.view.labelled('NcButton', 'Cancel').onClick()
		expect(busy).not.toHaveBeenCalled()
	})

	it('refuses an incomplete profile through Save without a request', async () => {
		const { view, vm } = await render({ open: true })
		await view.labelled('NcButton', 'Save profile').onClick()

		expect(vm.errors).toEqual(['A profile name is required.', 'A customer is required.', 'At least one line with a description and a price is required.'])
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('creates the profile through Save, then reports saved and closes', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const onSaved = vi.fn()
		const onClose = vi.fn()
		const { view, vm } = await render({ open: true, onSaved, onClose }, {
			form: { name: ' Hosting ', customerReference: 'acme', lines: [line({ description: 'Hosting {month}', unitPrice: 10 })] },
		})

		await view.labelled('NcButton', 'Save profile').onClick()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, payload] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/shillinq/RecurringInvoiceProfile')
		expect(payload).toMatchObject({ name: 'Hosting', customerReference: 'acme', frequency: 'monthly', interval: 1 })
		expect(showSuccess).toHaveBeenCalledWith('Recurring profile created.')
		expect([onSaved.mock.calls.length, onClose.mock.calls.length, vm.saving, vm.errors]).toEqual([1, 1, false, []])
	})
})
