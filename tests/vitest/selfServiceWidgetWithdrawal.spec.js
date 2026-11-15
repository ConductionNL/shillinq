/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The withdrawal link on the booking widget's confirmation step
 * (sales-cancellation REQ-SCX-004, task 3.4): the signed link the booking
 * answer carries is shown with its last day, in the partner's translation,
 * and an answer without a usable link shows none. Rendered server-side, so
 * no DOM is needed; only fetch's Response is stood in for.
 */

import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import SelfServiceWidget from '../../src/components/widget/SelfServiceWidget.vue'

const PROPS = { businessId: 'biz-1', apiBase: 'https://api.example.org/apps/shillinq/', apiKey: 'key-1', lang: 'nl' }
const LINK = { url: 'https://portal.example.org/herroepen?token=abc.def', periodEndsOn: '2026-10-04' }

/**
 * Render the widget with its data replaced by `state`.
 *
 * @param {object} state The data to render with.
 * @param {object} props Extra props.
 * @return {Promise<string>} The HTML.
 */
async function render(state, props = {}) {
	const app = createSSRApp({ ...SelfServiceWidget, data() { return { ...SelfServiceWidget.data.call(this), ...state } } }, { ...PROPS, ...props })
	return renderToString(app)
}

/**
 * A stand-in widget for calling its methods.
 *
 * @param {object} translations The partner's translation table.
 * @return {object} The widget.
 */
function widget(translations = {}) {
	const vm = { ...PROPS, translations }
	for (const [name, fn] of Object.entries(SelfServiceWidget.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, SelfServiceWidget.data.call(vm))
	for (const [name, fn] of Object.entries(SelfServiceWidget.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	return vm
}

/**
 * A fetch response whose body is `payload`.
 *
 * @param {*} payload The JSON body.
 * @return {object} The response.
 */
function answer(payload) {
	return { json: async () => payload }
}

describe('SelfServiceWidget withdrawal link', () => {
	it('shows the signed link with its last day on the confirmation step', async () => {
		const html = await render({ step: 'confirmed', confirmationMessage: 'See you soon.', withdrawalLink: LINK })
		expect(html).toContain('data-testid="wsw-withdrawal"')
		expect(html).toContain('You can withdraw from this booking free of charge until 2026-10-04.')
		expect(html).toContain('href="https://portal.example.org/herroepen?token=abc.def"')
		expect(html).toContain('rel="noopener"')
		expect(html).toContain('>Withdraw from contract here</a>')
	})

	it('uses the partner translation for the sentence and the link', async () => {
		const html = await render({ step: 'confirmed', withdrawalLink: LINK }, {
			translations: {
				'You can withdraw from this booking free of charge until {date}.': 'U kunt deze boeking tot en met {date} kosteloos herroepen.',
				'Withdraw from contract here': 'Herroep de overeenkomst hier',
			},
		})
		expect(html).toContain('U kunt deze boeking tot en met 2026-10-04 kosteloos herroepen.')
		expect(html).toContain('>Herroep de overeenkomst hier</a>')
	})

	it('shows no link when the booking cannot be withdrawn', async () => {
		const html = await render({ step: 'confirmed', confirmationMessage: 'See you soon.' })
		expect(html).toContain('See you soon.')
		expect(html).not.toContain('wsw-withdrawal')
	})

	it('keeps the link the booking answer carries and confirms', async () => {
		const vm = widget()
		await vm.confirmBooking(answer({ confirmationMessage: 'Tot dan.', withdrawal: LINK }))
		expect(vm.step).toBe('confirmed')
		expect(vm.confirmationMessage).toBe('Tot dan.')
		expect(vm.withdrawalLink).toEqual(LINK)
		expect(vm.withdrawalNotice).toBe('You can withdraw from this booking free of charge until 2026-10-04.')
	})

	it.each([
		['no link', { confirmationMessage: 'Tot dan.' }],
		['a link that is not https', { withdrawal: { url: 'http://portal.example.org/herroepen?token=x', periodEndsOn: '2026-10-04' } }],
		['a link without an address', { withdrawal: { periodEndsOn: '2026-10-04' } }],
		['no body', null],
	])('offers no link for %s', async (label, payload) => {
		const vm = widget()
		await vm.confirmBooking(answer(payload))
		expect(vm.step).toBe('confirmed')
		expect(vm.withdrawalLink).toBeNull()
	})

	it('keeps an empty last day when the server names none', () => {
		expect(widget().withdrawalOf({ withdrawal: { url: 'https://portal.example.org/x' } })).toEqual({ url: 'https://portal.example.org/x', periodEndsOn: '' })
	})
})
