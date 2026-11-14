/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AdminRoot as it renders (server-side, so no DOM is needed): what it hands
 * the admin settings shell, the embedded forms it shows once the stores are
 * up, and the section drawn in each page tab; the open tab taken from the
 * URL; and its lifecycle: the stores and required-field count on creation,
 * the tab switch and the store reload after a re-import.
 *
 * @spec openspec/specs/app-administration/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { initializeStores } from '../../src/store/store.js'

const captured = vi.hoisted(() => ({ list: [] }))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))

/**
 * A stub that records its attributes and the vnodes of its slots, and
 * renders its slots as HTML inside `tag`.
 *
 * @param {string} name The component name.
 * @param {string} tag The element rendered.
 * @return {object} The component.
 */
async function stub(name, tag) {
	const { ssrRenderAttrs, ssrRenderSlot } = await import('vue/server-renderer')
	return {
		name,
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			const slots = Object.fromEntries(Object.keys(ctx.$slots).map((key) => [key, ctx.$slots[key]()]))
			captured.list.push({ name, attrs: { ...ctx.$attrs }, slots })
			push(`<${tag}${ssrRenderAttrs({ 'data-stub': name, 'data-testid': ctx.$attrs['data-testid'], href: ctx.$attrs.href })}>`)
			for (const key of Object.keys(ctx.$slots)) {
				push(`<!--slot:${key}-->`)
				ssrRenderSlot(ctx.$slots, key, {}, null, push, parent)
			}
			push(`</${tag}>`)
		},
	}
}

vi.mock('@conduction/nextcloud-vue', async () => ({
	CnAdminSettingsShell: await stub('CnAdminSettingsShell', 'div'),
	CnSettingsSection: await stub('CnSettingsSection', 'section'),
	CnToggleTableWidget: await stub('CnToggleTableWidget', 'table'),
}))
vi.mock('@nextcloud/vue', async () => ({ NcButton: await stub('NcButton', 'a') }))
vi.mock('../../src/views/settings/Settings.vue', async () => ({ default: await stub('Settings', 'form') }))
vi.mock('../../src/views/settings/PipelinqIntegration.vue', async () => ({ default: await stub('PipelinqIntegration', 'form') }))

const { default: AdminRoot } = await import('../../src/views/settings/AdminRoot.vue')

const t = (app, text) => text

/**
 * Render the admin page with its data changed by `state`.
 *
 * @param {object} state The data to change.
 * @return {Promise<{html: string, data: object}>} The HTML and the data rendered.
 */
async function render(state = {}) {
	const data = { ...AdminRoot.data.call({}), ...state }
	const app = createSSRApp({ ...AdminRoot, created: undefined, data: () => data })
	app.config.globalProperties.t = t
	const html = await renderToString(app)
	return { html, data }
}

const named = (name) => captured.list.filter((entry) => entry.name === name)

