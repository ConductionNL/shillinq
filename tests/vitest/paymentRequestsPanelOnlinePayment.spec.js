/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The payment requests panel without a live payment provider
 * (receivables-payment-links task 3.3, REQ-RPL-002): the leaf reports
 * `onlinePayment.live === false`, the panel says online payment is not set up
 * and where to set it up, and Send payment link is disabled with that reason.
 * Only HTTP is replaced.
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

globalThis.t = (_app, value, vars = {}) => value.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

const { default: Panel } = await import('../../src/integrations/ShillinqPaymentRequestsPanel.vue')

const HOST = { register: 'dossiq', schema: 'case', objectId: 'c-1' }
const REQUEST = { id: 'r1', amount: 97.5, currency: 'EUR', state: 'pending', description: 'Omgevingsvergunning kappen, 1 boom' }
const NOT_SET_UP = 'Online payment is not set up: configure a payment source in integriq.'

/**
 * A fake panel instance with its own and its mixins' methods and computed.
 *
 * @return {object} The instance.
 */
function panel() {
	const vm = { $props: { integrationContext: {}, ...HOST }, ...HOST, onHeader: null, onSummary: null }
	Object.assign(vm, Panel.data.call(vm))
	for (const part of [...Panel.mixins, Panel]) {
		for (const [name, fn] of Object.entries(part.methods || {})) {
			vm[name] = fn.bind(vm)
		}
		for (const [name, getter] of Object.entries(part.computed || {})) {
			Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
		}
	}
	return vm
}

/**
 * The opening tag of the Send payment link stand-in of a request.
 *
 * @param {string} html The panel HTML.
 * @param {string} id The request id.
 * @return {string} The tag.
 */
function sendButton(html, id) {
	const match = html.match(new RegExp(`<cn-stub data-name="NcButton"[^>]*data-testid="shillinq-send-link-${id}"[^>]*>|<cn-stub data-name="NcButton"[^>]*>(?=[^<]*Send payment link)`))
	return match ? match[0] : ''
}

const components = { NcButton: standIn('NcButton'), NcLoadingIcon: standIn('NcLoadingIcon') }

describe('payment requests panel without online payment', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads that online payment is not set up from the leaf', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [REQUEST], onlinePayment: { live: false, reason: 'not-set-up' } } })
		const vm = panel()

		await vm.load()

		expect(vm.onlinePayment).toEqual({ live: false, reason: 'not-set-up' })
		expect(vm.onlinePaymentOff).toBe(true)
		expect(vm.onlinePaymentOffLabel).toBe(NOT_SET_UP)
	})

	it('keeps Send enabled when the leaf reports a live provider or says nothing', async () => {
		const vm = panel()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [REQUEST], onlinePayment: { live: true, reason: '' } } })
		await vm.load()
		expect(vm.onlinePaymentOff).toBe(false)

		vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: [REQUEST], onlinePayment: 'nope' } })
		await vm.load()
		expect(vm.onlinePayment).toBeNull()
		expect(vm.onlinePaymentOff).toBe(false)
	})

	it('renders the reason and disables Send payment link', async () => {
		const html = await renderTemplate(Panel, { props: HOST, data: { requests: [REQUEST], onlinePayment: { live: false, reason: 'not-set-up' } }, components })

		expect(html).toContain('data-testid="shillinq-online-payment-off"')
		expect(html).toContain(NOT_SET_UP)
		const tag = sendButton(html, 'r1')
		expect(tag).not.toBe('')
		expect(tag).toContain('data-disabled="true"')
		expect(tag).toContain(`title="${NOT_SET_UP}"`)
	})

	it('renders no reason and an enabled Send with a live provider', async () => {
		const html = await renderTemplate(Panel, { props: HOST, data: { requests: [REQUEST], onlinePayment: { live: true, reason: '' } }, components })

		expect(html).not.toContain('data-testid="shillinq-online-payment-off"')
		const tag = sendButton(html, 'r1')
		expect(tag).not.toBe('')
		expect(tag).not.toContain('data-disabled="true"')
	})
})
