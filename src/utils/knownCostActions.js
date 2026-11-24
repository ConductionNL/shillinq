// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// budget-known-costs design.md §12: the "Regenerate known costs" header
// action on Budget Line Derivations. CnIndexPage resolves it through
// `customComponents`; it is in src/manifestActions.js, which feeds both
// handler maps.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import RegenerateKnownCostsModal from '../modals/RegenerateKnownCostsModal.vue'

/**
 * Open the regeneration dialog.
 *
 * @return {object} The spawned dialog.
 * @spec openspec/changes/archive/2026-11-24-budget-known-costs/design.md
 */
export function openRegenerateKnownCosts() {
	return spawnDialog(RegenerateKnownCostsModal, {})
}
