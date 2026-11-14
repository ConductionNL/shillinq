/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The receipt's category picker offers only the active categories of the
 * receipt's administration (expenses-category-mapping REQ-ECM-002).
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { categoryName, pickableCategories } from '../../src/utils/expenseCategories.js'

const answer = vi.hoisted(() => ({ requests: [], rows: [] }))
const requests = answer.requests
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			answer.requests.push([url, config])
			return Promise.resolve({ data: { results: answer.rows } })
		},
	},
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, getLanguage: () => 'nl' }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({ NcSelect: {} }))

const CATEGORIES = [
	{ code: 'travel-public', name: { nl: 'Openbaar vervoer', en: 'Public transport' }, active: true, administrationId: 'adm-1' },
	{ code: 'meals', name: { nl: 'Maaltijden en representatie', en: 'Meals and entertainment' }, active: true, administrationId: 'adm-1' },
	{ code: 'fuel', name: { nl: 'Brandstof', en: 'Fuel' }, active: false, administrationId: 'adm-1' },
	{ code: 'office', name: { nl: 'Kantoorartikelen', en: 'Office supplies' }, active: true, administrationId: 'adm-2' },
	{ code: '', name: { nl: 'Zonder code' }, active: true, administrationId: 'adm-1' },
	{ code: 'other', name: {}, active: 'true', administrationId: 'adm-1' },
]
answer.rows = CATEGORIES

/**
 * A fake instance of the picker: mixin and component merged, computed as getters.
 *
 * @param {object} component The SFC.
 * @param {object} state Props and data.
 * @return {object} The instance.
 */
function instance(component, state) {
	const layers = [...(component.mixins ?? []), component]
	const vm = {
		...Object.assign({}, ...layers.map((layer) => layer.data?.() ?? {})),
		emitted: [],
		$emit(...args) {
			this.emitted.push(args)
		},
		...state,
	}
	for (const layer of layers) {
		for (const [name, method] of Object.entries(layer.methods ?? {})) {
			vm[name] = method.bind(vm)
		}
	}
	for (const layer of layers) {
		for (const [name, getter] of Object.entries(layer.computed ?? {})) {
			Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
		}
	}
	return vm
}

describe('pickableCategories', () => {
	it('keeps the active categories of the administration, by code', () => {
		expect(pickableCategories(CATEGORIES, 'adm-1').map((c) => c.code)).toEqual(['meals', 'travel-public'])
	})

	it('keeps every active category when the administration is unknown', () => {
		expect(pickableCategories(CATEGORIES, '').map((c) => c.code)).toEqual(['meals', 'office', 'travel-public'])
		expect(pickableCategories(null, 'adm-1')).toEqual([])
	})
})

describe('categoryName', () => {
	it('names a category in the language asked for, else the other, else its code', () => {
		expect(categoryName(CATEGORIES[1], 'nl_NL')).toBe('Maaltijden en representatie')
		expect(categoryName(CATEGORIES[1], 'en')).toBe('Meals and entertainment')
		expect(categoryName({ code: 'x', name: { nl: 'Alleen NL' } }, 'en')).toBe('Alleen NL')
		expect(categoryName(CATEGORIES[5], 'nl')).toBe('other')
	})
})

describe('ExpenseCategoryPicker', () => {
	let component

	beforeEach(async () => {
		component = (await import('../../src/components/expenses/ExpenseCategoryPicker.vue')).default
		requests.length = 0
	})

	it('lists the active categories of the receipt\'s administration only', async () => {
		const vm = instance(component, { administrationId: 'adm-1', modelValue: '' })
		await vm.fetchCategories()

		expect(requests).toEqual([
			['/apps/openregister/api/objects/shillinq/ExpenseCategory', { params: { _limit: 500, active: true, administrationId: 'adm-1' } }],
		])
		expect(vm.options.map((option) => [option.value, option.display])).toEqual([
			['meals', 'Maaltijden en representatie (meals)'],
			['travel-public', 'Openbaar vervoer (travel-public)'],
		])
	})

	it('emits the chosen code, which is what Receipt.category stores', () => {
		const vm = instance(component, { administrationId: 'adm-1', modelValue: '', categories: CATEGORIES })
		vm.onUpdateModelValue(vm.options[0])

		expect(vm.emitted[0]).toEqual(['update:modelValue', 'meals'])
		expect(vm.emitted[1]).toEqual(['selected', CATEGORIES[1]])
	})

	it('searches by Dutch name and by code', () => {
		const vm = instance(component, { administrationId: 'adm-1', modelValue: 'meals', categories: CATEGORIES })
		vm.onSearch('openbaar')
		expect(vm.filteredOptions.map((option) => option.value)).toEqual(['travel-public'])
		vm.onSearch('MEA')
		expect(vm.filteredOptions.map((option) => option.value)).toEqual(['meals'])
		expect(vm.selectedOption.value).toBe('meals')
	})
})
