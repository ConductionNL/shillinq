import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore }
export { useAnalyticsStore } from './modules/analytics.js'
export { usePortalStore } from './modules/portal.js'
export { useAutomationRuleStore } from './modules/automationRule.js'
export { useExpenseClaimStore } from './modules/expenseClaim.js'
export { useExpenseItemStore } from './modules/expenseItem.js'
