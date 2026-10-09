// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// assets-method-change-and-reserve: the fixed asset page's "Post missed
// depreciation" header action. Registered in src/manifestActions.js, which
// puts it where a detail page dispatches it (manifest.actions).

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import MissedDepreciationDialog from '../modals/MissedDepreciationDialog.vue'

/**
 * Header action: list the asset's missed months and post them on request.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<void>|boolean} The dialog, or false without an asset.
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
export function openMissedDepreciation(scope) {
	const assetId =
		(scope && scope.item && scope.item.id)
		|| window.location.pathname.split('/').pop()
	if (!assetId) {
		return false
	}
	return spawnDialog(MissedDepreciationDialog, { open: true, assetId })
}
