<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Delivery Status Chip

 Presentational status indicator for an ARInvoice's Peppol delivery status
 (REQ-EINV-007 / REQ-AR-011). Status is conveyed by a human-readable text
 label, never colour alone (WCAG 2.1 AA) — the colour class is purely a
 secondary visual cue layered on top of the label.

 @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
-->
<template>
	<span
		class="delivery-status-chip"
		:class="`delivery-status-chip--${status}`"
		data-testid="delivery-status-chip">
		{{ label }}
	</span>
</template>

<script>
export default {
	name: 'DeliveryStatusChip',
	props: {
		/**
		 * One of not-sent | queued | sent | delivered | rejected | failed.
		 */
		status: {
			type: String,
			default: 'not-sent',
		},
	},

	computed: {
		/** @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md */
		label() {
			const labels = {
				'not-sent': this.t('shillinq', 'Not sent'),
				queued: this.t('shillinq', 'Queued'),
				sent: this.t('shillinq', 'Sent'),
				delivered: this.t('shillinq', 'Delivered'),
				rejected: this.t('shillinq', 'Rejected'),
				failed: this.t('shillinq', 'Failed'),
			}
			return labels[this.status] || this.status
		},
	},
}
</script>

<style scoped>
.delivery-status-chip {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
	background: var(--color-background-dark, #ddd);
	color: var(--color-main-text, #222);
	border: 1px solid var(--color-border-dark, #ccc);
}

.delivery-status-chip--queued,
.delivery-status-chip--sent {
	background: var(--color-warning, #c93);
	color: #fff;
	border-color: transparent;
}

.delivery-status-chip--delivered {
	background: var(--color-success, #2c2);
	color: #fff;
	border-color: transparent;
}

.delivery-status-chip--rejected,
.delivery-status-chip--failed {
	background: var(--color-error, #c33);
	color: #fff;
	border-color: transparent;
}
</style>
