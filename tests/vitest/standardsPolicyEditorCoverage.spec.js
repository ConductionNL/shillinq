/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * StandardsPolicyEditor as it renders (server-side, so no DOM is needed):
 * the loading state, the read-only notice with and without an
 * administration, one row per framework with its switch, docs link and move
 * buttons enabled only where they apply, and the save button; what those
 * controls are bound to; and the load on mount. The stubbed Nc components
 * record their attributes and the vnodes of their slots.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md#requirement-req-asp-002-administrator-can-declare-and-order-frameworks
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { fetchAdministrationContext } from '../../src/api/administrationApi.js'

const captured = vi.hoisted(() => ({ list: [] }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key])) }))
vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: vi.fn() }))
vi.mock('@nextcloud/vue', async () => {
	const { ssrRenderAttrs: attrs, ssrRenderSlot: slot } = await import('vue/server-renderer')
	/**
	 * A stub that records its attributes and slot vnodes, and renders its
	 * slots as HTML inside `tag`.
	 *
	 * @param {string} name The component name.
	 * @param {string} tag The element rendered.
	 * @return {object} The component.
	 */
	const stub = (name, tag) => ({
		name,
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			const slots = Object.fromEntries(Object.keys(ctx.$slots).map((key) => [key, ctx.$slots[key]()]))
			captured.list.push({ name, attrs: { ...ctx.$attrs }, slots })
			push(`<${tag}${attrs({ 'data-stub': name, disabled: ctx.$attrs.disabled, 'aria-label': ctx.$attrs['aria-label'] })}>`)
			for (const key of Object.keys(ctx.$slots)) {
				slot(ctx.$slots, key, {}, null, push, parent)
			}
			push(`</${tag}>`)
		},
	})
	return {
		NcButton: stub('NcButton', 'button'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch', 'label'),
		NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	}
})

const { default: StandardsPolicyEditor } = await import('../../src/views/settings/StandardsPolicyEditor.vue')

/**
 * Render the editor with its data changed by `state`.
 *
 * @param {object} state The data to change.
 * @return {Promise<{html: string, data: object}>} The HTML and the data rendered.
 */
async function render(state = {}) {
	const data = { ...StandardsPolicyEditor.data.call({}), ...state }
	const html = await renderToString(createSSRApp({ ...StandardsPolicyEditor, mounted: undefined, data: () => data }))
	return { html, data }
}

/**
 * Three framework rows, the second enabled.
 *
 * @return {Array<object>} The rows.
 */
function rows() {
	return [
		{ key: 'ifrs', label: 'IFRS', docs: 'https://d/ifrs', enabled: false },
		{ key: 'esrs', label: 'ESRS', docs: 'https://d/esrs', enabled: true },
		{ key: 'bbv', label: 'BBV', docs: 'https://d/bbv', enabled: false },
	]
}

/**
 * The recorded renders of one stubbed component.
 *
 * @param {string} name The component name.
 * @return {Array<object>} Its records, in render order.
 */
function named(name) {
	return captured.list.filter((entry) => entry.name === name)
}

