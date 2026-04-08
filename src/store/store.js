// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useOrganizationStore } from './modules/organization.js'
import { useAppSettingsStore } from './modules/appSettings.js'
import { useDashboardStore } from './modules/dashboard.js'
import { useDataJobStore } from './modules/dataJob.js'

export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	const organizationStore = useOrganizationStore()
	const appSettingsStore = useAppSettingsStore()
	const dashboardStore = useDashboardStore()
	const dataJobStore = useDataJobStore()

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
