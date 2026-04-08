// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'

/**
 * Expense item store.
 *
 * @spec openspec/changes/general/tasks.md#task-3.4
 */
export const useExpenseItemStore = defineStore('expenseItem', {
	state: () => ({
		items: [],
		loading: false,
	}),

	getters: {
		getItems: (state) => state.items,
		getItemsByClaimId: (state) => (claimId) =>
			state.items.filter((i) => i.expenseClaimId === claimId),
	},

	actions: {
		async fetchItems() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				this.items = await objectStore.fetchObjects('ExpenseItem')
				return this.items
			} catch (error) {
				console.error('Failed to fetch expense items:', error)
			} finally {
				this.loading = false
			}
			return []
		},
	},
})
