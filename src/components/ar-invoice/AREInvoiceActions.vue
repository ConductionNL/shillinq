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
		<!-- Send by email (sales-invoice-sending REQ-SIS-001, REQ-SIS-002); the server routes by the customer's delivery method. -->
		<NcButton
			:disabled="!canMail || mailing"
			:title="canMail ? '' : t('shillinq', 'Only an issued invoice can be sent.')"
			data-testid="ar-invoice-send"
			@click="onMail('')">
			{{ mailing ? t('shillinq', 'Sending…') : t('shillinq', 'Send invoice') }}
		</NcButton>
		<NcButton
			v-if="offerEmail || fallbackNotice"
			:disabled="mailing"
			data-testid="ar-invoice-send-email"
			@click="onMail('email')">
			{{ t('shillinq', 'Send by email') }}
		</NcButton>
		<p
			v-if="!canMail"
			class="ar-einvoice-actions__notice"
			data-testid="ar-invoice-send-disabled-reason">
			{{ t('shillinq', 'Only an issued invoice can be sent.') }}
		</p>
		<p
			v-if="mailError"
			class="ar-einvoice-actions__error"
			role="alert"
			data-testid="ar-invoice-send-error">
			{{ mailError }}
		</p>
		<p
			v-if="sentLine"
			class="ar-einvoice-actions__notice"
			role="status"
			data-testid="ar-invoice-sent">
			{{ sentLine }}
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
	canSendInvoice,
	extractSendErrorMessage,
	invoiceIdOf,
	mapInvoiceSendResult,
	mapSendResult,
	resolveDeliveryStatus,
	sendEInvoiceEndpoint,
	sendingLine,
	sendInvoiceEndpoint,
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
			mailing: false,
			mailError: '',
			offerEmail: false,
			// The sending group a send just stored, before the page refetches.
			localSending: null,
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

		/** @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3 */
		canMail() {
			return canSendInvoice(this.object)
		},

		/** @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3 */
		sentLine() {
			return sendingLine(this.localSending || this.object?.sending, this.t)
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

		/**
		 * Send the invoice by the customer's delivery method, or by email.
		 *
		 * @param {string} via '' for the customer's method, 'email' for the mail.
		 * @return {Promise<void>}
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.3
		 */
		async onMail(via) {
			this.mailError = ''
			this.mailing = true
			try {
				const response = await axios.post(
					generateUrl(sendInvoiceEndpoint(invoiceIdOf(this.object))),
					{ administrationId: this.administrationId, via },
				)
				const mapped = mapInvoiceSendResult(response.data || {})
				this.offerEmail = mapped.offerEmail
				this.mailError = mapped.error
				if (mapped.sending) {
					this.localSending = mapped.sending
					this.fallbackNotice = ''
				}
				if (response.data?.status === 'sent') {
					showSuccess(this.t('shillinq', 'The invoice is sent.'))
				}
			} catch (e) {
				this.mailError = e?.response?.data?.error || this.t('shillinq', 'The invoice could not be sent.')
			} finally {
				this.mailing = false
			}
		},
	},
}
</script>

<style scoped>
.ar-einvoice-actions {
	display: flex;
	flex-wrap: wrap;
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
