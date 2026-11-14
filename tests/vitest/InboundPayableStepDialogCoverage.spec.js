/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * InboundPayableStepDialog rendered server-side with recording stand-ins
 * (tests/vitest/ssrCapture.js) and an NcDialog stand-in that streams both its
 * body and its #actions slot: the title, summary, fields and confirm label
 * per step, and the confirm / cancel / closing handlers the template bound,
 * which refuse locally, post the step, or report the server's refusal.
 *
 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ssrRenderAttrs, ssrRenderSlot } from 'vue/server-renderer'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcTextField: {} }))

const { default: InboundPayableStepDialog } = await import('../../src/modals/InboundPayableStepDialog.vue')

/**
 * An NcDialog stand-in: records its attributes and its body as vnodes, and
 * streams the body and the #actions slot.
 *
 * @param {object} record Where the attributes go.
 * @return {object}
 */
function dialogStub(record) {
	return {
		name: 'NcDialog',
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			record.dialog = ctx.$attrs
			record.body = [].concat(ctx.$slots.default?.() || [])
			push(`<section${ssrRenderAttrs({ 'data-stub': 'NcDialog', title: ctx.$attrs.name })}>`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('<footer>')
			ssrRenderSlot(ctx.$slots, 'actions', {}, null, push, parent)
			push('</footer></section>')
		},
	}
}

/**
 * Render the dialog with the given props and data.
 *
 * @param {object} props payableId, step and payable.
 * @param {object} [state] Data to lay over the dialog's own.
 * @return {Promise<{html: string, view: object}>}
 */
async function dialog(props, state = {}) {
	const view = session()
	const withProps = {
		...InboundPayableStepDialog,
		props: Object.fromEntries(
			Object.entries(InboundPayableStepDialog.props).map(([key, spec]) => [
				key,
				{ ...spec, required: false, default: () => props[key] ?? null },
			]),
		),
	}
	const html = await view.render(withProps, state, {}, ['NcButton', 'NcTextField', ['NcDialog', dialogStub(view)]])
	view.vm.$.emit = vi.fn()
	return { html: html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, ''), view }
}

const field = (view, label) => view.attrsOf('NcTextField').find((attrs) => attrs.label === label)

beforeEach(() => {
	axios.post.mockReset()
	showSuccess.mockReset()
	emit.mockReset()
})

describe('InboundPayableStepDialog rendering', () => {
	it('approves with a summary line and no fields', async () => {
		const { html, view } = await dialog({
			payableId: 'p-1',
			step: 'approve',
			payable: { payee: { name: 'Acme' }, amount: 12.5, currency: 'USD' },
		})
		expect(view.dialog.name).toBe('Approve payout')
		expect(html).toMatch(/data-testid="inbound-payable-step-summary"[^>]*>\s*USD 12\.50 to Acme\s*</)
		expect(view.attrsOf('NcTextField')).toEqual([])
		expect(view.labelled('NcButton', 'Approve')).toEqual(
			expect.objectContaining({ variant: 'primary', disabled: false }),
		)
		expect(html).not.toContain('role="alert"')
	})

	it('asks the bank reference, account and date when marking paid, defaulting to EUR', async () => {
		const { html, view } = await dialog(
			{ payableId: 'p-1', step: 'paid', payable: { payee: { name: 'Acme' }, amount: 3 } },
			{ bankReference: 'B-9', paidOn: '2026-01-02' },
		)
		expect(view.dialog.name).toBe('Mark payout paid')
		expect(html).toContain('EUR 3.00 to Acme')
		expect(view.attrsOf('NcTextField').map((attrs) => attrs.label)).toEqual([
			'Bank reference',
			'Bank ledger account',
			'Paid on',
		])
		expect(field(view, 'Bank reference').modelValue).toBe('B-9')
		expect(field(view, 'Paid on')).toEqual(
			expect.objectContaining({ type: 'date', modelValue: '2026-01-02', helperText: 'Leave empty for today.' }),
		)
		expect(view.labelled('NcButton', 'Mark paid').variant).toBe('primary')
	})

	it('asks the reason when rejecting, with an error-styled button and the error shown', async () => {
		const { html, view } = await dialog(
			{ payableId: 'p-1', step: 'reject' },
			{ error: 'Nope', submitting: true },
		)
		expect(view.dialog.name).toBe('Reject payout')
		expect(html).not.toContain('inbound-payable-step-summary')
		expect(view.attrsOf('NcTextField').map((attrs) => attrs.label)).toEqual(['Reason'])
		expect(view.labelled('NcButton', 'Reject')).toEqual(
			expect.objectContaining({ variant: 'error', disabled: true }),
		)
		expect(html).toMatch(/role="alert"[^>]*>\s*Nope\s*</)
	})

	it('falls back to a generic title and label for an unknown step, without a summary when the amount is absent', async () => {
		const { html, view } = await dialog({ payableId: 'p-1', step: 'other', payable: { payee: { name: 'Acme' } } })
		expect(view.dialog.name).toBe('Payout')
		expect(view.labelled('NcButton', 'Confirm')).toBeDefined()
		expect(html).not.toContain('inbound-payable-step-summary')
	})
})

