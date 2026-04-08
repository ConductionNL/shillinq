// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'

/**
 * Automation rule store.
 *
 * @see openspec/changes/general/tasks.md#task-3.3
 */
const automationRulePlugin = {
	name: 'automationRule',
	getters: {
		rules: (state) => state.collections?.AutomationRule || [],
		getActiveRules: (state) => (state.collections?.AutomationRule || []).filter((r) => r.isActive),
		ruleLoading: (state) => state.loading?.AutomationRule || false,
	},
	actions: {
		async fetchRules() {
			return this.fetchCollection('AutomationRule')
		},
	},
}

export const useAutomationRuleStore = createObjectStore('AutomationRule', { plugins: [automationRulePlugin] })
