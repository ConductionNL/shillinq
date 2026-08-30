/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Budget BBV Mapping object store (slice 06 of
 * bookkeeping-waterschappen-bbv-variant).
 *
 * Wraps `@conduction/nextcloud-vue`'s `createObjectStore` factory under
 * a slice-local Pinia id so the index page (`BudgetBBVMappingIndex.vue`)
 * and the future detail page (slice 07) share a single cached collection
 * + per-id object cache, plus the platform sub-resource plugins for the
 * detail page's relations + audit-trail tabs.
 *
 *   useBudgetBBVMappingStore() — registers the `budgetBBVMapping` type
 *   against the `shillinq` register + `BudgetBBVMapping` schema on first
 *   use, then proxies fetchCollection / fetchObject / create / update /
 *   delete to the shared platform store.
 *
 * The store base URL points at the OpenRegister object endpoint mounted
 * by the OR app (`/apps/openregister/api/objects`). The slice-04 Shillinq
 * page controller (`BudgetBBVMappingController`) only returns the
 * envelope (register + schema slugs) used by the manifest router; the
 * actual CRUD goes through OpenRegister so the slice-01 register
 * permissions (admin-write / authenticated-read) apply uniformly.
 *
 * Plugins:
 *   * relationsPlugin    — fetches the schema-declared
 *                          `x-openregister-relations` (programme,
 *                          account, administration) for the slice-07
 *                          detail picker.
 *   * auditTrailsPlugin  — surfaces who-changed-what for the slice-09
 *                          fiscal/audit overlay.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

import {
	auditTrailsPlugin,
	createObjectStore,
	relationsPlugin,
} from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

// Slug used by the index page when calling fetchCollection / fetchObject.
const TYPE_SLUG = 'budgetBBVMapping'
// Slugs registered with OpenRegister at install time by the slice-01
// register fragment. The schema slug is case-sensitive — it must match
// the JSON Schema title declared in
// `lib/Settings/register.d/bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed.json`.
const REGISTER_SLUG = 'shillinq'
const SCHEMA_SLUG = 'BudgetBBVMapping'

const useBaseStore = createObjectStore('budget-bbv-mapping', {
	plugins: [relationsPlugin(), auditTrailsPlugin()],
	baseUrl: generateUrl('/apps/openregister/api/objects'),
})

/**
 * Pinia composable returning the configured BudgetBBVMapping store.
 *
 * Registers the `budgetBBVMapping` object type on first call so callers
 * can simply ask the store for `fetchCollection('budgetBBVMapping')`
 * without bootstrap glue in every view.
 *
 * @return {object} The Pinia store instance.
 */
export function useBudgetBBVMappingStore() {
	const store = useBaseStore()
	if (
		typeof store.registerObjectType === 'function'
		&& !store.objectTypeRegistry[TYPE_SLUG]
	) {
		store.registerObjectType(TYPE_SLUG, SCHEMA_SLUG, REGISTER_SLUG, {
			registerSlug: REGISTER_SLUG,
			schemaSlug: SCHEMA_SLUG,
		})
	}
	return store
}

export { REGISTER_SLUG, SCHEMA_SLUG, TYPE_SLUG }
