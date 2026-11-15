/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The app shell as it renders (server-side, so no DOM is needed): what it
 * hands CnAppRoot, the per-object sidebar it draws once a page publishes an
 * object, the page's own sidebar component, and the stores it brings up.
 *
 * @spec openspec/changes/shillinq-manifest-tier4/tasks.md#2.2
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import notificationRuleWording from '../../src/notificationLabels.js'
import { initializeStores } from '../../src/store/store.js'

const captured = vi.hoisted(() => ({ list: [], sidebarProps: {} }))

vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn(async () => {}) }))

vi.mock('@conduction/nextcloud-vue', async () => {
	const { ssrRenderSlot } = await import('vue/server-renderer')
	return {
		CnAppRoot: {
			name: 'CnAppRoot',
			inheritAttrs: false,
			ssrRender(ctx, push, parent) {
				captured.list.push({ name: 'CnAppRoot', attrs: { ...ctx.$attrs } })
				push('<main data-stub="CnAppRoot">')
				ssrRenderSlot(ctx.$slots, 'sidebar', captured.sidebarProps, null, push, parent)
				push('</main>')
			},
		},
		CnObjectSidebar: {
			name: 'CnObjectSidebar',
			inheritAttrs: false,
			ssrRender(ctx, push) {
				captured.list.push({ name: 'CnObjectSidebar', attrs: { ...ctx.$attrs } })
				push('<aside data-stub="CnObjectSidebar"></aside>')
			},
		},
	}
})

const { default: App } = await import('../../src/App.vue')

const manifest = { version: '1.0.0', menu: [], pages: [] }
const registry = { Dashboard: { kind: 'page', component: { name: 'Dashboard' } } }

/**
 * Render the shell with its sidebar state changed by `sidebar`.
 *
 * @param {object} sidebar Fields set on objectSidebarState before rendering.
 * @param {object} props Extra props.
 * @return {Promise<{html: string, vm: object}>} The HTML and the shell instance.
 */
async function render(sidebar = {}, props = {}) {
	let vm = null
	const app = createSSRApp({
		render: () => h(App, { manifest, registry, ...props }),
	})
	// The shell instance is reached through a mixin's created hook, which
	// runs before the render that reads the sidebar state.
	app.mixin({
		created() {
			if (this.$options.name === 'App') {
				vm = this
				Object.assign(this.objectSidebarState, sidebar)
			}
		},
	})
	const html = await renderToString(app)
	return { html, vm }
}

const named = (name) => captured.list.filter((entry) => entry.name === name)

describe('App shell', () => {
	beforeEach(() => {
		captured.list.length = 0
		captured.sidebarProps = {}
		vi.mocked(initializeStores).mockClear()
		globalThis.window = { OC: { currentUser: { permissions: ['read', 'update'] } } }
	})

	afterEach(() => {
		delete globalThis.window
	})

	it('hands CnAppRoot the manifest, the registry and the app id', async () => {
		await render()
		const [root] = named('CnAppRoot')

		expect(root.attrs.manifest).toEqual(manifest)
		expect(root.attrs.registry).toEqual(registry)
		expect(root.attrs.appId).toBe('shillinq')
		expect(root.attrs.aiCompanion).toBe(true)
		expect(root.attrs.pageTypes).toEqual({})
		expect(root.attrs.customComponents).toEqual({})
	})

	it('orders the personal settings sections as the board does', async () => {
		await render()

		expect(named('CnAppRoot')[0].attrs.userSettingsSections).toEqual(['notifications', 'credentials', 'integrations', 'walkthrough'])
	})

	it('passes the current user\'s permissions, or none without a user', async () => {
		await render()
		expect(named('CnAppRoot')[0].attrs.permissions).toEqual(['read', 'update'])

		captured.list.length = 0
		globalThis.window = {}
		await render()
		expect(named('CnAppRoot')[0].attrs.permissions).toEqual([])
	})

	it('words every notification rule, keyed by schema and rule', async () => {
		await render()
		const labels = named('CnAppRoot')[0].attrs.notificationLabels

		expect(Object.keys(labels)).toEqual(Object.keys(notificationRuleWording))
		expect(labels['APTransaction.overdue']).toBe('Overdue')
	})

	it('translates for the library under its own app id', async () => {
		await render()

		expect(named('CnAppRoot')[0].attrs.translate('Approval needed')).toBe('Approval needed')
	})

	it('brings up the stores when it is created', async () => {
		await render()

		expect(initializeStores).toHaveBeenCalledTimes(1)
	})

	it('draws no object sidebar until a page publishes an object', async () => {
		const { html } = await render()

		expect(named('CnObjectSidebar')).toEqual([])
		expect(html).not.toContain('CnObjectSidebar')
	})

	it('draws the object sidebar with the published object', async () => {
		await render({
			active: true,
			title: 'Factuur 2026-001',
			objectType: 'invoice',
			objectId: 'inv-1',
			register: 'shillinq',
			schema: 'invoice',
		})
		const [sidebar] = named('CnObjectSidebar')

		expect(sidebar.attrs).toMatchObject({
			title: 'Factuur 2026-001',
			objectType: 'invoice',
			objectId: 'inv-1',
			register: 'shillinq',
			schema: 'invoice',
			open: true,
			registry,
		})
	})

	it('renders the page\'s own sidebar component beside it', async () => {
		captured.sidebarProps = {
			pageSidebarComponent: { name: 'FlowSidebar', ssrRender: (ctx, push) => push('<nav data-stub="FlowSidebar"></nav>') },
		}
		const { html } = await render()

		expect(html).toContain('data-stub="FlowSidebar"')
	})

	it('provides the sidebar state to the pages below it', async () => {
		const { vm } = await render()

		expect(App.provide.call(vm).objectSidebarState).toBe(vm.objectSidebarState)
		expect(vm.objectSidebarState).toMatchObject({ active: false, open: true, searchValue: '', activeFilters: {} })
	})
})
