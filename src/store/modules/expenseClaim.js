// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Expense claim store.
 *
 * @see openspec/changes/general/tasks.md#task-3.4
 */
const expenseClaimPlugin = {
	name: 'expenseClaim',
	getters: {
		claims: (state) => state.collections?.ExpenseClaim || [],
		getPendingCount: (state) => {
			const claims = state.collections?.ExpenseClaim || []
			return claims.filter((c) => c.status === 'submitted' || c.status === 'under_review').length
		},
	},
	actions: {
		async fetchClaims() {
			return this.fetchCollection('ExpenseClaim')
		},
	},
}

export const useExpenseClaimStore = createObjectStore('ExpenseClaim', { plugins: [expenseClaimPlugin] })
