// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @see openspec/changes/general/tasks.md#task-3.4
 */
import { defineStore } from 'pinia'

export const useExpenseClaimStore = defineStore('expenseClaim', {
	state: () => ({
		expenseClaims: [],
		loading: false,
	}),

	getters: {
		pendingClaimCount: (state) => state.expenseClaims.filter(
			(c) => c.status === 'submitted' || c.status === 'under_review',
		).length,
	},

	actions: {
		/**
		 * Fetch all expense claims from OpenRegister.
		 *
		 * @see openspec/changes/general/tasks.md#task-3.4
		 */
		async fetchClaims() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				objectStore.registerObjectType('ExpenseClaim', 'ExpenseClaim', 'shillinq')
				this.expenseClaims = await objectStore.fetchObjects('ExpenseClaim')
			} catch (error) {
				console.error('Failed to fetch expense claims:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
