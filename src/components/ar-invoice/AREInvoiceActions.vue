<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AR Invoice E-Invoice Actions

 `actionsComponent` for the manifest-driven ARInvoiceDetail page (REQ-EINV-007):
 renders the delivery-status indicator + the "Send e-invoice" action in the
 page header's actions slot. Mounted by CnPageRenderer/CnDetailPage, which
 passes `{ object, objectId, schema, objectType, store }` — `object` is
 already the resolved ARInvoice record, so no extra fetch is needed.

 The Send action is enabled only for `lifecycleState: issued` (server
 re-validates everything — this only governs the button's disabled state,
 never permission/business-rule enforcement, ADR-005). Validation failures
 from the server are shown inline before any event is emitted; a successful
 send shows a toast and updates the status chip in place.

 @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
-->
<template>
	<div class="ar-einvoice-actions" data-testid="ar-einvoice-actions">
		<DeliveryStatusChip :status="deliveryStatus" />
		<NcButton
			variant="primary"
			:disabled="!canSend || sending"
			data-testid="ar-einvoice-send"
			@click="onSend">
			{{
				sending ? t('shillinq', 'Sending…') : t('shillinq', 'Send e-invoice')
			}}
		</NcButton>
		<p
			v-if="sendError"
			class="ar-einvoice-actions__error"
			data-testid="ar-einvoice-send-error">
			{{ sendError }}
		</p>
		<p
			v-if="fallbackNotice"
			class="ar-einvoice-actions__notice"
			data-testid="ar-einvoice-fallback-notice">
			{{ fallbackNotice }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import DeliveryStatusChip from '../DeliveryStatusChip.vue'
import {
	canSendEInvoice,
	extractSendErrorMessage,
	mapSendResult,
	resolveDeliveryStatus,
	sendEInvoiceEndpoint,
} from './arEInvoiceActions.js'

export default {
	name: 'AREInvoiceActions',
	components: {
		NcButton,
		DeliveryStatusChip,
	},

	props: {
		/**
		 * The resolved ARInvoice record (bound by CnDetailPage's actions slot).
		 */
		object: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			sending: false,
			sendError: '',
			fallbackNotice: '',
			// Local override so the chip updates immediately on a successful
			// send without waiting for the parent's object store to refetch.
			localDeliveryStatus: null,
		}
	},

	computed: {
		/** @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md */
		deliveryStatus() {
			return resolveDeliveryStatus(this.object, this.localDeliveryStatus)
		},

		/** @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md */
		canSend() {
			return canSendEInvoice(this.object)
		},

		/** @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md */
		administrationId() {
			return this.object?.administrationId || ''
		},

		/** @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md */
		invoiceNumber() {
			return this.object?.invoiceNumber || ''
		},
	},

	methods: {
		/** @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md */
		async onSend() {
			this.sendError = ''
			this.fallbackNotice = ''
			this.sending = true
			try {
				const response = await axios.post(
					generateUrl(sendEInvoiceEndpoint(this.invoiceNumber)),
					{ administrationId: this.administrationId },
				)
				const { deliveryStatus, fallbackNotice } = mapSendResult(
					response.data || {},
					this.t,
				)
				this.localDeliveryStatus = deliveryStatus
				this.fallbackNotice = fallbackNotice
				if (fallbackNotice === '') {
					showSuccess(
						this.t('shillinq', 'Invoice queued for Peppol delivery.'),
					)
				}
			} catch (e) {
				this.sendError = extractSendErrorMessage(e, this.t)
			} finally {
				this.sending = false
			}
		},
	},
}
</script>

<style scoped>
.ar-einvoice-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ar-einvoice-actions__error {
	color: var(--color-error, #c33);
	font-size: 0.85em;
	margin: 0;
}

.ar-einvoice-actions__notice {
	color: var(--color-text-maxcontrast, #666);
	font-size: 0.85em;
	margin: 0;
}
</style>