describe('AdminRoot render', () => {
	beforeEach(() => {
		captured.list.length = 0
		globalThis.window = { location: { search: '' } }
	})

	afterEach(() => {
		delete globalThis.window
	})

	it('hands the shell the board, its tabs, crumbs and note', async () => {
		await render({ requiredFieldCount: 3 })
		const [shell] = named('CnAdminSettingsShell')

		expect(shell.attrs).toMatchObject({
			appId: 'shillinq',
			appName: 'Shillinq',
			look: 'board',
			versionCardPosition: 'end',
			title: 'Shillinq',
			breadcrumbNote: 'only for administrators, each section saves on its own',
			docUrl: 'https://shillinq.conduction.nl/docs/intro',
		})
		expect(shell.attrs.tabs.map((tab) => [tab.id, tab.count])).toEqual([['general', undefined], ['required-fields', 3], ['connections', undefined], ['appointments', undefined]])
		expect(shell.attrs.breadcrumbs.map((crumb) => crumb.label)).toEqual(['Administration settings', 'Shillinq', 'General'])
		expect(Object.keys(shell.slots)).toEqual(['default', 'tab-required-fields', 'tab-connections', 'tab-appointments'])
	})

	it('shows the embedded settings forms only once the stores are ready', async () => {
		const waiting = await render({ storesReady: false })
		expect(named('Settings')).toEqual([])
		expect(named('PipelinqIntegration')).toEqual([])
		expect(waiting.html).not.toContain('data-stub="Settings"')

		const ready = await render({ storesReady: true })
		expect(ready.html).toMatch(/<!--slot:default--><!--\[--><form data-stub="Settings"><\/form><form data-stub="PipelinqIntegration"><\/form>/)
		const [shell] = named('CnAdminSettingsShell').slice(-1)
		expect(shell.slots.default.map((node) => node.type.name)).toEqual(['Settings', 'PipelinqIntegration'])
	})

	it('draws each page tab as a section linking to its page, with the widget where declared', async () => {
		const { html } = await render()

		const sections = named('CnSettingsSection')
		expect(sections.map((entry) => [entry.attrs['data-testid'], entry.attrs.name, entry.attrs.wide])).toEqual([
			['admin-section-required-fields', 'Required fields', ''],
			['admin-section-connections', 'Connections', ''],
			['admin-section-appointments', 'Appointments', ''],
		])
		expect(sections[1].attrs.description).toBe('See which connections work before you rely on them.')
		const components = (nodes) => nodes.filter((node) => typeof node.type === 'object').map((node) => node.type.name)
		expect(components(sections[0].slots.default)).toEqual(['CnToggleTableWidget', 'NcButton'])
		expect(components(sections[1].slots.default)).toEqual(['NcButton'])

		const widgets = named('CnToggleTableWidget')
		expect(widgets).toHaveLength(1)
		expect(widgets[0].attrs.content.bind.schema).toBe('FieldRequirement')

		expect(named('NcButton').map((entry) => [entry.attrs.href, entry.slots.default[0].children])).toEqual([
			['/index.php/apps/shillinq/settings/required-fields', 'Manage required fields'],
			['/index.php/apps/shillinq/external-adapters', 'Open the connections'],
			['/index.php/apps/shillinq/settings/booking-widget-keys', 'Manage widget keys'],
		])
		const [section] = named('CnAdminSettingsShell')[0].slots['tab-required-fields']
		const [widget, button] = section.children.default()
		expect([section.props['data-testid'], widget.props.content.bind.field, button.props.href]).toEqual(['admin-section-required-fields', 'enabled', '/index.php/apps/shillinq/settings/required-fields'])
		expect(button.children.default()[0].children).toContain('Manage required fields')
		expect(html).toMatch(/<a data-stub="NcButton" href="\/index.php\/apps\/shillinq\/external-adapters"><!--slot:default--><!--\[-->\s*Open the connections\s*<!--\]--><\/a>/)
	})

	it('switches the open tab and reloads the stores after a re-import', async () => {
		const { data } = await render()
		const [shell] = named('CnAdminSettingsShell')

		shell.attrs.onTabChange('appointments')
		expect(data.activeTab).toBe('appointments')

		initializeStores.mockClear()
		shell.attrs.onReimported()
		expect(initializeStores).toHaveBeenCalledTimes(1)
	})
})

describe('AdminRoot lifecycle', () => {
	afterEach(() => {
		delete globalThis.window
	})

	it('opens the tab named in the URL, else General', () => {
		globalThis.window = { location: { search: '?tab=connections' } }
		expect(AdminRoot.data.call({})).toEqual({ storesReady: false, activeTab: 'connections', requiredFieldCount: null })

		globalThis.window = { location: { search: '?other=1' } }
		expect(AdminRoot.data.call({}).activeTab).toBe('general')
	})

	it('counts the required fields and reveals the forms once the stores are up', async () => {
		let release
		initializeStores.mockReset().mockReturnValue(new Promise((resolve) => { release = resolve }))
		axios.get.mockReset().mockResolvedValue({ data: { total: '7' } })
		const vm = { storesReady: false, requiredFieldCount: null }
		vm.loadRequiredFieldCount = AdminRoot.methods.loadRequiredFieldCount.bind(vm)

		const creating = AdminRoot.created.call(vm)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/FieldRequirement', { params: { _limit: 1 } })
		expect(initializeStores).toHaveBeenCalledTimes(1)
		expect(vm.storesReady).toBe(false)

		release()
		await creating
		expect([vm.storesReady, vm.requiredFieldCount]).toEqual([true, 7])
	})

	it('drops a count that is not a number', async () => {
		axios.get.mockReset().mockResolvedValue({ data: { total: 'many' } })
		const vm = { requiredFieldCount: 4 }
		await AdminRoot.methods.loadRequiredFieldCount.call(vm)
		expect(vm.requiredFieldCount).toBeNull()
	})
})
