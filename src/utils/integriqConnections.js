// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The External Connections page's Add integration header action
// (adopt-connection-registry).

import { generateUrl } from '@nextcloud/router'

/**
 * Where Add integration lands: integriq's overview, preset and linking.
 *
 * The route is the one hydra connection-registry design D9 names.
 *
 * @type {string}
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=shillinq&link=1'

/**
 * Open integriq's Connections overview on the link-a-source dialog.
 *
 * A connection row is integriq's, and a source is linked to it there. `link=1`
 * opens the dialog, filtered to shillinq's connections. This is a function
 * handler because a header action's `navigate` keyword only pushes a route
 * name inside shillinq's router, which cannot leave the app.
 *
 * @return {void}
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */
export function openIntegriqConnections() {
	window.location.assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
}

/**
 * Where Open in Integriq lands: integriq's catalogue (its Store page).
 *
 * Integriq's former `/catalog` route redirects here.
 *
 * @type {string}
 */
export const INTEGRIQ_STORE_PATH = '/apps/integriq/store'

/**
 * Open integriq's catalogue searched on one catalogue item's name.
 *
 * The Integrations page's row action (platform-integration-catalogue). An item
 * is switched on in integriq, under its own authorization (ADR-023), so this
 * leaves shillinq. The index page calls a row handler with
 * `{ actionId, item }`; a bare row is accepted too. Without a name the
 * catalogue opens unsearched.
 *
 * @param {object} [payload] The dispatch payload `{ item }`, or the row itself.
 * @return {void}
 * @spec openspec/changes/platform-integration-catalogue/specs/integration-catalogue/spec.md
 */
export function openIntegriqStore(payload = {}) {
	const row = (payload && typeof payload.item === 'object' && payload.item !== null) ? payload.item : (payload || {})
	const name = typeof row.name === 'string' ? row.name.trim() : ''
	const url = generateUrl(INTEGRIQ_STORE_PATH)
	window.location.assign(name === '' ? url : `${url}?_search=${encodeURIComponent(name)}`)
}
