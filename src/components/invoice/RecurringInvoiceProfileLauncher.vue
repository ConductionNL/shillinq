<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RecurringInvoiceProfileLauncher — the missing launcher for
 RecurringInvoiceProfileModal (recurring-invoicing REQ-RIN-008).

 WHY THIS FILE EXISTS.
 `src/manifest.d/recurring-invoicing.json` declared the create affordance as a
 `config.headerActions[]` entry on the `RecurringInvoiceProfiles` INDEX page,
 and its own `_note_open_modal_gap` recorded — correctly — that this could
 never work:

   - CnActionsBar renders a manifest `headerActions[]` entry as an
     `NcActionButton` INSIDE the collapsed ⋯ overflow menu, so the control was
     not visible on the page at all until the menu was opened; and
   - clicking it only makes CnIndexPage `$emit('header-action', …)`, an event
     CnPageRenderer does not listen for. There is no `open-modal` handler
     keyword on CnIndexPage (the `type:"open-modal"` dispatcher vocabulary
     works on CnDashboardPage and CnDetailPage only).

 So `RecurringInvoiceProfileModal` — fully implemented, registered
 `kind:"modal"`, carrying every field the spec asks for — was unreachable
 through the UI, and `tests/e2e/recurring-invoicing.spec.ts` was RED on
 purpose.

 This closes the gap using vocabulary the library DOES implement: the page
 declares a `widgets[]` entry in CnPageRenderer's `header-actions` slot whose
 `widgetKey` names this component (CnWidgetGrid resolves a widgetKey against
 the CnAppRoot registry before the built-in widget table). The component owns
 both halves of the interaction — a visible primary button and the modal it
 opens — so no cross-component event plumbing is needed and the modal stays
 modal-isolated in `src/modals/` (hydra gate-13).

 It deliberately does NOT re-implement any of the form: it mounts the existing
 modal and re-fetches the index list after a save by broadcasting the
 library's own widget-refresh channel.

 @spec openspec/specs/recurring-invoicing/spec.md
-->

<template>
	<div class="recurring-profile-launcher">
		<NcButton
			variant="primary"
			data-testid="recurring-profile-new"
			@click="open = true">
			<template #icon>
				<CalendarSync :size="20" />
			</template>
			{{ t('shillinq', 'New recurring profile') }}
		</NcButton>

		<RecurringInvoiceProfileModal
			:open="open"
			@close="open = false"
			@saved="onSaved" />
	</div>
</template>

<script>
import { emit as emitBus } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import CalendarSync from 'vue-material-design-icons/CalendarSync.vue'
import RecurringInvoiceProfileModal from '../../modals/RecurringInvoiceProfileModal.vue'

export default {
	name: 'RecurringInvoiceProfileLauncher',

	components: {
		NcButton,
		CalendarSync,
		RecurringInvoiceProfileModal,
	},

	data() {
		return {
			open: false,
		}
	},

	methods: {
		/** @spec exclude Re-export of the l10n `translate` helper for the template; no behaviour of its own. */
		t,

		/**
		 * Close the modal and ask the surrounding widgets to re-fetch, so the
		 * newly created profile appears in the index without a page reload.
		 * `cn:widget:refresh` is the library's own refresh channel
		 * (CnWidgetWrapper / CnChartWidget subscribe to it).
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/recurring-invoicing/spec.md
		 */
		onSaved() {
			this.open = false
			emitBus('cn:widget:refresh', {})
		},
	},
}
</script>

<style scoped>
.recurring-profile-launcher {
	display: flex;
	justify-content: flex-end;
	margin-bottom: var(--default-grid-baseline, 8px);
}
</style>
