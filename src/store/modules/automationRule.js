// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'

/**
 * Automation rule store.
 *
 * @see openspec/changes/general/tasks.md#task-3.3
 */
export const useAutomationRuleStore = defineStore('automationRule', {
	state: () => ({
		rules: [],
		loading: false,
	}),

	getters: {
		getRules: (state) => state.rules,
		getActiveRules: (state) => state.rules.filter((r) => r.isActive),
	},

	actions: {
		async fetchRules() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				this.rules = await objectStore.fetchObjects('AutomationRule')
				return this.rules
			} catch (error) {
				console.error('Failed to fetch automation rules:', error)
			} finally {
				this.loading = false
			}
			return []
		},
	},
})
