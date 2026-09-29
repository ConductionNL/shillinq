<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ProposePaymentRunModal: draft a payment run from the invoices due
 (banking-payment-run REQ-BPR-002, REQ-BPR-006).

 The bookkeeper picks the last due date, the day the run goes out and the
 account it leaves from. The server writes a draft run with one line per
 payable invoice and names every invoice it left out with the reason. The
 draft is edited and approved on its own page, where the second person
 approves it as before.

 Opened with spawnDialog from the "Propose payment run" header action on
 Payment runs (src/utils/paymentRunActions.js), so it lives in its own file
 (hydra gate-13).

 @spec openspec/changes/banking-payment-run/specs/payment-run-sepa-export/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Propose payment run')"
		size="normal"
		data-testid="propose-payment-run-modal"
		@closing="close">
		<div class="ppr">
			<template v-if="!result">
				<p class="ppr__hint">
					{{ t('shillinq', 'Every open supplier invoice due by the date you choose goes on a draft run. Blocked, disputed and already batched invoices stay off it.') }}
				</p>
				<NcTextField
					v-model="dueOnOrBefore"
					type="date"
					:label="t('shillinq', 'Due on or before')"
					data-testid="propose-due" />
				<NcTextField
					v-model="executionDate"
					type="date"
					:label="t('shillinq', 'Execution date')"
					data-testid="propose-execution" />
				<NcTextField
					v-model="debtorAccountIban"
					:label="t('shillinq', 'Pay from IBAN')"
					data-testid="propose-iban" />
				<NcCheckboxRadioSwitch
					v-model="payOnDueDate"
					data-testid="propose-on-due-date">
					{{ t('shillinq', 'Pay each invoice on its due date') }}
				</NcCheckboxRadioSwitch>
			</template>

			<template v-else>
				<p v-if="result.paymentRun" data-testid="propose-created">
					{{ t('shillinq', 'Draft run {number} with {count} payments, total EUR {total}.', {
						number: result.paymentRun.runNumber,
						count: result.paymentRun.paymentLines.length,
						total: Number(result.paymentRun.totalAmount).toFixed(2),
					}) }}
					<a :href="runUrl" data-testid="propose-open-run">{{ t('shillinq', 'Open the payment run') }}</a>
				</p>
				<p v-else data-testid="propose-nothing">
					{{ t('shillinq', 'No invoice can be paid by that date.') }}
				</p>
				<template v-if="result.skipped && result.skipped.length">
					<h3 class="ppr__skipped-title">
						{{ t('shillinq', 'Left out') }}
					</h3>
					<ul class="ppr__skipped" data-testid="propose-skipped">
						<li v-for="skip in result.skipped" :key="skip.apTransactionRef">
							{{ skip.invoiceNumber }}: {{ reasonText(skip) }}
						</li>
					</ul>
				</template>
			</template>

			<p
				v-if="error"
				class="ppr__error"
				role="alert"
				data-testid="propose-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ result ? t('shillinq', 'Close') : t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="!canSubmit || submitting"
				data-testid="propose-submit"
				@click="submit">
				{{ t('shillinq', 'Propose') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcTextField,
} from '@nextcloud/vue'
import { errorMessage } from '../utils/downPaymentApi.js'
import { proposePaymentRun, skipReasonText } from '../utils/paymentRunApi.js'

/**
 * Today as Y-m-d.
 *
 * @return {string} The date.
 */
function today() {
	return new Date().toISOString().slice(0, 10)
}

export default {
	name: 'ProposePaymentRunModal',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

	emits: ['close'],

	data() {
		return {
			administrationId: '',
			dueOnOrBefore: today(),
			executionDate: today(),
			debtorAccountIban: '',
			payOnDueDate: false,
			submitting: false,
			result: null,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the form is complete.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
		 */
		canSubmit() {
			return this.administrationId !== '' && this.dueOnOrBefore !== '' && this.executionDate !== '' && this.debtorAccountIban.trim() !== ''
		},

		/**
		 * Link to the new run's page.
		 *
		 * @return {string}
		 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
		 */
		runUrl() {
			const id = this.result?.paymentRun?.id || ''
			return generateUrl('/apps/shillinq/bookkeeping/payment-runs/' + encodeURIComponent(id))
		},
	},

	/**
	 * Load the active administration.
	 *
	 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
	 */
	async mounted() {
		try {
			const context = await axios.get(generateUrl('/apps/shillinq/api/administrations/context'))
			this.administrationId = String(context.data?.activeAdministrationId || '')
		} catch (error) {
			this.error = errorMessage(error, t('shillinq', 'The administration could not be loaded.'))
		}
	},

	methods: {
		t,

		/**
		 * The sentence for a left-out invoice.
		 *
		 * @param {object} skip The entry.
		 * @return {string}
		 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
		 */
		reasonText(skip) {
			return skipReasonText(skip)
		},

		/**
		 * Ask for the draft run.
		 *
		 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await proposePaymentRun({
					administrationId: this.administrationId,
					dueOnOrBefore: this.dueOnOrBefore,
					executionDate: this.executionDate,
					debtorAccountIban: this.debtorAccountIban,
					payOnDueDate: this.payOnDueDate,
				})
			} catch (error) {
				this.error = errorMessage(error, t('shillinq', 'The payment run could not be proposed.'))
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/banking-payment-run/tasks.md#task-3.2
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.ppr {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ppr__hint {
	color: var(--color-text-maxcontrast);
}

.ppr__skipped-title {
	font-size: 1em;
	margin: 0;
}

.ppr__skipped {
	margin: 0;
	padding-inline-start: 20px;
}

.ppr__error {
	color: var(--color-error-text);
}
</style>
