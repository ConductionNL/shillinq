// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Client for the accountant-portal API (AccountantPortalController). Both
// endpoints are #[NoAdminRequired] but scoped server-side to the caller's
// AdministrationMembership records — a non-granted administration is masked
// as a 404 (never 403), so the caller never needs to know which client
// administrations exist beyond what the dashboard already returned.
//
// @spec openspec/specs/accountant-portal/spec.md

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch the authenticated user's accountant dashboard: every accessible client
 * administration with its period-close state, BTW filing status + deadline,
 * missing-document count and open/attention items.
 *
 * @return {Promise<{userId: ?string, administrations: Array<object>}>}
 */
export async function fetchAccountantDashboard() {
	const url = generateUrl('/apps/shillinq/api/accountant/dashboard')
	const response = await axios.get(url)
	return response.data
}

/**
 * Open the handover-pack ZIP download for one client administration (journal
 * export, BTW-overzicht, trial balance, XAF auditfile). The server masks a
 * non-granted administration as a 404 — the browser download simply fails in
 * that case, it never confirms the administration exists.
 *
 * @param {string} administrationId - The client administration id.
 * @param {string} [period] - Reporting period passed to each generator (defaults to the current year server-side).
 * @return {void}
 */
export function downloadHandoverPack(administrationId, period) {
	let url = generateUrl(
		`/apps/shillinq/api/accountant/administrations/${encodeURIComponent(administrationId)}/handover-pack`,
	)
	if (period) {
		url += `?period=${encodeURIComponent(period)}`
	}

	if (typeof window !== 'undefined' && typeof window.open === 'function') {
		window.open(url, '_blank', 'noopener')
	}
}
