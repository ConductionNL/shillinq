/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BookingWidgetKeys: the buttons need a business (and, to generate, an
 * administration), each action posts to its own endpoint, a new key is shown
 * once, and a failed request becomes a message. The template is rendered
 * with Vue's server renderer.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['variant', 'disabled'], render() { return h('button', { 'data-variant': this.variant, disabled: this.disabled }, this.$slots.default?.()) } },
}))

const { default: Keys } = await import('../../src/views/BookingWidgetKeys.vue')

/**
 * Render the view with its data overridden.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data = {}) {
	const app = createSSRApp({ ...Keys, data: () => ({ ...Keys.data(), ...data }) })
	app.config.globalProperties.t = (a, text) => text
	return renderToString(app)
}

/**
 * Whether the button with the given variant is disabled in the HTML.
 *
 * @param {string} html The HTML.
 * @param {string} variant The button variant.
 * @return {boolean} True when disabled.
 */
function disabled(html, variant) {
	return new RegExp(`<button[^>]*data-variant="${variant}"[^>]*disabled`).test(html)
		|| new RegExp(`<button[^>]*disabled[^>]*data-variant="${variant}"`).test(html)
}

/**
 * The view's methods bound to a plain context with a business and administration.
 *
 * @return {object} The context.
 */
function context() {
	const ctx = { ...Keys.data(), businessId: 'biz-1', administrationId: 'adm-1' }
	for (const [name, fn] of Object.entries(Keys.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

const reply = (body) => vi.fn(async () => ({ json: async () => body }))

beforeEach(() => {
	globalThis.OC = { requestToken: 'token' }
})

afterEach(() => {
	delete globalThis.fetch
	vi.restoreAllMocks()
})

describe('BookingWidgetKeys rendering', () => {
	it('disables every action until a business is given', async () => {
		const html = await render()
		expect(['primary', 'secondary', 'error'].map((v) => disabled(html, v))).toEqual([true, true, true])
	})

	it('needs an administration only to generate', async () => {
		const html = await render({ businessId: 'biz-1' })
		expect(['primary', 'secondary', 'error'].map((v) => disabled(html, v))).toEqual([true, false, false])
	})

	it('disables every action while a request runs', async () => {
		const html = await render({ businessId: 'biz-1', administrationId: 'adm-1', busy: true })
		expect(['primary', 'secondary', 'error'].map((v) => disabled(html, v))).toEqual([true, true, true])
	})

	it('shows a new key and the message', async () => {
		const html = await render({ plaintextKey: 'wk_secret', message: 'Key created' })
		expect(html).toMatch(/<code[^>]*>wk_secret<\/code>/)
		expect(html).toContain('Key created')
	})

	it('shows no key block without a key', async () => {
		expect(await render()).not.toContain('role="status"')
	})
})

describe('BookingWidgetKeys actions', () => {
	it('generates a key for the business and administration', async () => {
		globalThis.fetch = reply({ success: true, apiKey: 'wk_1', message: 'Key created' })
		const vm = context()
		await vm.generate()

		const [url, init] = globalThis.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/shillinq/api/widget/admin/keys/create')
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'token' })
		expect(JSON.parse(init.body)).toEqual({ businessId: 'biz-1', administrationId: 'adm-1' })
		expect([vm.plaintextKey, vm.message, vm.busy]).toEqual(['wk_1', 'Key created', false])
	})

	it('shows no key when generating did not succeed', async () => {
		globalThis.fetch = reply({ success: false, message: 'Business unknown' })
		const vm = context()
		vm.plaintextKey = 'old'
		await vm.generate()
		expect([vm.plaintextKey, vm.message]).toEqual(['', 'Business unknown'])
	})

	it('rotates the key of the business only', async () => {
		globalThis.fetch = reply({ success: true, apiKey: 'wk_2' })
		const vm = context()
		await vm.rotate()
		expect(globalThis.fetch.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/widget/admin/keys/rotate')
		expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body)).toEqual({ businessId: 'biz-1' })
		expect([vm.plaintextKey, vm.message]).toEqual(['wk_2', ''])
	})

	it('rotating without a new key shows none', async () => {
		globalThis.fetch = reply({ success: true })
		const vm = context()
		await vm.rotate()
		expect(vm.plaintextKey).toBe('')
	})

	it('revokes the key and never shows one', async () => {
		globalThis.fetch = reply({ success: true, apiKey: 'ignored', message: 'Revoked' })
		const vm = context()
		vm.plaintextKey = 'old'
		await vm.revoke()
		expect(globalThis.fetch.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/widget/admin/keys/revoke')
		expect([vm.plaintextKey, vm.message]).toEqual(['', 'Revoked'])
	})

	it('turns a failed request into a message and frees the buttons', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		globalThis.fetch = vi.fn(async () => { throw new Error('offline') })
		const vm = context()
		await vm.revoke()
		expect([vm.message, vm.busy]).toEqual(['Error: offline', false])
		expect(console.error).toHaveBeenCalledWith('Widget key request failed:', expect.any(Error))
	})
})
