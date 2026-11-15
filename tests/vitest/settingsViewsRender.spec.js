/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The admin settings views as they render (server side, so no DOM is
 * needed): the admin page's tabs and sections, the configuration form, the
 * pipelinq connection form and how it reads its stored settings, and the
 * accounting standards editor in its loading, read-only and editable states.
 *
 * @spec openspec/specs/app-administration/spec.md
 * @spec openspec/specs/accounting-standards-policy/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const initializeStoresMock = vi.hoisted(() => vi.fn())
const settingsStoreMock = vi.hoisted(() => ({ settings: null, saveSettings: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: initializeStoresMock }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => settingsStoreMock }))
vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: vi.fn() }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		props: ['variant', 'disabled', 'modelValue', 'type', 'href', 'size'],
		render() {
			const attrs = { 'data-stub': name }
			if (this.disabled) {
				attrs.disabled = true
			}
			if (this.href) {
				attrs.href = this.href
			}
			if (name === 'NcCheckboxRadioSwitch') {
				attrs['data-checked'] = String(Boolean(this.modelValue))
			}
			return h(tag, attrs, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	})
	return {
		NcButton: slotted('NcButton', 'button'),
		NcCheckboxRadioSwitch: slotted('NcCheckboxRadioSwitch', 'label'),
		NcLoadingIcon: slotted('NcLoadingIcon', 'span'),
	}
})
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: {
		name: 'CnSettingsSection',
		props: ['name', 'description', 'wide'],
		render() {
			return h('section', { 'data-stub': 'CnSettingsSection' }, [h('h3', this.name), h('p', this.description), this.$slots.default?.()])
		},
	},
	CnToggleTableWidget: {
		name: 'CnToggleTableWidget',
		props: ['content'],
		render() {
			return h('div', { 'data-stub': 'CnToggleTableWidget', 'data-schema': this.content?.schema || '' })
		},
	},
	CnAdminSettingsShell: {
		name: 'CnAdminSettingsShell',
		props: ['tabs', 'breadcrumbs', 'breadcrumbNote', 'title'],
		render() {
			const tabSlots = Object.keys(this.$slots).filter((name) => name.startsWith('tab-'))
			return h('div', { 'data-stub': 'CnAdminSettingsShell' }, [
				h('nav', this.tabs.map((tab) => h('span', { 'data-tab': tab.id }, tab.label + (tab.count !== undefined ? ` (${tab.count})` : '')))),
				h('ol', this.breadcrumbs.map((crumb) => h('li', crumb.label))),
				h('p', this.breadcrumbNote),
				this.$slots.default?.(),
				...tabSlots.map((name) => h('div', { 'data-slot': name }, this.$slots[name]())),
			])
		},
	},
}))

globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_m, key) => vars[key]) : text)

const { default: AdminRoot } = await import('../../src/views/settings/AdminRoot.vue')
const { default: Settings } = await import('../../src/views/settings/Settings.vue')
const { default: PipelinqIntegration } = await import('../../src/views/settings/PipelinqIntegration.vue')
const { default: StandardsPolicyEditor } = await import('../../src/views/settings/StandardsPolicyEditor.vue')

/**
 * Render a view, its data laid over its own starting data.
 *
 * @param {object} view The component.
 * @param {object} [state] Data to lay over the view's own.
 * @return {Promise<string>} The HTML.
 */
