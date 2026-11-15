<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 InboundPayableStepDialog: approve, mark paid or reject one payout another app
 raised (inbound-payable-from-another-app T2.1, REQ-IPY-002, REQ-IPY-003).

 Approving asks a confirmation; marking paid asks the bank reference, the bank
 account it left from and the date; rejecting asks the reason the raising app
 is told. Each posts to InboundPayableController, which checks the
 payment.administer action and books the step. Opened with spawnDialog from
 src/utils/inboundPayableActions.js, so it lives in its own file (hydra gate-13).

 @spec openspec/changes/inbound-payable-from-another-app/tasks.md
-->

<template>
	<NcDialog
		:name="title"
		size="normal"
		data-testid="inbound-payable-step-dialog"
		@closing="close">
		<p v-if="summary" data-testid="inbound-payable-step-summary">
			{{ summary }}
		</p>
		<template v-if="step === 'paid'">
			<NcTextField
				v-model="bankReference"
				:label="t('shillinq', 'Bank reference')"
				data-testid="inbound-payable-bank-reference" />
			<NcTextField
				v-model="bankAccount"
				:label="t('shillinq', 'Bank ledger account')"
				data-testid="inbound-payable-bank-account" />
			<NcTextField
				v-model="paidOn"
				type="date"
				:label="t('shillinq', 'Paid on')"
				:helperText="t('shillinq', 'Leave empty for today.')"
				data-testid="inbound-payable-paid-on" />
		</template>
		<NcTextField
			v-if="step === 'reject'"
			v-model="reason"
			:label="t('shillinq', 'Reason')"
			:helperText="t('shillinq', 'The app that raised the payout is told this reason.')"
			data-testid="inbound-payable-reason" />
		<p
			v-if="error"
			class="ipsd__error"
			role="alert"
			data-testid="inbound-payable-step-error">
			{{ error }}
		</p>

		<template #actions>
			<NcButton @click="close">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				:variant="step === 'reject' ? 'error' : 'primary'"
				:disabled="submitting"
				data-testid="inbound-payable-step-confirm"
				@click="confirm">
				{{ confirmLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'
import { stepBody, takePayableStep } from '../utils/inboundPayableApi.js'

export default {
	name: 'InboundPayableStepDialog',
	components: {
		NcButton,
		NcDialog,
		NcTextField,
	},

	props: {
		/** The payable's id. */
		payableId: {
			type: String,
			required: true,
		},

		/** `approve`, `paid` or `reject`. */
		step: {
			type: String,
			required: true,
		},

		/** The row, for the summary line. */
		payable: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	data() {
		return {
			bankReference: '',
			bankAccount: '',
			paidOn: '',
			reason: '',
			error: '',
			submitting: false,
		}
	},

	computed: {
		/**
		 * The dialog title for the step.
		 *
		 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
		 */
		title() {
			return (
				{
					approve: t('shillinq', 'Approve payout'),
					paid: t('shillinq', 'Mark payout paid'),
					reject: t('shillinq', 'Reject payout'),
				}[this.step] || t('shillinq', 'Payout')
			)
		},

		/**
		 * The confirm button's label for the step.
		 *
		 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
		 */
		confirmLabel() {
			return (
				{
					approve: t('shillinq', 'Approve'),
					paid: t('shillinq', 'Mark paid'),
					reject: t('shillinq', 'Reject'),
				}[this.step] || t('shillinq', 'Confirm')
			)
		},

		/**
		 * Who gets how much, when the row carries it.
		 *
		 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
		 */
		summary() {
			const name = this.payable?.payee?.name
			const amount = this.payable?.amount
			if (!name || amount === undefined || amount === null) {
				return ''
			}
			return t('shillinq', '{amount} to {name}', {
				amount: `${this.payable.currency || 'EUR'} ${Number(amount).toFixed(2)}`,
				name,
			})
		},
	},

	methods: {
		t,

		/**
		 * The sentence for a value the step needs and did not get.
		 *
		 * @param {string} code The code stepBody() threw.
		 * @return {string}
		 */
		refusal(code) {
			return (
				{
					'missing-bank': t('shillinq', 'Fill in the bank reference and the bank ledger account.'),
					'bad-date': t('shillinq', 'The paid date must read YYYY-MM-DD.'),
					'missing-reason': t('shillinq', 'Give a reason for the rejection.'),
				}[code] || t('shillinq', 'The payout step could not be carried out.')
			)
		},

		/**
		 * Take the step and close with the payable the server answered.
		 *
		 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
		 */
		async confirm() {
			this.error = ''
			let body
			try {
				body = stepBody(this.step, {
					bankReference: this.bankReference,
					bankAccount: this.bankAccount,
					paidOn: this.paidOn,
					reason: this.reason,
				})
			} catch (error) {
				this.error = this.refusal(error.message)
				return
			}

			this.submitting = true
			try {
				const payable = await takePayableStep(this.payableId, this.step, body)
				showSuccess(t('shillinq', 'Payout updated.'))
				emit('cn:page:refresh', {})
				this.$emit('close', payable)
			} catch (error) {
				this.error =
					error?.response?.data?.error
					|| t('shillinq', 'The payout step could not be carried out.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without taking the step.
		 *
		 * @spec openspec/changes/inbound-payable-from-another-app/tasks.md
		 */
		close() {
			this.$emit('close', null)
		},
	},
}
</script>

<style scoped>
.ipsd__error {
	color: var(--color-error-text);
}
</style>
