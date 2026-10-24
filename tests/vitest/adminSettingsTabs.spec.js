/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Board ShInstellingen: the admin page draws the board's tab strip. General
 * holds the app's own settings, each other tab one section, the Required
 * fields tab counts its records, and the last crumb names the open tab.
 *
 * @spec openspec/specs/platform-required-fields/spec.md
 */

import axios from '@nextcloud/axios'
import { describe, expect, it, vi } from 'vitest'
import AdminRoot from '../../src/views/settings/AdminRoot.vue'

/**
 * A fake component instance with the computed properties bound to it.
 *
 * @param {object} state The data fields.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const vm = {
		t: (app, text) => text,
		activeTab: 'general',
		requiredFieldCount: null,
		...state,
	}
	for (const name of ['pageSections', 'tabs', 'breadcrumbs', 'configurationFields', 'settingsUrl']) {
		Object.defineProperty(vm, name, {
			get: () => AdminRoot.computed[name].call(vm),
		})
	}
	return vm
}

describe('admin settings tabs', () => {
	it('opens on General, then one tab per section', () => {
		expect(instance().tabs.map((tab) => tab.id)).toEqual([
			'general',
			'required-fields',
			'connections',
			'appointments',
		])
	})

	it('counts the required fields once they are read', () => {
		expect(instance().tabs[1].count).toBeUndefined()
		expect(instance({ requiredFieldCount: 2 }).tabs[1]).toMatchObject({
			label: 'Required fields',
			count: 2,
		})
	})

	it('chooses the register from OpenRegister and checks the return address', () => {
		const [register, returnAddress] = instance().configurationFields
		expect(register).toMatchObject({ key: 'register', type: 'select', optionsPath: 'results', optionValue: 'slug' })
		expect(register.optionsUrl).toContain('/apps/openregister/api/registers')
		expect(new RegExp(returnAddress.pattern).test('https://portal.example.nl')).toBe(true)
		expect(new RegExp(returnAddress.pattern).test('http://portal.example.nl')).toBe(false)
		expect(instance().settingsUrl).toContain('/apps/shillinq/api/settings')
	})

	it('draws a switch per required field on the Required fields tab', () => {
		const sections = instance().pageSections
		const widget = sections.find((s) => s.id === 'required-fields').widget
		expect(widget.type).toBe('toggle-table')
		expect(widget.content.source).toMatchObject({
			url: '/apps/shillinq/api/field-requirements',
			path: 'rows',
			key: 'id',
		})
		expect(widget.content.bind).toEqual({
			mode: 'each',
			field: 'enabled',
			register: 'shillinq',
			schema: 'FieldRequirement',
		})
		expect(sections.filter((s) => s.widget).map((s) => s.id)).toEqual([
			'required-fields',
		])
	})

	it('translates the widget labels through the app catalogue', () => {
		const vm = { t: (app, text) => `${app}:${text}` }
		expect(AdminRoot.provide.call(vm).cnTranslate('On')).toBe('shillinq:On')
	})

	it('names the open tab in the last crumb, General for an unknown one', () => {
		const crumbs = (state) => instance(state).breadcrumbs.map((c) => c.label)
		expect(crumbs({ activeTab: 'connections' })[2]).toBe('Connections')
		expect(crumbs({ activeTab: 'nothing' })[2]).toBe('General')
	})

	it('reads the count from the FieldRequirement total and drops it on a failed read', async () => {
		const vm = instance()
		axios.get = vi.fn(async () => ({ data: { total: 12 } }))
		await AdminRoot.methods.loadRequiredFieldCount.call(vm)
		expect(vm.requiredFieldCount).toBe(12)
		expect(axios.get.mock.calls[0][0]).toContain(
			'/objects/shillinq/FieldRequirement',
		)

		axios.get = vi.fn(async () => {
			throw new Error('403')
		})
		await AdminRoot.methods.loadRequiredFieldCount.call(vm)
		expect(vm.requiredFieldCount).toBeNull()
	})
})
