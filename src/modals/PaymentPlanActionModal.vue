<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 PaymentPlanActionModal: activate a drafted payment plan, record a payment on
 it by hand, or cancel it (receivables-payment-plans REQ-RPPL-001,
 REQ-RPPL-003). Each goes to PaymentPlanController, never straight to the
 lifecycle, because activating pauses dunning and mails the customer, a
 payment is allocated to the instalments and invoices, and cancelling resumes
 the pauses.

 Launched from the PaymentPlanDetail header actions (type open-modal); the
 plan id falls back to the route's :id. Its own file for hydra gate-13.

 @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/specs/bookkeeping-credit-control-dunning/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="title"
		size="small"
		data-testid="payment-plan-action-modal"
		@closing="onClose">
		<div class="ppa">
			<p v-if="mode === 'activate'">
				{{
					t(
						'shillinq',
						'Reminders for the invoices on this plan pause, and the customer is mailed the schedule.',
					)
				}}
			</p>
			<template v-else-if="mode === 'settle'">
				<NcTextField
					v-model="amount"
					type="number"
					:label="t('shillinq', 'Amount')"
					data-testid="payment-plan-amount" />
				<NcTextField
					v-model="paidDate"
					type="date"
					:label="t('shillinq', 'Paid on')"
					data-testid="payment-plan-paid-date" />
				<NcTextField
					v-model="reference"
					:label="t('shillinq', 'Payment reference')" />
			</template>
			<template v-else>
				<p>
					{{
						t(
							'shillinq',
							'Reminders for the invoices on this plan resume.',
						)
					}}
				</p>
				<NcTextField
					v-model="reason"
					:label="t('shillinq', 'Reason')"
					data-testid="payment-plan-cancel-reason" />
			</template>
			<p
				v-if="error"
				class="ppa__error"
				role="alert"
				data-testid="payment-plan-action-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('shillinq', 'Close') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting"
				data-testid="payment-plan-action-submit"
				@click="submit">
				{{ title }}
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
import { activatePlan, cancelPlan, settlePlan } from '../utils/paymentPlanApi.js'

export default {
	name: 'PaymentPlanActionModal',

	components: { NcButton, NcDialog, NcTextField },

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// activate, settle or cancel.
		mode: {
			type: String,
			default: 'settle',
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			amount: '',
			paidDate: new Date().toISOString().slice(0, 10),
			reference: '',
			reason: '',
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The dialog title and button text.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		title() {
			if (this.mode === 'activate') {
				return t('shillinq', 'Activate plan')
			}
			if (this.mode === 'cancel') {
				return t('shillinq', 'Cancel plan')
			}
			return t('shillinq', 'Record a payment')
		},

		/**
		 * The plan's id: the prop, else the route's :id.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		effectiveId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	methods: {
		t,

		/**
		 * Run the chosen action.
		 *
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				let result
				if (this.mode === 'activate') {
					result = await activatePlan(this.effectiveId)
					showSuccess(t('shillinq', 'The plan is active.'))
				} else if (this.mode === 'cancel') {
					result = await cancelPlan(this.effectiveId, this.reason)
					showSuccess(t('shillinq', 'The plan is cancelled.'))
				} else {
					result = await settlePlan(this.effectiveId, {
						amount: this.amount,
						paidDate: this.paidDate,
						reference: this.reference,
					})
					showSuccess(t('shillinq', 'The payment is recorded.'))
				}
				emit('cn:page:refresh', {})
				this.$emit('close', result)
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The payment plan could not be updated.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without saving.
		 *
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.ppa {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ppa__error {
	color: var(--color-error-text);
}
</style>
