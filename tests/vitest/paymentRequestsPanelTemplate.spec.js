/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The payment requests leaf panel rendered from its real template: the
 * loading and error states, the published fee, the empty line, each request
 * row with its amount, state, description, due line, its two acts and its
 * notice, and the settle form. The buttons' click handlers are captured from
 * the rendered stand-ins and fired, so each act is shown reaching the method
 * that does it. A carded host draws its own empty line; a summary renders
 * nothing.
 */

import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

globalThis.t = (_app, value, vars = {}) => value.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

const { default: Panel } = await import('../../src/integrations/ShillinqPaymentRequestsPanel.vue')

const components = { NcButton: standIn('NcButton'), NcLoadingIcon: standIn('NcLoadingIcon') }

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * The labels of the buttons in a piece of HTML.
 *
 * @param {string} html The HTML.
 * @return {Array<string>} The labels.
 */
const buttons = (html) => [...html.matchAll(/<cn-stub data-name="NcButton"[^>]*>([\s\S]*?)<\/cn-stub>/g)].map((m) => text(m[1]))

/**
 * The HTML of one request row.
 *
 * @param {string} html The panel HTML.
 * @param {string} id The request id.
 * @return {string} The row HTML.
 */
function row (html, id) {
	const start = html.indexOf(`data-testid="shillinq-payment-request-${id}"`)
	return '<li ' + html.slice(start, html.indexOf('</li>', start))
}

/**
 * Render the panel for a state.
 *
 * @param {object} data Preset data.
 * @param {object} props Props.
 * @return {Promise<string>} The HTML.
 */
const render = (data, props = {}) => renderTemplate(Panel, { props, data, components })

const REQUESTS = [
	{ id: 'r1', amount: 40, currency: 'EUR', paymentState: 'outstanding', description: 'Leges omgevingsvergunning', dueAt: '2026-12-01T00:00:00Z' },
	{ id: 'r2', amount: 12.5, currency: 'EUR', paymentState: 'paid' },
]

describe('ShillinqPaymentRequestsPanel template', () => {
	it('shows a loading icon, then the read failure', async () => {
		expect(await render({ loading: true })).toContain('data-name="NcLoadingIcon"')
		expect(text(await render({ error: 'The payment requests could not be read.' }))).toBe('The payment requests could not be read.')
	})

	it('shows the published fee and says when nobody has been asked to pay', async () => {
		const html = await render({ fee: { amount: 125, currency: 'EUR' } })

		expect(html).toContain('shillinq-leaf__fee')
		expect(text(html)).toMatch(/^Published fee for this record: .*125/)
		expect(text(html)).toContain('Nobody has been asked to pay anything on this record yet.')
		expect(html).not.toContain('shillinq-leaf__list')
	})

	it('lists each request with its amount, state, description, due line, acts and notice', async () => {
		const html = await render({ requests: REQUESTS, busyId: 'r1', notices: { r1: 'The payment link went out.' } })

		const first = row(html, 'r1')
		expect(text(first)).toContain(text(Panel.methods.amountOf.call({}, REQUESTS[0])))
		expect(text(first)).toContain('Leges omgevingsvergunning')
		expect(text(first)).toContain('Due 2026-12-01')
		expect(buttons(first)).toEqual(['Send payment link', 'Mark paid by other means'])
		expect(first.match(/data-disabled="true"/g)).toHaveLength(2)
		expect(text(first)).toContain('The payment link went out.')

		const second = row(html, 'r2')
		expect(second).not.toContain('shillinq-leaf__description')
		expect(second).not.toContain('shillinq-leaf__due')
		expect(second).not.toContain('data-disabled="true"')
		expect(html).not.toContain('Nobody has been asked to pay anything')
	})

	it('shows the settle form only under its own request, with every way money can arrive', async () => {
		const html = await render({ requests: REQUESTS, settleFor: 'r2' })

		expect(text(row(html, 'r2'))).toContain('How the money arrived Pin at the counter Cash at the counter Bank transfer Waived Another way Reference Reason, which a waiver needs Record the payment Cancel')
		expect(row(html, 'r2')).toContain('placeholder="What the bank statement will show"')
		expect(row(html, 'r1')).not.toContain('How the money arrived')
	})

	it('leaves the empty line to a carded host, and renders nothing for a summary', async () => {
		const carded = await render({}, { onHeader: () => {} })
		const summary = await render({ requests: REQUESTS }, { onSummary: () => {} })

		expect(carded).toContain('shillinq-payment-requests-panel')
		expect(carded).not.toContain('Nobody has been asked to pay anything')
		expect(summary).not.toContain('shillinq-payment-requests-panel')
		expect(summary).not.toContain('shillinq-payment-request-r1')
	})

	it('sends the link, opens the settle form and closes it from the buttons it draws', async () => {
		const clicks = []
		const recording = {
			name: 'NcButton',
			inheritAttrs: false,
			setup (_props, { slots, attrs }) {
				clicks.push({ label: () => text(String(slots.default?.()?.[0]?.children ?? '')), onClick: attrs.onClick })
				return () => h('cn-stub', { 'data-name': 'NcButton' }, slots.default?.())
			},
		}
		const send = vi.fn()
		const openSettle = vi.fn()
		const closeSettle = vi.fn()
		const subject = { ...Panel, methods: { ...Panel.methods, send, openSettle, closeSettle } }

		await renderTemplate(subject, {
			data: { requests: [REQUESTS[0]], settleFor: 'r1' },
			components: { NcButton: recording, NcLoadingIcon: standIn('NcLoadingIcon') },
		})
		const byLabel = (label) => clicks.find((click) => click.label() === label)

		byLabel('Send payment link').onClick()
		byLabel('Mark paid by other means').onClick()
		byLabel('Cancel').onClick()

		expect(send).toHaveBeenCalledTimes(1)
		expect(send.mock.calls[0][0]).toMatchObject({ id: 'r1' })
		expect(openSettle).toHaveBeenCalledTimes(1)
		expect(openSettle.mock.calls[0][0]).toMatchObject({ id: 'r1' })
		expect(closeSettle).toHaveBeenCalledTimes(1)
		expect(byLabel('Record the payment').onClick).toBeUndefined()
	})

	it('reads the host object when it is mounted', () => {
		const load = vi.fn()

		Panel.mounted.call({ load })

		expect(load).toHaveBeenCalledTimes(1)
	})
})
