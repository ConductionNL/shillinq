// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// banking-manual-match: the call behind the unmatched items bulk actions.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Classify selected ReconciliationMatch rows in one call.
 *
 * The server reads each match's reconciliation and checks its administration,
 * so a selection may span several reconciliations.
 *
 * @param {Array<string>} matchIds The selected match ids.
 * @param {string} resolutionStatus `timing`, `pending` or `adjustment`.
 * @param {string} resolutionReason The shared reason.
 * @return {Promise<{applied: number, failed: number}>} Totals.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export async function classifyUnmatchedItems(
	matchIds,
	resolutionStatus,
	resolutionReason,
) {
	const response = await axios.post(
		generateUrl('/apps/shillinq/api/reconciliation-matches/bulk-resolve'),
		{ matchIds, resolutionStatus, resolutionReason },
	)
	return {
		applied: Number(response.data?.applied ?? 0),
		failed: Object.keys(response.data?.failed ?? {}).length,
	}
}
