/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The budget mapping's record pickers load on creation and load again when
 * their scope changes: what each load asks the object endpoint for.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const requests = []
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (url, config) => {
			requests.push([url, config.params])
			return Promise.resolve({ data: { results: [] } })
		},
	},
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({ NcSelect: {} }))

/**
 * A fake instance: merged data and methods, props from state, recorded emits.
 *
 * @param {object} component The SFC.
 * @param {object} state Props and data to set.
 * @return {object} The instance.
 */
function instance(component, state) {
	const layers = [...(component.mixins ?? []), component]
	const vm = {
		...Object.assign({}, ...layers.map((layer) => layer.data?.() ?? {})),
		$emit() {},
		...state,
	}
	for (const layer of layers) {
		for (const [name, method] of Object.entries(layer.methods ?? {})) {
			vm[name] = method.bind(vm)
		}
	}
	return vm
}

describe('BBVProgrammePicker', () => {
	let component

	beforeEach(async () => {
		component = (await import('../../src/components/BudgetBBVMapping/BBVProgrammePicker.vue')).default
		requests.length = 0
	})

	it('defaults to the calendar year and loads the active programmes of it on creation', async () => {
		const year = component.props.fiscalYear.default()
		expect(year).toBe(new Date().getFullYear())

		const vm = instance(component, { fiscalYear: year, administrationId: '' })
		await component.created.call(vm)

		expect(requests).toEqual([
			['/apps/openregister/api/objects/shillinq/BBVProgramme', { fiscalYear: year, status: 'active', limit: 500 }],
		])
		expect(vm.loading).toBe(false)
	})

	it('loads again for a new fiscal year and a new administration', async () => {
		const vm = instance(component, { fiscalYear: 2027, administrationId: '' })
		component.watch.fiscalYear.call(vm)
		vm.administrationId = 'adm-1'
		component.watch.administrationId.call(vm)
		await vi.waitFor(() => expect(requests).toHaveLength(2))

		expect(requests[0][1]).toEqual({ fiscalYear: 2027, status: 'active', limit: 500 })
		expect(requests[1][1]).toEqual({ fiscalYear: 2027, status: 'active', limit: 500, administrationId: 'adm-1' })
	})
})

describe('GlAccountPicker', () => {
	let component

	beforeEach(async () => {
		component = (await import('../../src/components/BudgetBBVMapping/GlAccountPicker.vue')).default
		requests.length = 0
	})

	it('loads the accounts on creation and again for a new administration', async () => {
		const vm = instance(component, { administrationId: '' })
		await component.created.call(vm)
		vm.administrationId = 'adm-2'
		component.watch.administrationId.handler.call(vm)
		await vi.waitFor(() => expect(requests).toHaveLength(2))

		expect(component.watch.administrationId.immediate).toBe(false)
		expect(requests).toEqual([
			['/apps/openregister/api/objects/shillinq/Account', { _limit: 500 }],
			['/apps/openregister/api/objects/shillinq/Account', { _limit: 500, administrationId: 'adm-2' }],
		])
	})

	it('shows no balance for an amount that is not a number', () => {
		const vm = instance(component, {})
		expect(vm.formatEuro('abc')).toBe('')
		expect(vm.formatEuro(Infinity)).toBe('')
		expect(vm.formatEuro(123456).replace(/\s/g, ' ')).toBe('€ 1.235')
	})
})
