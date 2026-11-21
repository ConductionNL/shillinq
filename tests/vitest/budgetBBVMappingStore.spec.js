/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The BudgetBBVMapping store registers its object type against the shillinq
 * register and BudgetBBVMapping schema once, on the OpenRegister endpoint.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

const lib = vi.hoisted(() => ({ created: null, store: null }))

vi.mock('@conduction/nextcloud-vue', () => ({
	relationsPlugin: () => 'relations',
	auditTrailsPlugin: () => 'audit-trails',
	createObjectStore: (id, options) => {
		lib.created = { id, options }
		return () => lib.store
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { REGISTER_SLUG, SCHEMA_SLUG, TYPE_SLUG, useBudgetBBVMappingStore } = await import('../../src/store/modules/budgetBBVMappingStore.js')

describe('useBudgetBBVMappingStore', () => {
	it('builds the store on the OpenRegister objects endpoint with relations and audit trails', () => {
		expect(lib.created).toEqual({
			id: 'budget-bbv-mapping',
			options: { plugins: ['relations', 'audit-trails'], baseUrl: '/index.php/apps/openregister/api/objects' },
		})
		expect([TYPE_SLUG, SCHEMA_SLUG, REGISTER_SLUG]).toEqual(['budgetBBVMapping', 'BudgetBBVMapping', 'shillinq'])
	})

	it('registers the object type once', () => {
		const registry = {}
		lib.store = {
			objectTypeRegistry: registry,
			registerObjectType: vi.fn((type) => {
				registry[type] = true
			}),
		}

		expect(useBudgetBBVMappingStore()).toBe(lib.store)
		useBudgetBBVMappingStore()

		expect(lib.store.registerObjectType).toHaveBeenCalledTimes(1)
		expect(lib.store.registerObjectType).toHaveBeenCalledWith('budgetBBVMapping', 'BudgetBBVMapping', 'shillinq', {
			registerSlug: 'shillinq',
			schemaSlug: 'BudgetBBVMapping',
		})
	})

	it('leaves a store without type registration alone', () => {
		lib.store = { objectTypeRegistry: {} }

		expect(useBudgetBBVMappingStore()).toBe(lib.store)
	})
})
