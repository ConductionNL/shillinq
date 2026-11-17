// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// platform-custom-record-types: the shown own record types, their cards, and
// the router guard that keeps the generic `/records/:schema` pages to them.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The pages that take their schema from the route and must be guarded. */
export const GUARDED_ROUTES = ['CustomRecords', 'CustomRecordDetail']

/**
 * Read the shown own record types. A failed read counts as none shown.
 *
 * @return {Promise<Array<{slug: string, title: string, description: string, icon: ?string}>>} The shown types.
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
 */
export async function fetchShownTypes() {
	try {
		const { data } = await axios.get(generateUrl('/apps/shillinq/api/custom-record-types'))
		return Array.isArray(data?.types) ? data.types : []
	} catch {
		return []
	}
}

/**
 * Whether a slug is among the shown types, ignoring case.
 *
 * @param {Array<{slug: string}>} types The shown types.
 * @param {string} slug The slug from the route.
 * @return {boolean} Whether it is shown.
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
 */
export function isShown(types, slug) {
	if (typeof slug !== 'string' || slug === '') {
		return false
	}
	return types.some((type) => String(type.slug).toLowerCase() === slug.toLowerCase())
}

/**
 * One card section for the own records page, a card per shown type opening
 * its list page.
 *
 * @param {Array<{slug: string, title: string, description: string}>} types The shown types.
 * @param {string} label The section label, already translated.
 * @return {Array<object>} The ClusterOverview sections; empty when no type is shown.
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
 */
export function cardSections(types, label) {
	if (types.length === 0) {
		return []
	}
	return [
		{
			id: 'own-record-types',
			label,
			cards: types.map((type) => ({
				id: type.slug,
				label: type.title || type.slug,
				description: type.description || '',
				route: 'CustomRecords',
				params: { schema: type.slug },
			})),
		},
	]
}

/**
 * A vue-router guard: a typed URL for a record type that is not shown goes
 * to the own records page, which says the type is not available. Every
 * other route passes untouched, without a request.
 *
 * @param {function(): Promise<Array<{slug: string}>>} fetchTypes Reads the shown types.
 * @return {function(object): Promise<(boolean|object)>} The `beforeEach` guard.
 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
 */
export function customRecordGuard(fetchTypes = fetchShownTypes) {
	return async (to) => {
		if (!GUARDED_ROUTES.includes(to.name)) {
			return true
		}
		const schema = to.params?.schema
		if (isShown(await fetchTypes(), schema)) {
			return true
		}
		return { name: 'CustomRecordTypes', query: { unavailable: String(schema ?? '') } }
	}
}
