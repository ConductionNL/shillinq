/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Minimal @nextcloud/router stub for the offline Vitest suite.
 */

export function generateUrl(url, params) {
	let path = url
	// Substitute {param} placeholders like the real @nextcloud/router does, so
	// callers passing a params map (e.g. the W8 external-adapter detail page's
	// `/api/admin/external-adapters/{id}`) resolve to a concrete path. Callers
	// that pass no params (the settings store) are unaffected.
	if (params && typeof params === 'object') {
		for (const [key, value] of Object.entries(params)) {
			path = path.replace(
				new RegExp(`\\{${key}\\}`, 'g'),
				encodeURIComponent(String(value)),
			)
		}
	}
	return `/index.php${path.startsWith('/') ? path : `/${path}`}`
}

export function generateRemoteUrl(service) {
	return `http://localhost/remote.php/${service}`
}
