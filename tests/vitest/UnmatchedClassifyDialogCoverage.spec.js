/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * UnmatchedClassifyDialog rendered with recording stand-ins for
 * @nextcloud/vue (tests/vitest/ssrCapture.js): the title per
 * classification, the selection count, the reason field, the error line,
 * and the Cancel / Classify buttons pressed through the handlers the
 * template bound, with the classification call they lead to.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'
import UnmatchedClassifyDialog from '../../src/modals/UnmatchedClassifyDialog.vue'
import { classifyUnmatchedItems } from '../../src/utils/bankMatchApi.js'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcTextField: {} }))
vi.mock('../../src/utils/bankMatchApi.js', () => ({ classifyUnmatchedItems: vi.fn() }))

/**
 * Render the dialog with recording stubs.
 *
 * @param {object} props The dialog props, plus listeners (onClose).
 * @param {object} [state] Data to lay over the dialog's own.
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
				// The vnode branch of the slot too, so its bound handlers can be reached.
				view.slots.push(ctx.$slots[slot]())
				push(`<section data-slot="${slot}">`)
				ssrRenderSlot(ctx.$slots, slot, {}, null, push, parent)
				push('</section>')
			}
			push('</div>')
		},
	}
	const app = createSSRApp(
		{
			...UnmatchedClassifyDialog,
			components: { NcDialog: dialog, NcButton: view.stub('NcButton', 'button'), NcTextField: view.stub('NcTextField') },
			mixins: [{ created() { vm = this; Object.assign(this.$data, state) } }],
		},
		props,
	)
	app.config.warnHandler = () => {}
	const html = await renderToString(app)
	return { html: html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, ''), view, vm }
}

const SELECTED = ['m1', 'm2']

describe('UnmatchedClassifyDialog rendering', () => {
	beforeEach(() => {
		classifyUnmatchedItems.mockReset()
		showSuccess.mockReset()
		emit.mockReset()
	})

	it('titles the dialog after the classification, with a plain fallback', async () => {
		const titles = []
		for (const status of ['timing', 'pending', 'adjustment', 'other']) {
			const { view } = await render({ selectedIds: SELECTED, resolutionStatus: status })
			titles.push(view.attrsOf('NcDialog')[0].name)
		}
		expect(titles).toEqual(['Classify as timing', 'Classify as pending', 'Classify as adjustment', 'Classify'])
	})

	it('counts the selection, labels the reason and keeps Classify off without a reason', async () => {
		const { html, view } = await render({ selectedIds: SELECTED, resolutionStatus: 'timing' })

		expect(html).toMatch(/<p>\s*2 items selected\.\s*<\/p>/)
		expect(view.attrsOf('NcTextField')[0]).toMatchObject({ label: 'Reason', modelValue: '' })
		expect(view.labelled('NcButton', 'Classify').disabled).toBe(true)
		expect(view.labelled('NcButton', 'Cancel')).toBeDefined()
		expect(html).not.toContain('role="alert"')
	})

	it('enables Classify for a reason, and disables it again while submitting', async () => {
		const ready = await render({ selectedIds: SELECTED, resolutionStatus: 'pending' }, { reason: 'bank fee' })
		const busy = await render({ selectedIds: SELECTED, resolutionStatus: 'pending' }, { reason: 'bank fee', submitting: true })
		const blank = await render({ selectedIds: SELECTED, resolutionStatus: 'pending' }, { reason: '   ' })

		expect([
			ready.view.labelled('NcButton', 'Classify').disabled,
			busy.view.labelled('NcButton', 'Classify').disabled,
			blank.view.labelled('NcButton', 'Classify').disabled,
		]).toEqual([false, true, true])
	})

	it('shows the error line', async () => {
		const { html } = await render({ selectedIds: SELECTED, resolutionStatus: 'timing' }, { error: 'Locked period' })
		expect(html).toMatch(/role="alert" data-testid="unmatched-classify-error">\s*Locked period\s*</)
	})

	it('closes with null through Cancel and through the dialog closing', async () => {
		const onClose = vi.fn()
		const { view } = await render({ selectedIds: SELECTED, resolutionStatus: 'timing', onClose })

		view.labelled('NcButton', 'Cancel').onClick()
		view.attrsOf('NcDialog')[0].onClosing()

		expect(onClose.mock.calls).toEqual([[null], [null]])
		expect(classifyUnmatchedItems).not.toHaveBeenCalled()
	})

	it('classifies with the typed reason through the Classify button', async () => {
		classifyUnmatchedItems.mockResolvedValue({ applied: 2, failed: 0 })
		const onClose = vi.fn()
		const { view, vm } = await render({ selectedIds: SELECTED, resolutionStatus: 'adjustment', onClose })

		view.attrsOf('NcTextField')[0]['onUpdate:modelValue'](' rounding ')
		expect(vm.reason).toBe(' rounding ')
		const [field] = view.vnodes((vnode) => vnode.type?.name === 'NcTextField')
		field.props['onUpdate:modelValue']('  rounding  ')
		expect(vm.reason).toBe('  rounding  ')
		await view.labelled('NcButton', 'Classify').onClick()

		expect(classifyUnmatchedItems).toHaveBeenCalledWith(SELECTED, 'adjustment', 'rounding')
		expect(showSuccess).toHaveBeenCalledWith('2 items classified.')
		expect(emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(onClose).toHaveBeenCalledWith({ applied: 2, failed: 0 })
	})
})
