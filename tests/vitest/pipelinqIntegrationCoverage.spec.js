/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * PipelinqIntegration as it renders (server-side, so no DOM is needed): the
 * token hint and placeholder with and without a stored token, the feedback,
 * the busy labels and disabled buttons; what its inputs, form and test button
 * are bound to; and the settings fetch on creation. The stubbed settings
 * section keeps the vnodes of its slot so their bound handlers can be called,
 * as the browser would on input.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderAttrs, ssrRenderSlot } from 'vue/server-renderer'

const captured = vi.hoisted(() => ({ nodes: [] }))

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: {
		name: 'CnSettingsSection',
		props: ['name', 'description', 'wide'],
		ssrRender(ctx, push, parent) {
			captured.nodes.push(ctx.$slots.default())
			push(`<section data-name="${ctx.name}" data-description="${ctx.description}">`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('</section>')
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		ssrRender(ctx, push, parent) {
			push(`<button${ssrRenderAttrs({ disabled: ctx.$attrs.disabled, type: ctx.$attrs.type })}>`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('</button>')
		},
	},
}))

const { default: PipelinqIntegration } = await import('../../src/views/settings/PipelinqIntegration.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))

/**
 * Render the panel with its data replaced by `state`.
 *
 * @param {object} state The data to change.
 * @return {Promise<string>} The HTML.
 */
function render(state = {}) {
	const base = PipelinqIntegration.data.call({})
	const data = { ...base, ...state, form: { ...base.form, ...state.form } }
	const app = createSSRApp({ ...PipelinqIntegration, data: () => data })
	app.config.globalProperties.t = t
	return renderToString(app).then((html) => ({ html, data }))
}

/**
 * Every vnode under `nodes`, depth first.
 *
 * @param {Array|object} nodes The vnodes.
 * @return {Array<object>} The flattened vnodes.
 */
function flatten(nodes) {
	const out = []
	for (const node of [].concat(nodes ?? [])) {
		if (!node || typeof node !== 'object') {
			continue
		}
		out.push(node)
		if (Array.isArray(node.children)) {
			out.push(...flatten(node.children))
		}
	}
	return out
}

describe('PipelinqIntegration render', () => {
	beforeEach(() => {
		captured.nodes.length = 0
		globalThis.OC = { requestToken: 'tok-1' }
		globalThis.t = t
		// The panel's own created() fetch stays pending, so the state rendered is the one given.
		globalThis.fetch = vi.fn(() => new Promise(() => {}))
	})

	it('asks for a first token and offers save and test', async () => {
		const { html } = await render({ form: { endpoint: 'https://p' } })

		expect(html).toContain('data-name="Pipelinq integration"')
		expect(html).toContain('placeholder="Paste your pipelinq API token"')
		expect(html).toContain('The token is stored in the Nextcloud secrets store and never returned to the browser.')
		expect(html).toContain('value="https://p"')
		expect(html).toContain('<button type="submit"><!--[-->Save<!--]--></button><button><!--[-->Test connection<!--]--></button>')
		expect(html).not.toContain('class="feedback')
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/pipelinq/settings', { headers: { requesttoken: 'tok-1' } })
	})

	it('masks a stored token, shows the feedback and the busy labels', async () => {
		const { html } = await render({ hasToken: true, saving: true, testing: true, feedback: { kind: 'error', message: 'Nope' } })

		expect(html).toContain('placeholder="••••••••••••"')
		expect(html).toContain('A token is stored. Leave empty to keep the current token, or paste a new one to rotate it.')
		expect(html).toMatch(/class="error feedback"[^>]*>Nope</)
		expect(html).toContain('<button disabled type="submit"><!--[-->Saving…<!--]--></button><button disabled><!--[-->Testing…<!--]--></button>')
	})

	it('disables the test button without an endpoint', async () => {
		const { html } = await render()
		expect(html).toContain('<button type="submit"><!--[-->Save<!--]--></button><button disabled><!--[-->Test connection<!--]--></button>')
	})

	it('writes the endpoint and token inputs into the form', async () => {
		const { data } = await render({ hasToken: true })
		const nodes = flatten(captured.nodes.at(-1))
		const [endpoint, token] = nodes.filter((node) => node.type === 'input')

		expect([endpoint.props.id, endpoint.props.type, token.props.id, token.props.type]).toEqual(['pipelinq-endpoint', 'url', 'pipelinq-token', 'password'])
		expect(token.props.placeholder).toBe('••••••••••••')
		endpoint.props['onUpdate:modelValue']('https://new')
		token.props['onUpdate:modelValue']('secret')
		expect(data.form).toEqual({ endpoint: 'https://new', token: 'secret' })

		const hint = nodes.find((node) => node.props?.class === 'form-hint')
		expect(hint.children).toContain('A token is stored.')
	})

	it('saves on submit without leaving the page, and tests on the test button', async () => {
		await render({ form: { endpoint: 'https://p' }, feedback: { kind: 'success', message: 'Saved' } })
		const nodes = flatten(captured.nodes.at(-1))
		globalThis.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 502 }))

		const event = { preventDefault: vi.fn() }
		await nodes.find((node) => node.type === 'form').props.onSubmit(event)
		expect(event.preventDefault).toHaveBeenCalled()
		expect(fetch).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/pipelinq/settings', expect.objectContaining({ method: 'POST', body: '{"endpoint":"https://p"}' }))

		const feedback = nodes.find((node) => node.props?.class?.includes?.('feedback'))
		expect(feedback.props.class).toBe('feedback success')
		expect(feedback.children).toContain('Saved')

		const [save, test] = nodes.filter((node) => node.type?.name === 'NcButton')
		expect([save.props.type, save.props.disabled, test.props.disabled]).toEqual(['submit', false, false])
		expect(save.children.default()[0].children).toContain('Save')
		expect(test.children.default()[0].children).toContain('Test connection')
		await test.props.onClick()
		expect(fetch).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/pipelinq/settings/test', expect.objectContaining({ method: 'POST' }))
	})

	it('labels the buttons as busy in the slot vnodes too', async () => {
		await render({ saving: true, testing: true })
		const [save, test] = flatten(captured.nodes.at(-1)).filter((node) => node.type?.name === 'NcButton')
		expect([save.children.default()[0].children, test.children.default()[0].children]).toEqual(['Saving…', 'Testing…'])
		expect(flatten(captured.nodes.at(-1)).find((node) => node.props?.class === 'form-hint').children).toContain('never returned')
	})
})

