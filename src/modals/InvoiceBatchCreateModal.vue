<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 InvoiceBatchCreateModal: New invoice batch (sales-invoice-sending 3.3).

 The bookkeeper picks the customers, types the lines once and the invoice
 date; each customer's invoice is due by their own payment term unless a due
 date is given. Issue and send are optional; sending needs issuing. It posts
 to POST /api/ar-invoices/batch with a batch reference made once per dialog,
 so pressing Create again after a failure retries the same batch and writes
 only the invoices that are still missing.

 Opened with spawnDialog from the New invoice batch header action on Sales
 invoices and on Invoice batches (src/utils/invoiceBatchActions.js), so it
 lives in its own file (hydra gate-13).

 @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
-->

<template>
	<NcDialog
		:name="t('shillinq', 'New invoice batch')"
		size="normal"
		data-testid="invoice-batch-modal"
		@closing="close">
		<div v-if="result" class="ibm" data-testid="invoice-batch-result">
			<p role="status">
				{{
					t('shillinq', 'Batch {batchId}: {created} invoices created, {existing} already there, {skipped} skipped, {failed} failed.', {
						batchId: result.batchId,
						...counts,
					})
				}}
			</p>
			<p v-if="result.sendBatchId">
				{{ t('shillinq', 'The issued invoices are being sent. You get a notification when the batch is done.') }}
			</p>
			<ul v-if="problems.length > 0" class="ibm__problems">
				<li v-for="outcome in problems" :key="outcome.customerId">
					{{ customerName(outcome.customerId) }}: {{ outcome.reason }}
				</li>
			</ul>
		</div>
		<div v-else class="ibm">
			<p class="ibm__hint">
				{{ t('shillinq', 'Every selected customer gets one invoice with these lines, due by their own payment term unless you give a due date.') }}
			</p>
			<NcSelect
				v-model="customers"
				:options="customerOptions"
				:loading="loading"
				:multiple="true"
				:keepOpen="true"
				:inputLabel="t('shillinq', 'Customers')"
				data-testid="invoice-batch-customers" />
			<div class="ibm__dates">
				<NcTextField
					v-model="invoiceDate"
					type="date"
					:label="t('shillinq', 'Invoice date')"
					data-testid="invoice-batch-date" />
				<NcTextField
					v-model="dueDate"
					type="date"
					:label="t('shillinq', 'Due date (optional)')"
					data-testid="invoice-batch-due-date" />
			</div>
			<fieldset class="ibm__lines">
				<legend>{{ t('shillinq', 'Lines') }}</legend>
				<div
					v-for="(line, index) in lines"
					:key="index"
					class="ibm__line"
					:data-testid="'invoice-batch-line-' + index">
					<NcTextField
						v-model="line.description"
						class="ibm__description"
						:label="t('shillinq', 'Description')" />
					<NcTextField
						v-model="line.quantity"
						inputmode="decimal"
						:label="t('shillinq', 'Quantity')" />
					<NcTextField
						v-model="line.unitPrice"
						inputmode="decimal"
						:label="t('shillinq', 'Price excl. VAT')" />
					<NcSelect
						v-model="line.vatRate"
						:options="vatRates"
						:clearable="false"
						:inputLabel="t('shillinq', 'VAT %')" />
					<NcButton
						variant="tertiary"
						:aria-label="t('shillinq', 'Remove line')"
						:disabled="lines.length === 1"
						@click="lines.splice(index, 1)">
						{{ t('shillinq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton data-testid="invoice-batch-add-line" @click="lines.push(emptyLine())">
					{{ t('shillinq', 'Add line') }}
				</NcButton>
			</fieldset>
			<NcCheckboxRadioSwitch v-model="issue" data-testid="invoice-batch-issue">
				{{ t('shillinq', 'Issue the invoices') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="send"
				:disabled="!issue"
				data-testid="invoice-batch-send">
				{{ t('shillinq', 'Send them by each customer\'s delivery method') }}
			</NcCheckboxRadioSwitch>
			<p
				v-if="error"
				class="ibm__error"
				role="alert"
				data-testid="invoice-batch-error">
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
				:disabled="problem !== '' || submitting"
				:title="problem"
				data-testid="invoice-batch-submit"
				@click="submit">
				{{ t('shillinq', 'Create invoices') }}
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
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { fetchAdministrationContext } from '../api/administrationApi.js'
import {
	BATCH_VAT_RATES,
	batchOutcomeCounts,
	batchRequestProblem,
	buildBatchRequest,
	createInvoiceBatch,
	emptyBatchLine,
	newBatchId,
} from '../utils/invoiceBatchCreate.js'

export default {
	name: 'InvoiceBatchCreateModal',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	emits: ['close'],
	data() {
		return {
			loading: false,
			administrationId: '',
			batchId: newBatchId(),
			customerRows: [],
			customers: [],
			invoiceDate: new Date().toISOString().slice(0, 10),
			dueDate: '',
			lines: [emptyBatchLine()],
			vatRates: BATCH_VAT_RATES,
			issue: true,
			send: false,
			error: '',
			submitting: false,
			result: null,
		}
	},

	computed: {
		/**
		 * The administration's customers, by name.
		 *
		 * @return {Array<object>} Select options.
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		customerOptions() {
			return this.customerRows.map((customer) => ({
				value: String(customer.id ?? customer['@self']?.id ?? ''),
				label: String(customer.legalName || customer.tradeName || customer.customerId || customer.id),
			}))
		},

		/**
		 * The request the form holds now.
		 *
		 * @return {object} The body.
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		body() {
			return buildBatchRequest({
				administrationId: this.administrationId,
				batchId: this.batchId,
				customers: this.customers,
				lines: this.lines,
				invoiceDate: this.invoiceDate,
				dueDate: this.dueDate,
				issue: this.issue,
				send: this.send,
			})
		},

		/**
		 * Why the form cannot be submitted yet, or ''.
		 *
		 * @return {string} The reason.
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		problem() {
			return batchRequestProblem(this.body, t)
		},

		/**
		 * The outcome counts of the created batch.
		 *
		 * @return {object} The counts.
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		counts() {
			return batchOutcomeCounts(this.result)
		},

		/**
		 * The customers for whom no invoice was written, with the reason.
		 *
		 * @return {Array<object>} The outcomes.
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		problems() {
			const outcomes = Array.isArray(this.result?.outcomes) ? this.result.outcomes : []
			return outcomes.filter((outcome) => outcome.status === 'skipped' || outcome.status === 'failed')
		},
	},

	/**
	 * Load the active administration and its customers.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
	 */
	async mounted() {
		this.loading = true
		try {
			const context = await fetchAdministrationContext()
			this.administrationId = String(context?.activeAdministrationId || '')
			const response = await axios.get(
				generateUrl('/apps/openregister/api/objects/shillinq/CustomerMaster'),
				{ params: { administrationId: this.administrationId, _limit: 500 } },
			)
			const rows = response?.data?.results ?? response?.data?.objects ?? []
			this.customerRows = Array.isArray(rows) ? rows : []
		} catch {
			this.error = t('shillinq', 'The customers could not be loaded.')
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * A new empty line.
		 *
		 * @return {object} The line.
		 *
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		emptyLine() {
			return emptyBatchLine()
		},

		/**
		 * The name of a selected customer, else its id.
		 *
		 * @param {string} id The customer id.
		 * @return {string} The name.
		 *
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		customerName(id) {
			return this.customerOptions.find((option) => option.value === id)?.label || id
		},

		/**
		 * Create the batch; a failure keeps the form and its batch reference.
		 *
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				this.result = await createInvoiceBatch(this.body)
			} catch (error) {
				this.error = error?.response?.data?.error || t('shillinq', 'The batch could not be created. Press Create invoices again to retry; nothing is written twice.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close, handing back the batch when one was created.
		 *
		 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-3.3
		 */
		close() {
			this.$emit('close', this.result)
		},
	},
}
</script>

<style scoped>
.ibm {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.ibm__hint {
	color: var(--color-text-maxcontrast);
}

.ibm__dates {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.ibm__lines {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	border: none;
	padding: 0;
}

.ibm__line {
	display: grid;
	grid-template-columns: 3fr 1fr 1fr 1fr auto;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: end;
}

.ibm__error {
	color: var(--color-error-text);
}

.ibm__problems {
	list-style: disc;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}
</style>
