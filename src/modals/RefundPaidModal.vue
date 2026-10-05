<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RefundPaidModal: record that an approved refund was paid by bank
 (receivables-object-request-refund-and-credit 3.2, REQ-ORC-002).

 Asks the bank reference and the ledger account of the bank it was paid
 from, and posts both to ObjectRequestRefundController::markPaid. The server
 books debit refunds payable, credit that bank account, and moves the request
 to refunded. A refusal (no payment.administer, the refund not approved yet)
 is shown as the server words it.

 Opened by the Mark paid row action on the Refunds to pay page
 (src/utils/refundActions.js) through spawnDialog. Its own file for hydra
 gate-13.

 @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Mark refund paid')"
		size="small"
		data-testid="refund-paid-modal"
		@closing="onClose">
		<div class="rpm">
			<p>
				{{ t('shillinq', 'Pay the refund by bank first. Then record the payment here.') }}
			</p>
			<NcTextField
				v-model="bankReference"
				:label="t('shillinq', 'Bank reference')"
				data-testid="refund-paid-reference" />
			<NcSelect
				v-model="bankAccount"
				:options="accountOptions"
				:inputLabel="t('shillinq', 'Bank account')"
				:loading="loadingAccounts"
				data-testid="refund-paid-account" />
			<p
				v-if="error"
				class="rpm__error"
				role="alert"
				data-testid="refund-paid-error">
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
				data-testid="refund-paid-submit"
				@click="submit">
				{{ t('shillinq', 'Mark paid') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { refundError, refundStepUrl } from '../utils/refundApi.js'

export default {
	name: 'RefundPaidModal',

	components: { NcButton, NcDialog, NcSelect, NcTextField },

	props: {
		// The PaymentRequest row whose newest refund is approved.
		paymentRequest: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			bankReference: '',
			bankAccount: null,
			accounts: [],
			loadingAccounts: false,
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The ledger accounts as select options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
		 */
		accountOptions() {
			return this.accounts
				.filter((account) => account && account.accountNumber)
				.map((account) => ({
					value: String(account.accountNumber),
					label: `${account.accountNumber} ${account.name || ''}`.trim(),
				}))
		},
	},

	mounted() {
		this.loadAccounts()
	},

	methods: {
		t,

		/**
		 * Read the ledger accounts of the request's administration.
		 *
		 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
		 */
		async loadAccounts() {
			this.loadingAccounts = true
			try {
				const response = await axios.get(
					generateUrl('/apps/openregister/api/objects/shillinq/Account'),
					{
						params: {
							administrationId: this.paymentRequest.administrationId,
							_limit: 1000,
						},
					},
				)
				const rows =
					response?.data?.results ?? response?.data?.objects ?? []
				this.accounts = Array.isArray(rows) ? rows : []
			} catch (error) {
				this.error = refundError(
					error,
					t('shillinq', 'The ledger accounts could not be loaded.'),
				)
			} finally {
				this.loadingAccounts = false
			}
		},

		/**
		 * Post the bank payment and close with the server's answer.
		 *
		 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
		 */
		async submit() {
			this.error = ''
			const bankReference = String(this.bankReference || '').trim()
			const bankAccount = String(this.bankAccount?.value || '')
			if (bankReference === '' || bankAccount === '') {
				this.error = t(
					'shillinq',
					'Fill in the bank reference and choose the bank account.',
				)
				return
			}
			this.submitting = true
			try {
				const response = await axios.post(
					refundStepUrl(this.paymentRequest.id, 'paid'),
					{ bankReference, bankAccount },
				)
				showSuccess(t('shillinq', 'Refund marked paid.'))
				this.$emit('close', response.data)
			} catch (error) {
				this.error = refundError(
					error,
					t('shillinq', 'The refund could not be marked paid.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without recording anything.
		 *
		 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.rpm {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.rpm__error {
	color: var(--color-error-text);
}
</style>
