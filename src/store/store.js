import { useSettingsStore } from './modules/settings.js'

/**
 * Bootstrap the Pinia stores: eagerly load settings.
 *
 * OR-shaped object CRUD is NOT bootstrapped here — per ADR-015/ADR-026
 * (`openspec/changes/object-store-retirement/`), this app has no generic
 * shared object store. Features that need OpenRegister object CRUD use the
 * platform's `createObjectStore` directly, scoped per feature (see
 * `src/store/modules/budgetBBVMappingStore.js`).
 *
 * @return {Promise<object>} The instantiated settings store.
 * @spec exclude Store bootstrap glue — triggers the settings load; the
 * observable behaviors live in the store actions themselves (see
 * REQ-Admin-001 / REQ-Admin-005).
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()

	await settingsStore.fetchSettings()

	return { settingsStore }
}

export { useSettingsStore }
