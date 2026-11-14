/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AdministrationSwitcherPage: it hosts the switcher with a reload after a
 * switch and turns the switcher's errors into a sign-in hint or a general
 * failure. The template is rendered with Vue's server renderer.
 *
 * @spec openspec/specs/bookkeeping-multi-administratie/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.hoisted(() => {
	globalThis.t = (app, text) => text
})
vi.mock('@nextcloud/vue', () => ({
	NcAppContent: { render() { return h('main', this.$slots.default?.()) } },
}))
vi.mock('../../src/components/AdministratieSwitcher.vue', () => ({
	default: { props: ['reloadAfterSwitch'], render() { return h('select', { 'data-reload': String(this.reloadAfterSwitch) }) } },
}))

const { default: Page } = await import('../../src/views/AdministrationSwitcherPage.vue')

/**
 * Render the page with its data overridden.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data = {}) {
	const app = createSSRApp({ ...Page, data: () => ({ ...Page.data(), ...data }) })
	app.config.globalProperties.t = globalThis.t
	return renderToString(app)
}

/**
 * The page's methods bound to a plain context seeded from its data.
 *
 * @return {object} The context.
 */
function context() {
	const ctx = { ...Page.data() }
	ctx.onError = Page.methods.onError.bind(ctx)
	return ctx
}

describe('AdministrationSwitcherPage', () => {
	it('hosts the switcher with a reload after switching and no alert', async () => {
		const html = await render()
		expect(html).toContain('Switch administration')
		expect(html).toContain('data-reload="true"')
		expect(html).not.toContain('role="alert"')
	})

	it('shows an error as an alert', async () => {
		const html = await render({ errorMessage: 'Failed to load administrations.' })
		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to load administrations\./)
	})

	it('asks a signed-out user to sign in', () => {
		const vm = context()
		vm.onError({ response: { status: 401 } })
		expect(vm.errorMessage).toBe('Please sign in to switch administrations.')
	})

	it('reports any other error as a failed load', () => {
		const vm = context()
		vm.onError({ response: { status: 500 } })
		expect(vm.errorMessage).toBe('Failed to load administrations.')
		vm.onError(undefined)
		expect(vm.errorMessage).toBe('Failed to load administrations.')
	})
})
