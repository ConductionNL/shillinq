// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'
import { useAnalyticsStore } from './modules/analytics.js'
import { usePortalStore } from './modules/portal.js'
import { useAutomationRuleStore } from './modules/automationRule.js'
import { useExpenseClaimStore } from './modules/expenseClaim.js'
import { useExpenseItemStore } from './modules/expenseItem.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export {
	useObjectStore,
	useSettingsStore,
	useAnalyticsStore,
	usePortalStore,
	useAutomationRuleStore,
	useExpenseClaimStore,
	useExpenseItemStore,
}
