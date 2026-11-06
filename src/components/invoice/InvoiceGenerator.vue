<!--
  Invoice Generator

  Admin form for drafting a BillableInvoice from approved time entries +
  expense records. Renders the six-model selector (T&M, fixed-fee,
  milestone, retainer, mixed, usage) per Task 15, captures the date range,
  rate-card / retainer pickers, customer + project, and offers the three
  end actions: Save as Draft, Preview PDF, Post to AR.

  @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15

  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section class="invoice-generator">
		<header class="invoice-generator__header">
			<h2>{{ t('shillinq', 'Generate Invoice') }}</h2>
		</header>

		<form class="invoice-generator__form" @submit.prevent="onSaveDraft">
			<div class="invoice-generator__row">
				<label>
					{{ t('shillinq', 'Billing model') }}
					<select v-model="form.billingModel" required>
						<option value="t_and_m">
							{{ t('shillinq', 'Time & Materials') }}
						</option>
						<option value="fixed_fee">
							{{ t('shillinq', 'Fixed fee') }}
						</option>
						<option value="milestone">
							{{ t('shillinq', 'Milestone') }}
						</option>
						<option value="retainer">
							{{ t('shillinq', 'Retainer') }}
						</option>
						<option value="mixed">{{ t('shillinq', 'Mixed') }}</option>
						<option value="usage">{{ t('shillinq', 'Usage') }}</option>
					</select>
				</label>
				<label>
					{{ t('shillinq', 'Customer') }}
					<input v-model="form.customerId" type="text" required />
				</label>
				<label>
					{{ t('shillinq', 'Project (optional)') }}
					<input v-model="form.projectId" type="text" />
				</label>
			</div>

			<div class="invoice-generator__row">
				<label>
					{{ t('shillinq', 'From') }}
					<input v-model="form.fromDate" type="date" required />
				</label>
				<label>
					{{ t('shillinq', 'To') }}
					<input v-model="form.toDate" type="date" required />
				</label>
			</div>

			<div class="invoice-generator__row">
				<label v-if="needsRateCard">
					{{ t('shillinq', 'Rate card') }}
					<select v-model="form.rateCardId">
						<option
							v-for="card in rateCards"
							:key="card.id"
							:value="card.id">
							{{ card.label }}
						</option>
					</select>
				</label>
				<label v-if="needsRetainer">
					{{ t('shillinq', 'Retainer schedule') }}
					<select v-model="form.retainerScheduleId">
						<option v-for="rs in retainers" :key="rs.id" :value="rs.id">
							{{ rs.label }}
						</option>
					</select>
				</label>
				<label v-if="needsFixedFee">
					{{ t('shillinq', 'Fixed fee (€)') }}
					<input
						v-model.number="fixedFeeEuros"
						type="number"
						min="0"
						step="0.01" />
				</label>
				<label v-if="needsMilestone">
					{{ t('shillinq', 'Milestone ID') }}
					<input v-model="form.milestoneId" type="text" />
				</label>
			</div>

			<fieldset
				v-if="needsUsage"
				class="invoice-generator__usage"
				data-testid="usage-readings">
				<legend>{{ t('shillinq', 'Rated readings to bill') }}</legend>
				<p v-if="readingsError" role="alert">
					{{ readingsError }}
				</p>
				<p v-else-if="readings.length === 0">
					{{
						t(
							'shillinq',
							'This customer has no rated readings in this period that are not on an invoice yet.',
						)
					}}
				</p>
				<label
					v-for="reading in readings"
					:key="reading.id"
					class="invoice-generator__reading">
					<input
						v-model="meterReadingIds"
						type="checkbox"
						:value="reading.id" />
					{{ readingLabel(reading) }}
				</label>
			</fieldset>

			<div v-if="!needsUsage" class="invoice-generator__row">
				<label class="invoice-generator__row--wide">
					{{ t('shillinq', 'Time entry IDs (comma-separated)') }}
					<textarea v-model="timeIdsRaw" rows="2" />
				</label>
				<label class="invoice-generator__row--wide">
					{{ t('shillinq', 'Expense IDs (comma-separated)') }}
					<textarea v-model="expenseIdsRaw" rows="2" />
				</label>
			</div>

			<div class="invoice-generator__row">
				<label class="invoice-generator__row--wide">
					{{ t('shillinq', 'Notes') }}
					<textarea v-model="form.notes" rows="2" />
				</label>
			</div>

			<div v-if="totals" class="invoice-generator__totals">
				<p>
					<strong>{{ t('shillinq', 'Net amount') }}:</strong> €
					{{ formatMoney(totals.netAmount) }}
				</p>
				<p>
					<strong>{{ t('shillinq', 'VAT/BTW') }}:</strong> €
					{{ formatMoney(totals.vatAmount) }}
				</p>
				<p>
					<strong>{{ t('shillinq', 'Gross amount') }}:</strong> €
					{{ formatMoney(totals.grossAmount) }}
				</p>
			</div>

			<div class="invoice-generator__actions">
				<button type="submit" :disabled="busy">
					{{ t('shillinq', 'Save as Draft') }}
				</button>
				<button type="button" :disabled="busy" @click="onPreviewPdf">
					{{ t('shillinq', 'Preview PDF') }}
				</button>
				<button type="button" :disabled="busy || !draftId" @click="onPost">
					{{ t('shillinq', 'Post to AR') }}
				</button>
			</div>

			<p v-if="error" class="invoice-generator__error" role="alert">
				{{ error }}
			</p>
		</form>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import invoiceApi from '../../api/invoiceApi.js'
