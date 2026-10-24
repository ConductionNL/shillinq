<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 AgreePaymentPlanModal: agree a payment plan for a customer's overdue
 invoices (receivables-payment-plans REQ-RPPL-001).

 Step one draws up the schedule on the server (POST /api/v1/payment-plans),
 which adds it up to the cent and shows the instalments. Step two activates
 it: dunning of the invoices pauses and the customer is mailed the schedule.

 Opened from the "Agree a payment plan" header action on CustomerDetail
 (the route's :id is the customer) and on ARInvoiceDetail of an overdue
 invoice (the modal reads the invoice's customer and preselects it). Its own
 file for hydra gate-13.

 @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/specs/bookkeeping-credit-control-dunning/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Agree a payment plan')"
		size="normal"
		data-testid="agree-payment-plan-modal"
		@closing="onClose">
		<div class="app">
			<template v-if="!draft">
				<p v-if="invoices.length === 0 && !loading" class="app__muted">
					{{ t('shillinq', 'This customer has no overdue invoices.') }}
				</p>
				<fieldset v-else class="app__invoices">
					<legend>{{ t('shillinq', 'Invoices') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="invoice in invoices"
						:key="invoice.id"
						:modelValue="form.invoiceIds.includes(invoice.id)"
						:data-testid="'agree-plan-invoice-' + invoice.invoiceNumber"
						@update:modelValue="toggle(invoice.id)">
						{{ invoice.invoiceNumber }} · {{ money(invoice.amountDue) }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<NcCheckboxRadioSwitch
					v-model="form.mode"
					value="count"
					name="plan-mode"
					type="radio">
					{{ t('shillinq', 'Number of instalments') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="form.mode"
					value="amount"
					name="plan-mode"
					type="radio">
					{{ t('shillinq', 'Amount per instalment') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="form.mode === 'count'"
					v-model="form.instalmentCount"
					type="number"
					:label="t('shillinq', 'Number of instalments')"
					data-testid="agree-plan-count" />
				<NcTextField
					v-else
					v-model="form.instalmentAmount"
					type="number"
					:label="t('shillinq', 'Amount per instalment')"
					data-testid="agree-plan-amount" />
				<NcSelect
					v-model="frequency"
					:options="frequencyOptions"
					:inputLabel="t('shillinq', 'Frequency')"
					data-testid="agree-plan-frequency" />
				<NcTextField
					v-model="form.firstDueDate"
					type="date"
					:label="t('shillinq', 'First due date')"
					data-testid="agree-plan-first-due" />
				<NcTextField
					v-model="form.graceDays"
					type="number"
					:label="t('shillinq', 'Grace days')"
					:helperText="
						t(
							'shillinq',
							'Days an instalment may be late before the plan ends.',
						)
					" />
				<NcCheckboxRadioSwitch v-model="form.includesCharges">
					{{ t('shillinq', 'Include collection costs already charged') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="form.includesCharges"
					v-model="form.chargesAmount"
					type="number"
					:label="t('shillinq', 'Collection costs')" />
				<NcTextField
					v-model="form.agreedWith"
					:label="t('shillinq', 'Agreed with')" />
				<NcTextField v-model="form.note" :label="t('shillinq', 'Note')" />
			</template>

			<template v-else>
				<p data-testid="agree-plan-summary">
					{{
						t(
							'shillinq',
							'Plan {number}: {count} instalments, {total} in total.',
							{
								number: draft.plan.planNumber,
								count: draft.instalments.length,
								total: money(draft.plan.totalAmount),
							},
						)
					}}
				</p>
				<ol class="app__schedule" data-testid="agree-plan-schedule">
					<li v-for="instalment in draft.instalments" :key="instalment.id">
						{{ instalment.dueDate }} · {{ money(instalment.amount) }}
					</li>
				</ol>
				<p class="app__muted">
					{{
						t(
							'shillinq',
							'Activating pauses reminders for these invoices and mails the schedule to the customer.',
						)
					}}
				</p>
			</template>

			<p
				v-if="error"
				class="app__error"
				role="alert"
				data-testid="agree-plan-error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!draft"
				variant="primary"
				:disabled="submitting || form.invoiceIds.length === 0"
				data-testid="agree-plan-draw-up"
				@click="drawUp">
				{{ t('shillinq', 'Draw up schedule') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="submitting"
				data-testid="agree-plan-activate"
				@click="activate">
				{{ t('shillinq', 'Activate plan') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { errorMessage } from '../utils/downPaymentApi.js'
import {
	activatePlan,
	draftPlan,
	loadInvoice,
	loadPlannableInvoices,
} from '../utils/paymentPlanApi.js'

export default {
	name: 'AgreePaymentPlanModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		// customer or invoice: what the route's :id is.
		from: {
			type: String,
			default: 'customer',
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			invoices: [],
			customerId: '',
			frequency: null,
			form: {
				invoiceIds: [],
				mode: 'count',
				instalmentCount: 6,
				instalmentAmount: '',
				firstDueDate: '',
				graceDays: 14,
				includesCharges: false,
				chargesAmount: '',
				agreedWith: '',
				note: '',
			},

			draft: null,
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The frequencies on offer.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		frequencyOptions() {
			return [
				{ value: 'monthly', label: t('shillinq', 'Monthly') },
				{ value: 'weekly', label: t('shillinq', 'Weekly') },
			]
		},

		/**
		 * The route or prop id.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		effectiveId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	/**
	 * Load the customer's plannable invoices.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
	 */
	async mounted() {
		this.frequency = this.frequencyOptions[0]
		const today = new Date().toISOString().slice(0, 10)
		try {
			this.customerId = this.effectiveId
			let preselect = ''
			if (this.from === 'invoice') {
				const invoice = await loadInvoice(this.effectiveId)
				this.customerId = String(invoice.customerId || '')
				preselect = this.effectiveId
			}
			this.invoices = await loadPlannableInvoices(this.customerId, today)
			this.form.invoiceIds = preselect
				? [preselect]
				: this.invoices.map((invoice) => invoice.id)
		} catch (error) {
			this.error = errorMessage(
				error,
				t('shillinq', 'The invoices could not be loaded.'),
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * An amount as EUR with two decimals.
		 *
		 * @param {number|string} amount The amount.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		money(amount) {
			return `EUR ${Number(amount || 0).toFixed(2)}`
		},

		/**
		 * Select or deselect an invoice.
		 *
		 * @param {string} id The invoice id.
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		toggle(id) {
			this.form.invoiceIds = this.form.invoiceIds.includes(id)
				? this.form.invoiceIds.filter((existing) => existing !== id)
				: [...this.form.invoiceIds, id]
		},

		/**
		 * Draw up the schedule on the server.
		 *
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		async drawUp() {
			this.error = ''
			this.submitting = true
			const first = this.invoices.find((invoice) =>
				this.form.invoiceIds.includes(invoice.id),
			)
			try {
				this.draft = await draftPlan({
					...this.form,
					frequency: this.frequency?.value || 'monthly',
					customerId: this.customerId,
					administrationId: first?.administrationId || '',
				})
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The schedule could not be drawn up.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Activate the drawn-up plan.
		 *
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
		 */
		async activate() {
			this.error = ''
			this.submitting = true
			try {
				const plan = await activatePlan(this.draft.plan.id)
				showSuccess(
					t('shillinq', 'Payment plan {number} is active.', {
						number: plan.planNumber,
					}),
				)
				emit('cn:page:refresh', {})
				this.$emit('close', plan)
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The payment plan could not be activated.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close. A drawn-up plan stays as a draft on the Payment plans page.
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
.app {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.app__invoices {
	border: none;
	padding: 0;
}

.app__schedule {
	padding-inline-start: 20px;
}

.app__muted {
	color: var(--color-text-maxcontrast);
}

.app__error {
	color: var(--color-error-text);
}
</style>
