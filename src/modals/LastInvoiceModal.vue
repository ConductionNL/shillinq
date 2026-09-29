<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LastInvoiceModal: mark an approved supplier invoice as the last one of its
 order (planning-commitment-year-end, REQ-PCYE-003).

 Shows how much of the commitment is released back to the budget, and on
 confirmation closes the commitment. Opened from SupplierInvoiceDetail, so it
 lives in its own file (gate 13).

 @spec openspec/changes/planning-commitment-year-end/specs/bookkeeping-verplichtingenadministratie/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Mark as last invoice')"
		size="small"
		data-testid="last-invoice-modal"
		@closing="close">
		<div class="lim">
			<p v-if="preview && !done" data-testid="last-invoice-release">
				{{
					t(
						'shillinq',
						'This closes commitment {commitment} and releases EUR {amount} back to the budget.',
						{
							commitment: preview.commitmentNumber,
							amount: euro(preview.release),
						},
					)
				}}
			</p>
			<p v-if="done" data-testid="last-invoice-done">
				{{
					t('shillinq', 'Commitment {commitment} is closed.', {
						commitment: preview.commitmentNumber,
					})
				}}
			</p>
			<p
				v-if="error"
				class="lim__error"
				role="alert"
				data-testid="last-invoice-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ done ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!done"
				variant="primary"
				:disabled="!preview || submitting"
				data-testid="last-invoice-confirm"
				@click="submit">
				{{ t('shillinq', 'Release and close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import {
	euro,
	markLastInvoice,
	previewLastInvoice,
} from '../utils/commitmentYearEndApi.js'
import { errorMessage } from '../utils/downPaymentApi.js'

export default {
	name: 'LastInvoiceModal',

	components: { NcButton, NcDialog },

	props: {
		invoiceId: {
			type: String,
			required: true,
		},

		administrationId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			preview: null,
			done: false,
			submitting: false,
			error: '',
		}
	},

	/**
	 * Load what would be released.
	 *
	 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.3
	 */
	async mounted() {
		try {
			this.preview = await previewLastInvoice(
				this.administrationId,
				this.invoiceId,
			)
		} catch (error) {
			this.error = errorMessage(
				error,
				t('shillinq', 'The commitment could not be loaded.'),
			)
		}
	},

	methods: {
		t,
		euro,

		/**
		 * Mark the invoice and close the commitment.
		 *
		 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.3
		 */
		async submit() {
			this.submitting = true
			this.error = ''
			try {
				this.preview = await markLastInvoice(
					this.administrationId,
					this.invoiceId,
				)
				this.done = true
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The commitment could not be closed.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/planning-commitment-year-end/tasks.md#task-2.3
		 */
		close() {
			this.$emit('close', this.done)
		},
	},
}
</script>

<style scoped>
.lim__error {
	color: var(--color-error);
}
</style>
