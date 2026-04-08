import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useAnalyticsStore } from './modules/analytics.js'
import { usePortalStore } from './modules/portal.js'
import { useAutomationRuleStore } from './modules/automationRule.js'
import { useExpenseClaimStore } from './modules/expenseClaim.js'
import { useExpenseItemStore } from './modules/expenseItem.js'

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

export {
	useObjectStore,
	useSettingsStore,
	useAnalyticsStore,
	usePortalStore,
	useAutomationRuleStore,
	useExpenseClaimStore,
	useExpenseItemStore,
}
