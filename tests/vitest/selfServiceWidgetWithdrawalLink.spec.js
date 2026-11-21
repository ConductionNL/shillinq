/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The booking widget's confirmation step shows the signed withdrawal link
 * and its last day the server sent for a booking made online
 * (sales-cancellation task 3.4, REQ-SCX-004), in the partner's translation
 * table, and shows nothing when there is no usable link. The line itself
 * is `WithdrawalNotice.vue`, which only the widget renders.
 *
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.4
 */

import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import SelfServiceWidget from '../../src/components/widget/SelfServiceWidget.vue'

const PROPS = { businessId: 'biz-1', apiBase: 'https://api.example.org/apps/shillinq/', apiKey: 'key-1', lang: 'en' }
const LINK = 'https://kapsalon.example.org/herroepen?withdraw=abc.def'

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
 * @return {object} The widget.
 */
function widget() {
	const vm = { ...PROPS, translations: {} }
	for (const [name, fn] of Object.entries(SelfServiceWidget.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, SelfServiceWidget.data.call(vm))
	return vm
}

/**
 * A response whose body is `payload`.
 *
 * @param {object} payload The parsed body.
 * @return {object} The response.
 */
function response(payload) {
	return { status: 201, ok: true, json: async () => payload }
}

describe('the withdrawal link on the confirmation step (REQ-SCX-004)', () => {
	it('keeps the link and its last day the server sent', async () => {
		const vm = widget()
		await vm.confirmBooking(response({ confirmationMessage: 'Created', withdrawalLink: LINK, withdrawalLastDay: '2026-12-05' }))

		expect([vm.step, vm.withdrawalLink, vm.withdrawalLastDay]).toEqual(['confirmed', LINK, '2026-12-05'])
	})

	it('renders the link, opening in a new tab, with the last day', async () => {
		const html = await render({ step: 'confirmed', confirmationMessage: 'Created', withdrawalLink: LINK, withdrawalLastDay: '2026-12-05' })

		expect(html).toContain('Changed your mind? You can withdraw from this booking without a fee until')
		expect(html).toContain('<time datetime="2026-12-05">2026-12-05</time>')
		expect(html).toMatch(new RegExp(`<a class="wsw-widget__withdrawal-link" href="${LINK.replace(/[.?]/g, '\\$&')}" target="_blank" rel="noopener noreferrer">Withdraw from this booking</a>`))
	})

	it('speaks the partner\'s language', async () => {
		const translations = {
			'Changed your mind? You can withdraw from this booking without a fee until': 'Bedacht? Je kunt deze boeking zonder kosten herroepen tot en met',
			'Withdraw from this booking': 'Deze boeking herroepen',
		}
		const html = await render({ step: 'confirmed', withdrawalLink: LINK, withdrawalLastDay: '2026-12-05' }, { translations })

		expect(html).toContain('Bedacht? Je kunt deze boeking zonder kosten herroepen tot en met')
		expect(html).toContain('>Deze boeking herroepen</a>')
	})

	it.each([
		['no link', {}],
		['a link that is not https', { withdrawalLink: 'http://kapsalon.example.org/x', withdrawalLastDay: '2026-12-05' }],
		['a javascript link', { withdrawalLink: 'javascript:alert(1)', withdrawalLastDay: '2026-12-05' }],
		['a link without its last day', { withdrawalLink: LINK, withdrawalLastDay: null }],
	])('shows nothing for %s', async (_name, extra) => {
		const vm = widget()
		await vm.confirmBooking(response({ confirmationMessage: 'Created', ...extra }))

		const html = await render({ step: 'confirmed', confirmationMessage: 'Created', withdrawalLink: vm.withdrawalLink, withdrawalLastDay: vm.withdrawalLastDay })
		expect(html).not.toContain('wsw-widget__withdrawal')
	})
})
