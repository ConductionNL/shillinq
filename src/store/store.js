// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '@conduction/nextcloud-vue'
import { useSettingsStore } from './modules/settings.js'
import { useOrganizationStore } from './modules/organization.js'
import { useAppSettingsStore } from './modules/appSettings.js'
import { useDashboardStore } from './modules/dashboard.js'
import { useDataJobStore } from './modules/dataJob.js'

/**
 * Initialize all Pinia stores and configure the object store.
 *
 * @spec openspec/changes/core/tasks.md#task-3.5
 * @return {Promise<object>} The initialized stores
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()
	const organizationStore = useOrganizationStore()
	const appSettingsStore = useAppSettingsStore()
	const dashboardStore = useDashboardStore()
	const dataJobStore = useDataJobStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	return {
		settingsStore,
		objectStore,
		organizationStore,
		appSettingsStore,
		dashboardStore,
		dataJobStore,
	}
}

export {
	useObjectStore,
	useSettingsStore,
	useOrganizationStore,
	useAppSettingsStore,
	useDashboardStore,
	useDataJobStore,
}
