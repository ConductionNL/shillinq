// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Client for the multi-administratie context + switcher API
// (AdministrationController). All endpoints are #[NoAdminRequired] but
// scoped to the user's AdministrationMembership records server-side; the
// caller never needs to know which administraties exist, only what to
// display (REQ-MA-001, REQ-MA-003).
//
// @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-13

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch the authenticated user's administration context.
 *
 * Returns the list of accessible administraties (with code, name, role,
 * posting/closing rights) plus the active administrationId. The server masks
 * non-membership as a 404 in callers, but for this read endpoint a logged-in
 * user always receives at least an empty list.
 *
 * @return {Promise<{userId: ?string, administrations: Array<object>, activeAdministrationId: ?string}>}
 */
export async function fetchAdministrationContext() {
	const url = generateUrl('/apps/shillinq/api/administrations/context')
	const response = await axios.get(url)
	return response.data
}

/**
 * Switch the session's active administration.
 *
 * Server validates the user has a valid AdministrationMembership for the
 * target id; a non-membership is masked as a 404 (never 403). The caller
 * MUST treat 404 as "administration not accessible" without exposing the
 * existence of other tenants' data.
 *
 * @param {string} administrationId The target administration id.
 * @return {Promise<{activeAdministrationId: string}>}
 */
export async function switchAdministration(administrationId) {
	const url = generateUrl('/apps/shillinq/api/administrations/switch')
	const response = await axios.post(url, { administrationId })
	return response.data
}