describe('PipelinqIntegration settings fetch', () => {
	/**
	 * A fake component instance with its methods bound.
	 *
	 * @return {object}
	 */
	function panel() {
		const self = { ...PipelinqIntegration.data.call({}) }
		for (const [name, method] of Object.entries(PipelinqIntegration.methods)) {
			self[name] = method.bind(self)
		}
		return self
	}

	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok-1' }
		globalThis.fetch = vi.fn()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('fills the endpoint and the stored-token flag when created', async () => {
		fetch.mockResolvedValue({ ok: true, json: () => Promise.resolve({ endpoint: 'https://p', hasToken: 1 }) })
		const vm = panel()

		await PipelinqIntegration.created.call(vm)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/pipelinq/settings', { headers: { requesttoken: 'tok-1' } })
		expect([vm.form.endpoint, vm.hasToken]).toEqual(['https://p', true])
	})

	it('reads missing settings as empty and keeps the form on a refused fetch', async () => {
		fetch.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({}) })
		const vm = panel()
		vm.form.endpoint = 'old'
		await vm.fetchSettings()
		expect([vm.form.endpoint, vm.hasToken]).toEqual(['', false])

		const json = vi.fn()
		fetch.mockResolvedValueOnce({ ok: false, json })
		vm.form.endpoint = 'kept'
		await vm.fetchSettings()
		expect(json).not.toHaveBeenCalled()
		expect(vm.form.endpoint).toBe('kept')
	})

	it('logs a fetch that could not be sent', async () => {
		const error = new Error('offline')
		fetch.mockRejectedValue(error)
		const log = vi.spyOn(console, 'error').mockImplementation(() => {})
		const vm = panel()

		await vm.fetchSettings()

		expect(log).toHaveBeenCalledWith('Failed to fetch pipelinq settings:', error)
		expect([vm.form.endpoint, vm.hasToken]).toEqual(['', false])
	})
})
