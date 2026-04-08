// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'

/**
 * Expense claim store.
 *
 * @spec openspec/changes/general/tasks.md#task-3.4
 */
export const useExpenseClaimStore = defineStore('expenseClaim', {
	state: () => ({
		claims: [],
		loading: false,
	}),

	getters: {
		getClaims: (state) => state.claims,
		getPendingCount: (state) =>
			state.claims.filter((c) => c.status === 'submitted' || c.status === 'under_review').length,
	},

	actions: {
		async fetchClaims() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				this.claims = await objectStore.fetchObjects('ExpenseClaim')
				return this.claims
			} catch (error) {
				console.error('Failed to fetch expense claims:', error)
			} finally {
				this.loading = false
			}
			return []
		},
	},
})
