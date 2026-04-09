// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * @see openspec/changes/general/tasks.md#task-3.3
 */
import { defineStore } from 'pinia'

export const useAutomationRuleStore = defineStore('automationRule', {
	state: () => ({
		automationRules: [],
		loading: false,
	}),

	getters: {
		activeRuleCount: (state) => state.automationRules.filter((r) => r.isActive).length,
	},

	actions: {
		/**
		 * Fetch all automation rules from OpenRegister.
		 *
		 * @see openspec/changes/general/tasks.md#task-3.3
		 */
		async fetchRules() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				objectStore.registerObjectType('AutomationRule', 'AutomationRule', 'shillinq')
				this.automationRules = await objectStore.fetchObjects('AutomationRule')
			} catch (error) {
				console.error('Failed to fetch automation rules:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
