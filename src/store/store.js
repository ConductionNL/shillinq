import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * Bootstrap the Pinia stores: point the object store at OpenRegister and
 * eagerly load settings.
 *
 * @return {Promise<object>} The instantiated settings + object stores.
 * @spec exclude Store bootstrap glue — wires base URLs and triggers the
 * settings load; the observable behaviors live in the store actions
 * themselves (see REQ-Admin-001 / REQ-Admin-005).
 */
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
