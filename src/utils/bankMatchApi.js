// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// banking-manual-match: the calls behind the unmatched items bulk actions.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

/**
 * Classify selected ReconciliationMatch rows, one bulk call per reconciliation.
 *
 * The endpoint is scoped to one reconciliation, and a selection on the
 * unmatched items page can span several, so the matches are read first and
 * grouped by their `reconId`.
 *
 * @param {Array<string>} matchIds The selected match ids.
 * @param {string} resolutionStatus `timing`, `pending` or `adjustment`.
 * @param {string} resolutionReason The shared reason.
 * @return {Promise<{applied: number, failed: number}>} Totals over all groups.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export async function classifyUnmatchedItems(
	matchIds,
	resolutionStatus,
	resolutionReason,
) {
	const groups = {}
	let failed = 0
	for (const id of matchIds) {
		try {
			const response = await axios.get(
				generateUrl(
					`/apps/openregister/api/objects/${REGISTER_SLUG}/ReconciliationMatch/${encodeURIComponent(id)}`,
				),
			)
			const reconId = String(response.data?.reconId || '')
			if (reconId === '') {
				failed++
				continue
			}
			groups[reconId] = [...(groups[reconId] || []), id]
		} catch {
			failed++
		}
	}

	let applied = 0
	for (const [reconId, ids] of Object.entries(groups)) {
		const response = await axios.post(
			generateUrl(
				`/apps/shillinq/api/reconciliations/${encodeURIComponent(reconId)}/matches/bulk-resolve`,
			),
			{ matchIds: ids, resolutionStatus, resolutionReason },
		)
		applied += Number(response.data?.applied ?? 0)
		failed += Object.keys(response.data?.failed ?? {}).length
	}

	return { applied, failed }
}
