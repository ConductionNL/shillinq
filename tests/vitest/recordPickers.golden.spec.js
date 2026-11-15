/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the budget mapping's two record pickers: their options,
 * search, selection, emitted events, the requests they make and what they do
 * with each answer.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import { beforeEach, describe, it, vi } from 'vitest'
import { asyncOutcome, golden, outcome } from './goldenHarness.js'

const requests = []
const answer = { next: null }
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			requests.push(['get', url, config])
			return answer.next()
		},
	},
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({ NcSelect: {} }))

const check = golden('recordPickers')

const ACCOUNTS = [
	{
		accountNumber: '4300',
		accountName: 'Drukwerk',
		accountType: 'expense',
		balance: 123456,
	},
	{ accountNumber: 7, name: 'Kas', type: 'asset', balanceCents: 0 },
	{ id: 'a-3', title: 'Zonder nummer', balance: 'x' },
	{ accountNumber: '', id: 'a-4', accountName: 'Leeg nummer', balance: null },
	{ accountNumber: 0, accountName: 'Nul' },
	{},
]
const PROGRAMMES = [
	{ programmeCode: 'P1', programmeName: 'Water' },
	{ programmeCode: 12, programmeName: 'Wegen en dijken' },
	{ id: 'p-3', programmeName: 'Zonder code' },
	{ programmeCode: '', id: 'p-4' },
	{ programmeCode: 0, programmeName: 'Nul' },
	{},
]
const QUERIES = ['', '  ', '43', 'DRUK', 'water', 'p1', 'nope', '12', null]
const MODELS = ['', '4300', '7', 'P1', '12', 'unknown', '0', 'a-3', 'p-3']
const ANSWERS = {
	results: (rows) => Promise.resolve({ data: { results: rows } }),
	objects: (rows) => Promise.resolve({ data: { objects: rows } }),
	plain: (rows) => Promise.resolve({ data: rows }),
	nullResults: (rows) =>
		Promise.resolve({ data: { results: null, objects: rows } }),
	notAList: () => Promise.resolve({ data: { results: 'x' } }),
	empty: () => Promise.resolve({}),
	serverError: () =>
		Promise.reject(
			Object.assign(new Error('x'), { response: { data: { error: 'Nee' } } }),
		),
	networkError: () => Promise.reject(new Error('offline')),
}
const PICKERS = {
	GlAccountPicker: {
		rows: 'accounts',
		fetch: 'fetchAccounts',
		fixture: ACCOUNTS,
		props: {},
	},
	BBVProgrammePicker: {
		rows: 'programmes',
		fetch: 'fetchProgrammes',
		fixture: PROGRAMMES,
		props: { fiscalYear: 2026 },
	},
}

/**
 * The component's options with its mixins applied, the way Vue merges them.
 *
 * @param {object} component The SFC.
 * @return {object} data, computed and methods.
 */
function merged(component) {
	const layers = [...(component.mixins ?? []), component]
	return {
		data: () =>
			Object.assign({}, ...layers.map((layer) => layer.data?.() ?? {})),
		computed: Object.assign({}, ...layers.map((layer) => layer.computed ?? {})),
		methods: Object.assign({}, ...layers.map((layer) => layer.methods ?? {})),
	}
}

/**
 * A fake instance: data, props, methods, computed as getters, recorded emits.
 *
 * @param {object} component The SFC.
 * @param {object} state Props and data to set.
 * @return {object} The instance.
 */
function instance(component, state) {
	const options = merged(component)
	const vm = {
		...options.data(),
		emitted: [],
		$emit(...args) {
			this.emitted.push(args)
		},
		...state,
	}
	for (const [name, method] of Object.entries(options.methods)) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(options.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm) })
	}
	return vm
}

describe.each(Object.entries(PICKERS))('%s', (name, picker) => {
	let component

	beforeEach(async () => {
		component = (
			await import(`../../src/components/BudgetBBVMapping/${name}.vue`)
		).default
		requests.length = 0
	})

	it('offers its records as options', () => {
		const vm = instance(component, {
			...picker.props,
			[picker.rows]: picker.fixture,
			modelValue: '',
			query: '',
		})
		check(
			`${name} options`,
			outcome(() => vm.options),
		)
		check(
			`${name} formatDisplay null`,
			outcome(() => vm.formatDisplay(null)),
		)
	})

	it.each(QUERIES)('filters on the search %s', (query) => {
		const vm = instance(component, {
			...picker.props,
			[picker.rows]: picker.fixture,
			modelValue: '',
		})
		vm.onSearch(query)
		check(`${name} search ${query}`, {
			query: vm.query,
			options: outcome(() => vm.filteredOptions),
		})
	})

	it.each(MODELS)('shows the selected value %s', (modelValue) => {
		const loaded = instance(component, {
			...picker.props,
			[picker.rows]: picker.fixture,
			modelValue,
		})
		const waiting = instance(component, { ...picker.props, modelValue })
		check(`${name} selected ${modelValue}`, {
			loaded: outcome(() => loaded.selectedOption),
			waiting: outcome(() => waiting.selectedOption),
		})
	})

	it('emits what the user picks', () => {
		const vm = instance(component, {
			...picker.props,
			[picker.rows]: picker.fixture,
			modelValue: '',
		})
		const option = vm.options[0]
		vm.onSelected(null)
		vm.onSelected(option)
		vm.onSelected({ value: 'x' })
		vm.onUpdateModelValue(null)
		vm.onUpdateModelValue(option)
		vm.onUpdateModelValue({ value: 'x' })
		check(`${name} emits`, vm.emitted)
	})

	describe.each(Object.keys(ANSWERS))('on the answer %s', (kind) => {
		it.each(['', 'adm-1'])(
			'loads for administration "%s"',
			async (administrationId) => {
				for (const modelValue of [
					'',
					'4300',
					'P1',
					'a-3',
					'p-3',
					'a-4',
					'p-4',
					'0',
					'undefined',
					'unknown',
				]) {
					requests.length = 0
					answer.next = () => ANSWERS[kind](picker.fixture)
					const vm = instance(component, {
						...picker.props,
						administrationId,
						modelValue,
						[picker.rows]: [{ id: 'stale' }],
					})
					const result = await asyncOutcome(() => vm[picker.fetch]())
					check(`${name} ${kind} ${administrationId} ${modelValue}`, {
						result,
						requests: [...requests],
						rows: vm[picker.rows],
						loading: vm.loading,
						fetchError: vm.fetchError,
						emitted: vm.emitted,
					})
				}
			},
		)
	})
})
