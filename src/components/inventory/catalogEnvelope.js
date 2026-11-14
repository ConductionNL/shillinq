/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The inventory catalog endpoints answer with an envelope: the rows, which
 * side answered and whether that side is authoritative. These helpers fetch
 * and unpack it the same way for every catalog view.
 *
 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Fetch a catalog envelope.
 *
 * @param {string} path The app-relative endpoint.
 * @return {Promise<object>} The envelope, an empty object when the body is empty.
 */
export async function fetchEnvelope(path) {
	const response = await axios.get(generateUrl(path))
	return response.data || {}
}

/**
 * The rows under a key of an envelope, an empty list when they are not a list.
 *
 * @param {object} envelope The envelope.
 * @param {string} key The rows' key.
 * @return {Array<object>} The rows.
 */
export function rowsOf(envelope, key) {
	return Array.isArray(envelope[key]) ? envelope[key] : []
}

/**
 * Whether a failure is the 403 that means the caller holds no administration:
 * an answer, not a broken endpoint.
 *
 * @param {Error} error The failure.
 * @return {boolean} True for the no-administration answer.
 */
export function isNoAdministration(error) {
	return error?.response?.status === 403
}
