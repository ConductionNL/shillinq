/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The administration context the pages read: the request, how an
 * administration is named (name, else code, else id), the select options
 * built from the list.
 *
 * @spec openspec/specs/administration-context/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Read the caller's administration context.
 *
 * @return {Promise<object|undefined>} The response body.
 */
export async function fetchAdministrationContext() {
	const response = await axios.get(
		generateUrl('/apps/shillinq/api/administrations/context'),
	)
	return response.data
}

/**
 * How an administration is named: its name, else its code, else its id.
 *
 * @param {object} administration One administration.
 * @return {string}
 */
export function administrationLabel(administration) {
	return administration.name || administration.administrationCode || administration.administrationId
}

/**
 * The select options of the context's administrations.
 *
 * @param {object|undefined} context The context body.
 * @return {Array<{value: string, label: string}>}
 */
export function administrationOptions(context) {
	return (context?.administrations || []).map((administration) => ({
		value: administration.administrationId,
		label: administrationLabel(administration),
	}))
}
