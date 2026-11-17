// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-investment-deduction-claims 2.1: the "Recalculate" header action on
// Investment deductions. CnIndexPage resolves it through `customComponents`;
// it is in src/manifestActions.js, which feeds both handler maps.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import RecalculateInvestmentDeductionsModal from '../modals/RecalculateInvestmentDeductionsModal.vue'

/**
 * Header action: open the Recalculate dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/tax-investment-deduction-claims/tasks.md#task-2.1
 */
export function openRecalculateInvestmentDeductions() {
	return spawnDialog(RecalculateInvestmentDeductionsModal, {})
}
