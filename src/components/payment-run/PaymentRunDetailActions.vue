<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PaymentRunDetailActions — payment-run-sepa-export (REQ-SEPA-006 / REQ-SEPA-007).

 The actions component injected into the PaymentRun detail page's #actions slot
 (wired as the page's actionsComponent in the manifest). It receives the current
 PaymentRun via the slot-scoped `object` prop (loadState / store-driven, never a
 DOM data-attribute — hydra initial-state gate).

 - "Export to bank" (visible when the run is approved): POSTs to
   /api/v1/payment-runs/{id}/export, which generates the SEPA pain.001 + CSV
   bank file, stores + tags it, sets exportedFileRef / exportedAt and drives
   approved → exported. On success the detail object refreshes to reflect the
   exported state + stored file reference.
 - "Reconcile / import statement" (visible when the run is exported): launches
   the PaymentRunReconcileModal to upload a CAMT.053 statement.

 The reconcile dialog lives in its own file under src/modals/ (hydra gate-13
 modal isolation) and is hosted here because this component owns the launch
 button.

 @spec openspec/changes/payment-run-sepa-export/specs/payment-run-sepa-export/spec.md
-->

<template>
	<div class="payment-run-detail-actions">
		<NcButton
			v-if="isApproved"
			type="primary"
			:disabled="exporting"
			data-testid="payment-run-export"
			@click="exportToBank">
			<template #icon>
				<BankOutline :size="20" />
			</template>
			{{ t('shillinq', 'Export to bank') }}
		</NcButton>

		<NcButton
			v-if="isExported"
			data-testid="payment-run-reconcile-open"
			@click="showReconcileModal = true">
			<template #icon>
				<BankCheck :size="20" />
			</template>
			{{ t('shillinq', 'Reconcile / import statement') }}
		</NcButton>

		<PaymentRunReconcileModal
			:open="showReconcileModal"
			:payment-run-id="runId"
			@close="showReconcileModal = false"
			@reconciled="onReconciled" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import BankOutline from 'vue-material-design-icons/BankOutline.vue'
import BankCheck from 'vue-material-design-icons/BankCheck.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import PaymentRunReconcileModal from '../../modals/PaymentRunReconcileModal.vue'

export default {
	name: 'PaymentRunDetailActions',
	components: {
		NcButton,
		BankOutline,
		BankCheck,
		PaymentRunReconcileModal,
	},
	props: {
		// Slot-scoped PaymentRun from the detail page (#actions="{ object }").
		object: {
			type: Object,
			default: () => ({}),
		},
	},
	data() {
		return {
			exporting: false,
			showReconcileModal: false,
		}
	},
	computed: {
		runId() {
			return String((this.object && (this.object.id || (this.object['@self'] && this.object['@self'].id))) || '')
		},
		state() {
			return String((this.object && (this.object.lifecycleState || this.object.status)) || '')
		},
		isApproved() {
			return this.state === 'approved'
		},
		isExported() {
			return this.state === 'exported'
		},
	},
	methods: {
		t,
		async exportToBank() {
			if (!this.runId || this.exporting) {
				return
			}
			this.exporting = true
			try {
				await axios.post(
					generateUrl(`/apps/shillinq/api/v1/payment-runs/${this.runId}/export`),
				)
				showSuccess(t('shillinq', 'Payment run exported to bank file.'))
				emit('cn:widget:refresh', { widget: 'PaymentRunDetail' })
			} catch (error) {
				const message = error.response && error.response.data && error.response.data.error
					? error.response.data.error
					: t('shillinq', 'Could not export the payment run.')
				showError(message)
			} finally {
				this.exporting = false
			}
		},
		onReconciled() {
			this.showReconcileModal = false
			emit('cn:widget:refresh', { widget: 'PaymentRunDetail' })
		},
	},
}
</script>

<style scoped>
.payment-run-detail-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
