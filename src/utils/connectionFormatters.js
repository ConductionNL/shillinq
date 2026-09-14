// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Column formatters for the External Connections page (adopt-connection-registry).
//
// The rows are integriq's `app_connection` objects. The names and labels are
// the ones hydra's connection-registry contract gives them (design D8), so
// every app adopting the registry renders a status the same way. Each adopting
// app carries this local copy until @conduction/nextcloud-vue ships them as
// built-ins. nextcloud-vue#1163 added them after 2.53.1, the latest release,
// and shillinq pins ^2.39.0, so the copy stays.
//
// `limited` came with hydra#673: the connection works in part, such as a
// preview API that serves some calls and refuses others.

import { translate as t } from '@nextcloud/l10n'

/**
 * English source label per status value, translated on each call.
 *
 * @type {Record<string, string>}
 */
export const CONNECTION_STATUS_LABELS = {
	configured: 'Configured',
	limited: 'Limited',
	unconfigured: 'Not configured',
	simulated: 'Simulated',
	unavailable: 'Not available',
	error: 'Error',
}

/**
 * The label for a connection status.
 *
 * An unknown value renders itself rather than an empty cell: a status the app
 * cannot name is still a status the admin should see.
 *
 * @param {string} value The `status` enum value.
 * @return {string} The label, or the raw value when it is not one of the six.
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */
export function connectionStatus(value) {
	const source = CONNECTION_STATUS_LABELS[value]
	return source ? t('shillinq', source) : String(value ?? '')
}

/**
 * The Open settings link text, or '' when the row has nowhere to send a reader.
 *
 * Empty text makes the link cell fall through to plain text, so a row without
 * a settings page offers nothing to click.
 *
 * @param {string} value The row's `settingsUrl`.
 * @return {string} The link text, or ''.
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */
export function connectionSettingsLabel(value) {
	return typeof value === 'string' && value.length > 0
		? t('shillinq', 'Open settings')
		: ''
}

export default {
	connectionStatus,
	connectionSettingsLabel,
}