async function render(view, state = {}) {
	const own = view.data
	const app = createSSRApp({ ...view, data() { return { ...own.call(this), ...state } } })
	app.config.globalProperties.t = globalThis.t
	// Fragment and v-if markers are SSR bookkeeping, not what a reader sees.
	return (await renderToString(app)).replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * A response the pipelinq settings read answers with.
 *
 * @param {boolean} ok Whether the read succeeded.
 * @param {object} body The JSON body.
 * @return {object}
 */
function response(ok, body) {
	return { ok, json: async () => body }
}

beforeEach(() => {
	axiosMock.get.mockReset()
	initializeStoresMock.mockReset().mockResolvedValue(undefined)
	settingsStoreMock.settings = null
	globalThis.OC = { requestToken: 'tok' }
	globalThis.fetch = vi.fn().mockResolvedValue(response(false, {}))
})

afterEach(() => {
	delete globalThis.window
	delete globalThis.OC
	delete globalThis.fetch
})

describe('Settings', () => {
	it('renders the stored register and return address, and the save message', async () => {
		settingsStoreMock.settings = { register: 'shillinq', portal_payment_redirect_url: 'https://portal.example/paid' }

		const html = await render(Settings, { successMessage: 'Settings saved successfully' })

		expect(html).toMatch(/id="register"[^>]*value="shillinq"|value="shillinq"[^>]*id="register"/)
		expect(html).toContain('https://portal.example/paid')
		expect(html).toContain('class="success-message"')
		expect(html).not.toContain('role="alert"')
		expect(html).toMatch(/<button[^>]*>\s*Save\s*<\/button>/)
	})

	it('shows the refusal as an alert and Saving… on a disabled button while it saves', async () => {
		const html = await render(Settings, { saving: true, errorMessage: 'The settings were not saved.' })

		expect(html).toMatch(/role="alert"[^>]*>\s*The settings were not saved\./)
		expect(html).toMatch(/<button[^>]*disabled[^>]*>\s*Saving…/)
	})
})

describe('PipelinqIntegration', () => {
	/**
	 * An instance of the form with its methods bound.
	 *
	 * @return {object}
	 */
	function instance() {
		const vm = { ...PipelinqIntegration.data.call({}) }
		for (const [name, method] of Object.entries(PipelinqIntegration.methods)) {
			vm[name] = method.bind(vm)
		}
		return vm
	}

	it('reads the stored endpoint and whether a token is stored, with the request token', async () => {
		globalThis.fetch.mockResolvedValue(response(true, { endpoint: 'https://pipelinq.example/api', hasToken: 1 }))
		const vm = instance()

		await vm.fetchSettings()

		expect(globalThis.fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/pipelinq/settings', { headers: { requesttoken: 'tok' } })
		expect(vm.form.endpoint).toBe('https://pipelinq.example/api')
		expect(vm.hasToken).toBe(true)
	})

	it('keeps the empty form when the read is refused or fails', async () => {
		const vm = instance()
		await vm.fetchSettings()
		expect(vm.form.endpoint).toBe('')
		expect(vm.hasToken).toBe(false)

		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		globalThis.fetch.mockRejectedValue(new Error('offline'))
		await vm.fetchSettings()
		expect(vm.form.endpoint).toBe('')
		expect(error).toHaveBeenCalledWith('Failed to fetch pipelinq settings:', expect.any(Error))
		error.mockRestore()
	})

	it('says a token is stored and masks it, and holds Test back without an endpoint', async () => {
		const stored = await render(PipelinqIntegration, { hasToken: true })
		expect(stored).toContain('placeholder="••••••••••••"')
		expect(stored).toContain('A token is stored. Leave empty to keep the current token, or paste a new one to rotate it.')
		expect(stored).toMatch(/<button[^>]*disabled[^>]*>\s*Test connection/)

		const fresh = await render(PipelinqIntegration, { form: { endpoint: 'https://p.example', token: '' }, feedback: { kind: 'error', message: 'Connection refused' } })
		expect(fresh).toContain('Paste your pipelinq API token')
		expect(fresh).toContain('never returned to the browser')
		expect(fresh).toMatch(/class="feedback error"[^>]*>\s*Connection refused/)
		expect(fresh).not.toMatch(/<button[^>]*disabled[^>]*>\s*Test connection/)
	})

	it('names the running act on its button', async () => {
		const html = await render(PipelinqIntegration, { saving: true, testing: true, form: { endpoint: 'https://p.example', token: '' } })

		expect(html).toMatch(/<button[^>]*disabled[^>]*>\s*Saving…/)
		expect(html).toMatch(/<button[^>]*disabled[^>]*>\s*Testing…/)
	})
})

describe('StandardsPolicyEditor', () => {
	it('shows only the loading icon while the policy loads', async () => {
		const html = await render(StandardsPolicyEditor)

		expect(html).toContain('data-stub="NcLoadingIcon"')
		expect(html).not.toContain('data-testid="standards-policy-list"')
	})

	it('asks for an administration first, and offers nothing to change', async () => {
		const html = await render(StandardsPolicyEditor, { loading: false })

		expect(html).toContain('Choose an administration to see its standards policy.')
		expect(html).toMatch(/data-testid="standards-policy-resolved"[\s\S]*<strong[^>]*>—<\/strong>/)
		expect(html).toMatch(/<button[^>]*disabled[^>]*>[\s\S]*?Save policy/)
	})

	it('tells a non-owner only an owner can change the policy', async () => {
		const html = await render(StandardsPolicyEditor, { loading: false, administrationId: 'adm-1' })

		expect(html).toContain('Only an owner of this administration can change its standards policy.')
	})

	it('lets an owner reorder and save, ranks the rows, and resolves the highest enabled one', async () => {
		const editor = { ...StandardsPolicyEditor.data.call({}) }
		const rows = editor.rows.slice(0, 3).map((row, index) => ({ ...row, enabled: index > 0 }))
		const html = await render(StandardsPolicyEditor, { loading: false, administrationId: 'adm-1', canEdit: true, rows })

		expect(html).not.toContain('data-testid="standards-policy-readonly"')
		expect(html.match(/class="standards-policy__rank"[^>]*>(\d)</g).map((m) => m.slice(-2, -1))).toEqual(['1', '2', '3'])
		expect(html).toContain(`data-testid="standards-policy-row-${rows[0].key}"`)
		expect(html).toMatch(new RegExp(`aria-label="Read about ${rows[1].label.replace(/[()+/]/g, '.')} \\(opens in a new tab\\)"`))
		expect(html).toMatch(/data-testid="standards-policy-resolved"[\s\S]*?<strong[^>]*>([^<]+)<\/strong>/)
		expect(html.match(/data-testid="standards-policy-resolved"[\s\S]*?<strong[^>]*>([^<]+)<\/strong>/)[1]).toBe(rows[1].label)
		// The first row cannot move up and the last cannot move down; the rest can.
		expect(html.match(/<button[^>]*disabled[^>]*>/g)).toHaveLength(2)
		expect(html.match(/data-checked="true"/g)).toHaveLength(2)
	})
})

describe('AdminRoot', () => {
	beforeEach(() => {
		globalThis.window = { location: { search: '?tab=connections' } }
	})

	it('opens on the tab the address names and draws a section per tab with its link', async () => {
		const html = await render(AdminRoot)

		expect(html).toMatch(/<span data-tab="general">General<\/span><span data-tab="required-fields">Required fields<\/span><span data-tab="connections">Connections<\/span><span data-tab="appointments">Appointments<\/span>/)
		expect(html).toMatch(/<ol><li>Administration settings<\/li><li>Shillinq<\/li><li>Connections<\/li><\/ol>/)
		expect(html).toContain('only for administrators, each section saves on its own')
		expect(html).toMatch(/data-slot="tab-required-fields"[\s\S]*?data-stub="CnToggleTableWidget"[\s\S]*?href="\/index.php\/apps\/shillinq\/settings\/required-fields"[^>]*>\s*Manage required fields/)
		expect(html).toMatch(/data-slot="tab-connections"[\s\S]*?href="\/index.php\/apps\/shillinq\/external-adapters"[^>]*>\s*Open the connections/)
		expect(html.match(/data-stub="CnToggleTableWidget"/g)).toHaveLength(1)
	})

	it('shows the settings forms only once the stores are up, and starts them on creation', async () => {
		const waiting = await render(AdminRoot)
		expect(initializeStoresMock).toHaveBeenCalled()
		expect(waiting).not.toContain('Pipelinq integration')

		const ready = await render(AdminRoot, { storesReady: true })
		expect(ready).toContain('Pipelinq integration')
		expect(ready).toContain('OpenRegister register ID')
	})

	it('reads the required field count when it is created', async () => {
		await render(AdminRoot)

		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/shillinq/FieldRequirement', { params: { _limit: 1 } })
	})

	it('opens on General without a tab in the address', async () => {
		globalThis.window = { location: { search: '' } }
		expect(AdminRoot.data.call({}).activeTab).toBe('general')
	})

	it('starts the stores again after a re-import', () => {
		AdminRoot.methods.onReimported.call({})
		expect(initializeStoresMock).toHaveBeenCalledTimes(1)
	})
})
