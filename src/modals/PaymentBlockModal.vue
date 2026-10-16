<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PaymentBlockModal: block or release payment of one supplier invoice or one
 supplier (banking-payment-run REQ-BPR-003, REQ-BPR-004).

 Blocking asks a reason; releasing asks a confirmation. Either way the dialog
 patches paymentBlocked and paymentBlockReason on the object through
 OpenRegister, so the object's audit trail names who did it and when. The
 lifecycle state is left alone: an overdue invoice stays overdue.

 Launched from the APTransactionDetail and PayeeDetail header actions (type
 open-modal, ADR-049). The modal is hosted outside the page's object
 context, so the id falls back to the route's :id, as PaymentRunReconcileModal
 does. Its own file for hydra gate-13.

 @spec openspec/changes/archive/2026-09-29-banking-payment-run/specs/payment-control-guards/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="
			mode === 'release'
				? t('shillinq', 'Release payment')
				: t('shillinq', 'Block payment')
		"
		size="small"
		data-testid="payment-block-modal"
		@closing="onClose">
		<div class="pbm">
			<p v-if="mode === 'release'">
				{{
					t(
						'shillinq',
						'Payment runs can pay this again from the next proposal on.',
					)
				}}
			</p>
			<template v-else>
				<p>
					{{
						t(
							'shillinq',
							'No payment run pays this while the block is on. Bookkeeping goes on as usual.',
						)
					}}
				</p>
				<NcTextField
					v-model="reason"
					:label="t('shillinq', 'Reason')"
					data-testid="payment-block-reason" />
			</template>
			<p
				v-if="error"
				class="pbm__error"
				role="alert"
				data-testid="payment-block-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting"
				data-testid="payment-block-submit"
				@click="submit">
				{{
					mode === 'release'
						? t('shillinq', 'Release payment')
						: t('shillinq', 'Block payment')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { errorMessage } from '../utils/downPaymentApi.js'
import {
	blockPayload,
	releasePayload,
	setPaymentBlock,
} from '../utils/paymentRunApi.js'

export default {
	name: 'PaymentBlockModal',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// APTransaction or Payee.
		schema: {
			type: String,
			required: true,
		},

		// block or release.
		mode: {
			type: String,
			default: 'block',
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			reason: '',
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The object's id: the prop, else the route's :id.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-2.2
		 */
		effectiveId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	methods: {
		t,

		/**
		 * Save the block or the release.
		 *
		 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-2.2
		 */
		async submit() {
			this.error = ''
			let payload
			try {
				payload =
					this.mode === 'release'
						? releasePayload()
						: blockPayload(this.reason)
			} catch (error) {
				this.error = error.message
				return
			}
			this.submitting = true
			try {
				await setPaymentBlock(this.schema, this.effectiveId, payload)
				showSuccess(
					this.mode === 'release'
						? t('shillinq', 'Payment released')
						: t('shillinq', 'Payment blocked'),
				)
				emit('cn:widget:refresh', {
					widget:
						this.schema === 'Payee'
							? 'PayeeDetail'
							: 'APTransactionDetail',
				})
				this.$emit('close', payload)
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The payment block could not be saved.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without saving.
		 *
		 * @spec openspec/changes/archive/2026-09-29-banking-payment-run/tasks.md#task-2.2
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.pbm {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pbm__error {
	color: var(--color-error-text);
}
</style>