describe('InboundPayableStepDialog handlers', () => {
	it('writes the paid fields through the text fields and posts them', async () => {
		axios.post.mockResolvedValue({ data: { id: 'p-1', state: 'paid' } })
		const { view } = await dialog({ payableId: 'p-1', step: 'paid' })

		field(view, 'Bank reference')['onUpdate:modelValue']('B-1')
		field(view, 'Bank ledger account')['onUpdate:modelValue']('1100')
		field(view, 'Paid on')['onUpdate:modelValue']('2026-03-04')
		await view.labelled('NcButton', 'Mark paid').onClick()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/inbound-payables/p-1/paid', {
			bankReference: 'B-1',
			bankAccount: '1100',
			paidOn: '2026-03-04',
		})
		expect(showSuccess).toHaveBeenCalledWith('Payout updated.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(view.vm.$.emit).toHaveBeenCalledWith('close', { id: 'p-1', state: 'paid' })
		expect([view.vm.error, view.vm.submitting]).toEqual(['', false])
	})

	it('binds the same fields when the body renders as vnodes', async () => {
		const { view } = await dialog({ payableId: 'p-1', step: 'paid' })
		const fields = view.body.flatMap((vnode) => (Array.isArray(vnode.children) ? vnode.children : [vnode]))
			.filter((vnode) => vnode.type?.name === 'NcTextField')
		expect(fields.map((vnode) => vnode.props.label)).toEqual(['Bank reference', 'Bank ledger account', 'Paid on'])

		fields.forEach((vnode, index) => vnode.props['onUpdate:modelValue'](['R', 'A', '2026-01-01'][index]))
		expect([view.vm.bankReference, view.vm.bankAccount, view.vm.paidOn]).toEqual(['R', 'A', '2026-01-01'])

		const reject = await dialog({ payableId: 'p-1', step: 'reject' })
		const [reason] = reject.view.body.filter((vnode) => vnode.type?.name === 'NcTextField')
		reason.props['onUpdate:modelValue']('Late')
		expect(reason.props.label).toBe('Reason')
		expect(reject.view.vm.reason).toBe('Late')
	})

	it('refuses a reject without reason before posting, then posts the reason', async () => {
		axios.post.mockResolvedValue({ data: { id: 'p-2', state: 'rejected' } })
		const { view } = await dialog({ payableId: 'p-2', step: 'reject' })

		await view.labelled('NcButton', 'Reject').onClick()
		expect(view.vm.error).toBe('Give a reason for the rejection.')
		expect(axios.post).not.toHaveBeenCalled()

		field(view, 'Reason')['onUpdate:modelValue']('  Duplicate  ')
		await view.labelled('NcButton', 'Reject').onClick()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/inbound-payables/p-2/reject', { reason: 'Duplicate' })
		expect(view.vm.error).toBe('')
	})

	it('names each missing paid value', async () => {
		const { view } = await dialog({ payableId: 'p-1', step: 'paid' }, { bankReference: 'B', bankAccount: '1100', paidOn: '04-03-2026' })
		await view.labelled('NcButton', 'Mark paid').onClick()
		expect(view.vm.error).toBe('The paid date must read YYYY-MM-DD.')

		view.vm.bankAccount = ''
		await view.labelled('NcButton', 'Mark paid').onClick()
		expect(view.vm.error).toBe('Fill in the bank reference and the bank ledger account.')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('refuses an unknown step with the generic sentence', async () => {
		const { view } = await dialog({ payableId: 'p-1', step: 'other' })
		await view.labelled('NcButton', 'Confirm').onClick()
		expect(view.vm.error).toBe('The payout step could not be carried out.')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('shows the server refusal, or the generic sentence, and stays open', async () => {
		const { view } = await dialog({ payableId: 'p-1', step: 'approve' })

		axios.post.mockRejectedValueOnce(Object.assign(new Error('x'), { response: { data: { error: 'Not allowed' } } }))
		await view.labelled('NcButton', 'Approve').onClick()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/inbound-payables/p-1/approve', {})
		expect([view.vm.error, view.vm.submitting]).toEqual(['Not allowed', false])

		axios.post.mockRejectedValueOnce(new Error('down'))
		await view.labelled('NcButton', 'Approve').onClick()
		expect(view.vm.error).toBe('The payout step could not be carried out.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(view.vm.$.emit).not.toHaveBeenCalled()
	})

	it('closes with nothing from Cancel and from the dialog closing', async () => {
		const { view } = await dialog({ payableId: 'p-1', step: 'approve' })
		view.labelled('NcButton', 'Cancel').onClick()
		view.dialog.onClosing()
		expect(view.vm.$.emit.mock.calls).toEqual([['close', null], ['close', null]])
		expect(axios.post).not.toHaveBeenCalled()
	})
})