import { loadBillableReadings } from '../../utils/usageBilling.js'

export default {
	name: 'InvoiceGenerator',

	props: {
		rateCards: {
			type: Array,
			default: () => [],
		},

		retainers: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			form: {
				billingModel: 't_and_m',
				customerId: '',
				projectId: '',
				fromDate: '',
				toDate: '',
				rateCardId: '',
				retainerScheduleId: '',
				milestoneId: '',
				notes: '',
			},

			fixedFeeEuros: 0,
			readings: [],
			meterReadingIds: [],
			readingsError: '',
			timeIdsRaw: '',
			expenseIdsRaw: '',
			draftId: null,
			totals: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		needsRateCard() {
			return ['t_and_m', 'mixed'].includes(this.form.billingModel)
		},

		needsRetainer() {
			return ['retainer', 'mixed'].includes(this.form.billingModel)
		},

		needsFixedFee() {
			return ['fixed_fee', 'mixed'].includes(this.form.billingModel)
		},

		needsMilestone() {
			return this.form.billingModel === 'milestone'
		},

		/**
		 * Whether the usage model is chosen (sales-usage-billing REQ-USB-002).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
		 */
		needsUsage() {
			return this.form.billingModel === 'usage'
		},

		/**
		 * What the usage reading list depends on.
		 *
		 * @return {string}
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
		 */
		usageQuery() {
			return [
				this.form.billingModel,
				this.form.customerId,
				this.form.fromDate,
				this.form.toDate,
			].join('|')
		},

		/**
		 * The generation request, with the selected reading ids for usage.
		 *
		 * @return {object}
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
		 */
		payload() {
			return {
				...this.form,
				timeEntryIds: this.parseIds(this.timeIdsRaw),
				expenseIds: this.parseIds(this.expenseIdsRaw),
				fixedFeeCents: Math.round(Number(this.fixedFeeEuros) * 100),
				meterReadingIds: this.needsUsage ? [...this.meterReadingIds] : [],
			}
		},
	},

	watch: {
		/**
		 * Reload the billable readings when the model, customer or period changes.
		 *
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
		 */
		usageQuery() {
			this.loadReadings()
		},
	},

	methods: {
		t,
		parseIds(raw) {
			if (typeof raw !== 'string' || raw.trim() === '') {
				return []
			}
			return raw
				.split(',')
				.map((s) => s.trim())
				.filter(Boolean)
		},

		/**
		 * Load the customer's rated readings in the period; select them all.
		 *
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
		 */
		async loadReadings() {
			this.readings = []
			this.meterReadingIds = []
			this.readingsError = ''
			const { customerId, fromDate, toDate } = this.form
			if (!this.needsUsage || !customerId || !fromDate || !toDate) {
				return
			}
			try {
				this.readings = await loadBillableReadings(
					customerId,
					fromDate,
					toDate,
				)
				this.meterReadingIds = this.readings.map((reading) => reading.id)
			} catch {
				this.readingsError = t(
					'shillinq',
					'The readings could not be loaded.',
				)
			}
		},

		/**
		 * One reading as a line: period, quantity and amount.
		 *
		 * @param {object} reading A meter reading.
		 * @return {string}
		 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.2
		 */
		readingLabel(reading) {
			return t('shillinq', '{from} to {to}: {quantity} {unit}, EUR {amount}', {
				from: reading.periodStart,
				to: reading.periodEnd,
				quantity: reading.quantity,
				unit: reading.unit || '',
				amount: this.formatMoney(reading.ratedAmount),
			})
		},

		formatMoney(value) {
			const n = Number(value || 0)
			return n.toLocaleString('nl-NL', {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})
		},

		async onSaveDraft() {
			this.busy = true
			this.error = ''
			try {
				const result = await invoiceApi.generate(this.payload)
				this.draftId = result?.id || result?.invoiceId || null
				this.totals = result?.summary || {
					netAmount: result?.netAmount,
					vatAmount: result?.vatAmount,
					grossAmount: result?.grossAmount,
				}
				this.$emit('draft-created', result)
			} catch (e) {
				this.error =
					e && e.message
						? e.message
						: t('shillinq', 'Failed to draft invoice')
			} finally {
				this.busy = false
			}
		},

		async onPreviewPdf() {
			if (this.draftId === null) {
				await this.onSaveDraft()
			}
			if (this.draftId !== null) {
				await invoiceApi.exportPdf(this.draftId)
				this.$emit('pdf-previewed', this.draftId)
			}
		},

		async onPost() {
			if (this.draftId === null) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const result = await invoiceApi.post(this.draftId)
				this.$emit('posted', result)
			} catch (e) {
				this.error =
					e && e.message
						? e.message
						: t('shillinq', 'Failed to post invoice')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.invoice-generator__row {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.invoice-generator__row label {
	display: flex;
	flex-direction: column;
	min-width: 200px;
}

.invoice-generator__row--wide {
	flex: 1 1 100%;
}

.invoice-generator__usage {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.invoice-generator__totals {
	background: var(--color-background-hover, #f4f4f4);
	padding: 12px;
	margin: 12px 0;
}

.invoice-generator__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.invoice-generator__error {
	color: var(--color-error, #d40000);
	margin-top: 8px;
}
</style>
