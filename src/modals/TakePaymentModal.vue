<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 TakePaymentModal: take an open invoice's payment on the spot, on the user's
 registered phone (receivables-provider-payouts REQ-RPPO-005).

 Opened from the "Take payment now" header action on ARInvoiceDetail. The
 server asks the provider, through integriq, to push the open amount to the
 user's payment device; when the provider cannot, it answers a checkout, and
 this dialog shows it as a QR code for the customer to scan. The capture
 settles the invoice like any provider payment. Its own file for hydra
 gate-13.

 @spec openspec/changes/receivables-provider-payouts/specs/ar-invoice-payment-links/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Take payment now')"
		size="normal"
		data-testid="take-payment-modal"
		@closing="onClose">
		<div class="tpm">
			<p v-if="!result" class="tpm__muted">
				{{
					t(
						'shillinq',
						'The open amount of this invoice goes to the payment app on your registered phone. The customer pays there.',
					)
				}}
			</p>

			<p v-else-if="result.mode === 'device'" data-testid="take-payment-on-device">
				{{
					t(
						'shillinq',
						'The payment is on your phone. Once the customer has paid, this invoice shows as paid.',
					)
				}}
			</p>

			<template v-else-if="result.mode === 'qr'">
				<p data-testid="take-payment-qr-intro">
					{{
						t(
							'shillinq',
							'Your phone cannot take this payment. Let the customer scan this code to pay.',
						)
					}}
				</p>
				<img
					v-if="qrImage"
					class="tpm__qr"
					:src="qrImage"
					:alt="t('shillinq', 'QR code of the payment page')"
					data-testid="take-payment-qr">
				<a
					:href="result.checkoutUrl"
					target="_blank"
					rel="noopener noreferrer"
					data-testid="take-payment-checkout-link">
					{{ t('shillinq', 'Open the payment page') }}
				</a>
			</template>

			<p
				v-if="error"
				class="tpm__error"
				role="alert"
				data-testid="take-payment-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton data-testid="take-payment-close" @click="onClose">
				{{ result ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="submitting"
				data-testid="take-payment-start"
				@click="start">
				{{ t('shillinq', 'Take payment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { checkoutQrImage, refusalMessage, takePayment } from '../utils/pointOfSaleApi.js'

export default {
	name: 'TakePaymentModal',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			result: null,
			qrImage: '',
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The invoice: the prop, else the route's id.
		 *
		 * @return {string}
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
		 */
		invoiceId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	methods: {
		t,

		/**
		 * Ask for the payment on the phone; show the QR code when the server answers a checkout.
		 *
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
		 */
		async start() {
			this.error = ''
			this.submitting = true
			try {
				const result = await takePayment(this.invoiceId)
				if (result.mode === 'qr') {
					this.qrImage = await checkoutQrImage(result.checkoutUrl)
				}
				this.result = result
			} catch (error) {
				this.error = refusalMessage(
					error,
					t('shillinq', 'The payment could not be started.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close, and refresh the invoice page once a payment was started.
		 *
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.1
		 */
		onClose() {
			if (this.result) {
				emit('cn:page:refresh', {})
			}
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.tpm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	align-items: flex-start;
}

.tpm__qr {
	width: 240px;
	height: 240px;
	background-color: var(--color-main-background);
}

.tpm__muted {
	color: var(--color-text-maxcontrast);
}

.tpm__error {
	color: var(--color-error-text);
}
</style>
