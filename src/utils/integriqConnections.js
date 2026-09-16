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
