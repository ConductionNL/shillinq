// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @spec openspec/changes/general/tasks.md#task-3.2
 */
import { defineStore } from 'pinia'

export const usePortalStore = defineStore('portal', {
	state: () => ({
		portalTokens: [],
		loading: false,
	}),

	actions: {
		/**
		 * Fetch all portal tokens from OpenRegister.
		 *
		 * @spec openspec/changes/general/tasks.md#task-3.2
		 */
		async fetchTokens() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				objectStore.registerObjectType('PortalToken', 'PortalToken', 'shillinq')
				this.portalTokens = await objectStore.fetchObjects('PortalToken')
			} catch (error) {
				console.error('Failed to fetch portal tokens:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
