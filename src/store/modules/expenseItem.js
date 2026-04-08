// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Expense item store.
 *
 * @see openspec/changes/general/tasks.md#task-3.4
 */
const expenseItemPlugin = {
	name: 'expenseItem',
	getters: {
		items: (state) => state.collections?.ExpenseItem || [],
		getItemsByClaimId: (state) => (claimId) => {
			const items = state.collections?.ExpenseItem || []
			return items.filter((i) => i.expenseClaimId === claimId)
		},
	},
	actions: {
		async fetchItems() {
			return this.fetchCollection('ExpenseItem')
		},
	},
}

export const useExpenseItemStore = createObjectStore('ExpenseItem', { plugins: [expenseItemPlugin] })