describe('StandardsPolicyEditor render', () => {
	beforeEach(() => {
		captured.list.length = 0
		vi.clearAllMocks()
		axios.get = vi.fn()
		axios.put = vi.fn()
	})

	it('shows only the loading icon while loading', async () => {
		const { html } = await render()

		expect(html).toContain('data-testid="StandardsPolicyEditor"')
		expect(html).toMatch(/<h2[^>]*>Accounting standards<\/h2>/)
		expect(captured.list.map((entry) => entry.name)).toEqual(['NcLoadingIcon'])
		expect(captured.list[0].attrs).toMatchObject({ size: 32, class: 'standards-policy__loading' })
		expect(html).not.toContain('standards-policy-list')
	})

	it('asks for an administration, and names the owners of one, while read-only', async () => {
		const none = await render({ loading: false, rows: rows() })
		expect(none.html).toMatch(/data-testid="standards-policy-readonly"[^>]*>\s*Choose an administration to see its standards policy\.\s*</)

		const owned = await render({ loading: false, administrationId: 'a1', rows: rows() })
		expect(owned.html).toMatch(/data-testid="standards-policy-readonly"[^>]*>\s*Only an owner of this administration can change its standards policy\.\s*</)

		const editable = await render({ loading: false, administrationId: 'a1', canEdit: true, rows: rows() })
		expect(editable.html).not.toContain('standards-policy-readonly')
	})

	it('renders a ranked row per framework with its switch and docs link', async () => {
		const { html } = await render({ loading: false, canEdit: true, rows: rows() })

		expect(html).toMatch(/data-testid="standards-policy-row-esrs"/)
		expect(html).toMatch(/class="standards-policy__row--enabled standards-policy__row" data-testid="standards-policy-row-esrs"/)
		expect(html).toMatch(/class="standards-policy__row" data-testid="standards-policy-row-ifrs"/)
		expect(html).toMatch(/<span class="standards-policy__rank"[^>]*>2<\/span>/)
		expect(html).toContain('href="https://d/bbv"')
		expect(html).toContain('aria-label="Read about BBV (opens in a new tab)"')
		expect(html).toContain('title="Read about this standard"')
		expect(html).toMatch(/data-testid="standards-policy-resolved"[\s\S]*Resolved framework \(highest enabled\):[\s\S]*<strong[^>]*>ESRS<\/strong>/)

		const switches = named('NcCheckboxRadioSwitch')
		expect(switches.map((entry) => [entry.attrs.modelValue, entry.attrs.disabled, entry.attrs.type])).toEqual([
			[false, false, 'switch'],
			[true, false, 'switch'],
			[false, false, 'switch'],
		])
		expect(switches[1].slots.default[0].children).toBe('ESRS')
	})

	it('disables the move buttons at the ends and everything while read-only', async () => {
		await render({ loading: false, canEdit: true, rows: rows() })
		const moves = named('NcButton').slice(0, 6).map((entry) => [entry.attrs['aria-label'], entry.attrs.disabled])
		expect(moves).toEqual([
			['Move up', true], ['Move down', false],
			['Move up', false], ['Move down', false],
			['Move up', false], ['Move down', true],
		])
		const save = named('NcButton').at(-1)
		expect([save.attrs.variant, save.attrs.disabled]).toEqual(['primary', false])

		captured.list.length = 0
		await render({ loading: false, canEdit: false, rows: rows() })
		expect(named('NcButton').every((entry) => entry.attrs.disabled === true)).toBe(true)
		expect(named('NcCheckboxRadioSwitch').every((entry) => entry.attrs.disabled === true)).toBe(true)
	})

	it('switches, moves and saves through the bound controls', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const { data } = await render({ loading: false, canEdit: true, administrationId: 'a1', rows: rows() })

		named('NcCheckboxRadioSwitch')[2].attrs['onUpdate:modelValue'](true)
		expect(data.rows[2].enabled).toBe(true)

		const buttons = named('NcButton')
		buttons[4].attrs.onClick()
		expect(data.rows.map((row) => row.key)).toEqual(['ifrs', 'bbv', 'esrs'])
		buttons[1].attrs.onClick()
		expect(data.rows.map((row) => row.key)).toEqual(['bbv', 'ifrs', 'esrs'])

		await buttons.at(-1).attrs.onClick()
		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/a1/standards-policy', {
			frameworks: [
				{ key: 'bbv', enabled: true, precedence: 1 },
				{ key: 'ifrs', enabled: false, precedence: 2 },
				{ key: 'esrs', enabled: true, precedence: 3 },
			],
		})
	})

	it('draws the arrow icons, and a spinner on the save button while saving', async () => {
		await render({ loading: false, canEdit: true, rows: rows().slice(0, 1) })
		const [up, down, save] = named('NcButton')
		expect([up.slots.icon[0].type.name, down.slots.icon[0].type.name]).toEqual(['ArrowUpIcon', 'ArrowDownIcon'])
		expect(save.slots.icon[0].type.name).toBe('ContentSaveIcon')
		expect(save.slots.default[0].children).toContain('Save policy')

		captured.list.length = 0
		const { html } = await render({ loading: false, canEdit: true, saving: true, rows: rows().slice(0, 1) })
		const busy = named('NcButton').at(-1)
		expect(busy.attrs.disabled).toBe(true)
		expect(busy.slots.icon[0].type.name).toBe('NcLoadingIcon')
		expect(busy.slots.icon[0].props.size).toBe(20)
		expect(html).toMatch(/<button data-stub="NcButton" disabled><!--\[--><span data-stub="NcLoadingIcon"[^>]*><\/span>/)
	})
})

describe('StandardsPolicyEditor mount', () => {
	it('loads the active administration\'s policy when mounted', async () => {
		fetchAdministrationContext.mockResolvedValue({ activeAdministrationId: 'a1' })
		axios.get = vi.fn().mockResolvedValue({ data: { canEdit: true, frameworks: [{ key: 'esrs', enabled: true, precedence: 1 }] } })
		const vm = { ...StandardsPolicyEditor.data.call({}) }
		for (const [name, method] of Object.entries(StandardsPolicyEditor.methods)) {
			vm[name] = method.bind(vm)
		}

		await StandardsPolicyEditor.mounted.call(vm)

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/a1/standards-policy')
		expect([vm.loading, vm.canEdit, vm.rows[0].key, vm.rows[0].enabled]).toEqual([false, true, 'esrs', true])
	})
})
