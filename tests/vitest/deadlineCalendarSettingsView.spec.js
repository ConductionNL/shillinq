/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * DeadlineCalendarSettings: the rows it renders, how it loads (falling back
 * to the defaults on a failure) and how it saves the toggles and lead times.
 * The template is rendered with Vue's server renderer.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/vue', () => ({
	NcAppContent: { render() { return h('main', this.$slots.default?.()) } },
	NcButton: { props: ['disabled'], render() { return h('button', { disabled: this.disabled, 'data-testid': 'save' }, this.$slots.default?.()) } },
	NcCheckboxRadioSwitch: { props: ['modelValue'], render() { return h('label', { 'data-on': String(this.modelValue) }, this.$slots.default?.()) } },
	NcLoadingIcon: { props: ['name'], render() { return h('span', { class: 'loading' }, this.name) } },
	NcTextField: { props: ['modelValue', 'label'], render() { return h('input', { class: 'lead', value: this.modelValue }) } },
}))

const { default: Settings } = await import('../../src/views/DeadlineCalendarSettings.vue')

const t = (app, text) => text

/**
 * Render the settings with their data overridden.
 *
 * @param {object} data The data to use instead of the defaults.
 * @return {Promise<string>} The HTML.
 */
async function render(data) {
	const app = createSSRApp({ ...Settings, data: () => ({ ...Settings.data(), ...data }) })
	app.config.globalProperties.t = t
	return renderToString(app)
}

/**
 * The settings' methods bound to a plain context seeded from their data.
 *
 * @return {object} The context.
 */
function context() {
	const ctx = { ...Settings.data(), t }
	for (const [name, fn] of Object.entries(Settings.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

const URL = '/index.php/apps/shillinq/api/deadline-calendar/settings'

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('DeadlineCalendarSettings rendering', () => {
	it('shows only the loading icon while loading', async () => {
		const html = await render({})
		expect(html).toContain('Loading deadline calendar settings')
		expect(html).not.toContain('<form')
	})

	it('renders each category with a lead time only when it is enabled', async () => {
		const html = await render({
			loading: false,
			rows: [
				{ id: 'filing', label: 'Filing', description: 'Filing text', enabled: true, leadDaysInput: '10' },
				{ id: 'ar-due', label: 'Invoices', description: 'Invoice text', enabled: false, leadDaysInput: '7' },
			],
		})
		expect(html).toContain('data-testid="deadline-category-filing"')
		expect(html).toContain('data-testid="deadline-category-ar-due"')
		expect(html.match(/deadline-calendar-settings__lead/g)).toHaveLength(1)
		expect(html).toContain('value="10"')
		expect(html).toContain('data-on="false"')
		expect(html).toContain('Invoice text')
		expect(html).toMatch(/data-testid="deadline-settings-save"[^>]*>Save</)
	})

	it('says it is saving, and shows the saved message and an error', async () => {
		const html = await render({ loading: false, saving: true, savedMessage: 'Deadline calendar settings saved.', errorMessage: 'Failed to save deadline calendar settings.' })
		expect(html).toContain('Saving…')
		expect(html).toMatch(/role="status"[^>]*>\s*Deadline calendar settings saved\./)
		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to save deadline calendar settings\./)
	})
})

describe('DeadlineCalendarSettings behaviour', () => {
	it('loads the stored settings with an editable lead time', async () => {
		http.get.mockResolvedValue({ data: { categories: { 'ar-due': { enabled: true, leadDays: 3 } } } })
		const vm = context()
		await vm.load()

		expect(http.get).toHaveBeenCalledWith(URL)
		const arDue = vm.rows.find((row) => row.id === 'ar-due')
		expect([arDue.enabled, arDue.leadDays, arDue.leadDaysInput]).toEqual([true, 3, '3'])
		expect([vm.loading, vm.errorMessage]).toEqual([false, ''])
	})

	it('loads the settings when mounted', async () => {
		http.get.mockResolvedValue({ data: {} })
		const vm = context()
		Settings.mounted.call(vm)
		await vi.waitFor(() => expect(vm.loading).toBe(false))
		expect(http.get).toHaveBeenCalledWith(URL)
		expect(vm.rows).toHaveLength(4)
	})

	it('falls back to the defaults and says so when loading fails', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const vm = context()
		await vm.load()

		expect(vm.rows.map((row) => [row.id, row.enabled, row.leadDaysInput])).toEqual([
			['filing', true, '10'],
			['payment-run', true, '7'],
			['ar-due', false, '7'],
			['contract', true, '7'],
		])
		expect([vm.loading, vm.errorMessage]).toEqual([false, 'Failed to load deadline calendar settings.'])
	})

	it('saves the toggles with the typed lead times and shows what was stored', async () => {
		http.post.mockResolvedValue({ data: { categories: { filing: { enabled: false, leadDays: 14 } } } })
		const vm = context()
		vm.rows = [
			{ id: 'filing', enabled: false, leadDaysInput: '14' },
			{ id: 'contract', enabled: true, leadDaysInput: 'x' },
		]
		await vm.save()

		expect(http.post).toHaveBeenCalledWith(URL, {
			categories: { filing: { enabled: false, leadDays: 14 }, contract: { enabled: true, leadDays: 0 } },
		})
		expect(vm.rows[0]).toMatchObject({ id: 'filing', enabled: false, leadDaysInput: '14' })
		expect([vm.saving, vm.savedMessage, vm.errorMessage]).toEqual([false, 'Deadline calendar settings saved.', ''])
	})

	it('says a save failed and keeps the rows', async () => {
		http.post.mockRejectedValue(new Error('down'))
		const vm = context()
		vm.rows = [{ id: 'filing', enabled: true, leadDaysInput: '5' }]
		vm.savedMessage = 'old'
		await vm.save()

		expect(vm.rows).toEqual([{ id: 'filing', enabled: true, leadDaysInput: '5' }])
		expect([vm.saving, vm.savedMessage, vm.errorMessage]).toEqual([false, '', 'Failed to save deadline calendar settings.'])
	})
})
