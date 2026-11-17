// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// bookings-messages-and-embedding 1.4: the named handler for the "New
// repeating appointment" header action on Appointments. CnIndexPage resolves
// a header handler against `customComponents` (src/main.js) and calls it; it
// opens the dialog with spawnDialog.

import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import RepeatAppointmentModal from '../modals/RepeatAppointmentModal.vue'

/**
 * Header action: open the repeating appointment dialog.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/changes/bookings-messages-and-embedding/tasks.md#task-1.4
 */
export function openRepeatAppointment() {
	return spawnDialog(RepeatAppointmentModal, {})
}
