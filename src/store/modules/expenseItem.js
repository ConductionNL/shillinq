// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @see openspec/changes/general/tasks.md#task-3.4
 */
import { defineStore } from 'pinia'

export const useExpenseItemStore = defineStore('expenseItem', {
	state: () => ({
		expenseItems: [],
		loading: false,
	}),

	actions: {
		/**
		 * Fetch expense items for a given claim.
		 *
		 * @param {string} expenseClaimId The parent claim ID
		 * @see openspec/changes/general/tasks.md#task-3.4
		 */
		async fetchItemsForClaim(expenseClaimId) {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				objectStore.registerObjectType('ExpenseItem', 'ExpenseItem', 'shillinq')
				const items = await objectStore.fetchObjects('ExpenseItem', { expenseClaimId })
				this.expenseItems = items
			} catch (error) {
				console.error('Failed to fetch expense items:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
