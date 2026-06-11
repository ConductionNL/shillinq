/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Minimal @nextcloud/router stub for the offline Vitest suite.
 */

export function generateUrl(url) {
	return `/index.php${url.startsWith('/') ? url : `/${url}`}`
}

export function generateRemoteUrl(service) {
	return `http://localhost/remote.php/${service}`
}
