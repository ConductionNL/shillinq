<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 InvoiceGenerateWizard: Generate invoices from hours, costs and meter
 readings (board ShFactuurGenereren).

 Three steps in CnWizardDialog: Source (customer, project, period, billing
 model and what that model needs), Choose lines (the time entries, the
 expense claims passed on to the customer and, for usage billing, the rated
 meter readings, each with a checkbox), and Review (the draft the server
 made, with Post to sales invoices as the last button).

 An app component, not manifest config: a manifest `type: form` with
 `steps` renders fields of one schema, and the middle step here is a choice
 of rows from three other schemas, read for the customer and period chosen
 in the first step. Leaving Choose lines drafts the invoice
 (POST /api/v1/invoices/generate); finishing posts it.

 Opened with spawnDialog from the Generate invoices header action on Sales
 invoices (src/utils/invoiceGenerateWizard.js).

 @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
-->

<template>
	<CnWizardDialog
		ref="wizard"
		look="board"
		:dialogTitle="t('shillinq', 'Generate invoices')"
		:eyebrowContext="t('shillinq', 'Sales invoices')"
		:stepEyebrow="t('shillinq', '{context}, step {step} of {total}')"
		:steps="steps"
		:validate="validate"
		:cancelLabel="t('shillinq', 'Cancel')"
		:backLabel="t('shillinq', 'Back')"
		:nextLabel="t('shillinq', 'Next')"
		:submitLabel="t('shillinq', 'Post to sales invoices')"
		:closeLabel="t('shillinq', 'Close')"
		data-testid="invoice-generate-wizard"
		@submit="onSubmit"
		@close="$emit('close')">
		<template #step-source>
			<div class="igw__grid">
				<NcSelect
					v-model="customer"
					:options="customerOptions"
					:loading="loading"
					:inputLabel="t('shillinq', 'Customer')"
					data-testid="igw-customer" />
				<NcTextField
					v-model="source.projectId"
					:label="t('shillinq', 'Project (optional)')"
					data-testid="igw-project" />
				<NcTextField
					v-model="source.fromDate"
					type="date"
					:label="t('shillinq', 'From')"
					data-testid="igw-from" />
				<NcTextField
					v-model="source.toDate"
					type="date"
					:label="t('shillinq', 'To')"
					data-testid="igw-to" />
				<NcSelect
					v-model="model"
					:options="modelOptions"
					:clearable="false"
					:inputLabel="t('shillinq', 'Billing model')"
					data-testid="igw-model" />
				<NcSelect
					v-if="needsRateCard"
					v-model="rateCard"
					:options="rateCardOptions"
					:inputLabel="t('shillinq', 'Rate card')"
					data-testid="igw-rate-card" />
				<NcSelect
					v-if="needsRetainer"
					v-model="retainer"
					:options="retainerOptions"
					:inputLabel="t('shillinq', 'Retainer schedule')"
					data-testid="igw-retainer" />
				<NcTextField
					v-if="needsFixedFee"
					v-model="source.fixedFee"
					inputmode="decimal"
					:label="t('shillinq', 'Fixed fee (€)')"
					data-testid="igw-fixed-fee" />
				<NcTextField
					v-if="source.billingModel === 'milestone'"
					v-model="source.milestoneId"
					:label="t('shillinq', 'Milestone ID')"
					data-testid="igw-milestone" />
			</div>
			<p v-if="error" class="igw__error" role="alert">
				{{ error }}
			</p>
		</template>

		<template #step-lines>
			<p class="igw__source">
				<span class="igw__chip">{{ customer ? customer.label : '' }}</span>
				<span v-if="source.projectId" class="igw__chip">{{
					t('shillinq', 'Project {project}', { project: source.projectId })
				}}</span>
				<span class="igw__chip">{{ periodLabel }}</span>
			</p>
			<p v-if="linesLoading" class="igw__muted">
				{{ t('shillinq', 'Loading the lines …') }}
			</p>
			<template v-else>
				<section
					v-for="group in lineGroups"
					:key="group.key"
					class="igw__group">
					<h3 class="igw__group-title">
						{{ group.title }}
					</h3>
					<p v-if="group.rows.length === 0" class="igw__muted">
						{{ group.empty }}
					</p>
					<table v-else class="igw__table">
						<thead>
							<tr>
								<th class="igw__check" scope="col">
									<span class="hidden-visually">{{
										t('shillinq', 'Bill')
									}}</span>
								</th>
								<th scope="col">
									{{ group.label }}
								</th>
								<th class="igw__num" scope="col">
									{{ group.quantityLabel }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in group.rows" :key="row.id">
								<td class="igw__check">
									<NcCheckboxRadioSwitch
										:modelValue="
											chosen[group.key].includes(row.id)
										"
										:ariaLabel="row.title"
										@update:modelValue="
											toggle(group.key, row.id, $event)
										" />
								</td>
								<td>
									<span class="igw__title">{{ row.title }}</span>
									<span v-if="row.meta" class="igw__meta">{{
										row.meta
									}}</span>
								</td>
								<td class="igw__num">
									{{ row.quantity }}
								</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>
			<p class="igw__total">
				{{
					n(
						'shillinq',
						'%n line chosen, becomes one draft invoice',
						'%n lines chosen, become one draft invoice',
						count,
					)
				}}
			</p>
			<p v-if="error" class="igw__error" role="alert">
				{{ error }}
			</p>
		</template>

		<template #step-review>
			<dl v-if="draft" class="igw__summary">
				<dt>{{ t('shillinq', 'Invoice number') }}</dt>
				<dd>{{ draft.invoiceNumber || t('shillinq', 'On posting') }}</dd>
				<dt>{{ t('shillinq', 'Net amount') }}</dt>
				<dd>{{ money(draft.summary && draft.summary.netAmount) }}</dd>
				<dt>{{ t('shillinq', 'VAT') }}</dt>
				<dd>{{ money(draft.summary && draft.summary.vatAmount) }}</dd>
				<dt>{{ t('shillinq', 'Gross amount') }}</dt>
				<dd class="igw__strong">
					{{ money(draft.summary && draft.summary.grossAmount) }}
				</dd>
				<dt>{{ t('shillinq', 'Due date') }}</dt>
				<dd>{{ draft.dueDate }}</dd>
			</dl>
			<p class="igw__muted">
				{{
					t(
						'shillinq',
						'The draft is saved. Post it to send it to sales invoices, or close and post it later.',
					)
				}}
			</p>
		</template>
	</CnWizardDialog>
</template>

<script>
import { CnWizardDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	BILLING_MODELS,
	buildGenerateRequest,
	chosenCount,
	draftInvoice,
	errorText,
	idOf,
	loadCandidates,
	postInvoice,
} from '../utils/invoiceGenerateWizard.js'

const REGISTER_SLUG = 'shillinq'

/**
 * Rows out of an OpenRegister list response.
 *
 * @param {object} response The axios response.
 * @return {Array<object>} The rows.
 */
function rowsOf(response) {
	const rows =
		response?.data?.results ?? response?.data?.objects ?? response?.data ?? []
	return Array.isArray(rows) ? rows : []
}

export default {
	name: 'InvoiceGenerateWizard',
	components: {
		CnWizardDialog,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
	},

	emits: ['close'],
	data() {
		return {
			loading: false,
			linesLoading: false,
			error: '',
			customers: [],
			rateCards: [],
			retainers: [],
			customer: null,
			rateCard: null,
			retainer: null,
			source: {
				billingModel: 't_and_m',
				projectId: '',
				fromDate: '',
				toDate: '',
				fixedFee: '',
				milestoneId: '',
			},

			candidates: { timeEntries: [], expenses: [], readings: [] },
			chosen: { timeEntryIds: [], expenseIds: [], meterReadingIds: [] },
			draft: null,
		}
	},

	computed: {
		steps() {
			return [
				{ id: 'source', label: t('shillinq', 'Source') },
				{ id: 'lines', label: t('shillinq', 'Choose lines') },
				{ id: 'review', label: t('shillinq', 'Review') },
			]
		},

		modelLabels() {
			return {
				t_and_m: t('shillinq', 'Time & Materials'),
				fixed_fee: t('shillinq', 'Fixed fee'),
				milestone: t('shillinq', 'Milestone'),
				retainer: t('shillinq', 'Retainer'),
				mixed: t('shillinq', 'Mixed'),
				usage: t('shillinq', 'Usage'),
			}
		},

		modelOptions() {
			return BILLING_MODELS.map((value) => ({
				value,
				label: this.modelLabels[value],
			}))
		},

		model: {
			get() {
				return (
					this.modelOptions.find(
						(o) => o.value === this.source.billingModel,
					) || null
				)
			},

			set(option) {
				this.source.billingModel = option ? option.value : 't_and_m'
			},
		},

		customerOptions() {
			return this.customers.map((c) => ({
				value: idOf(c),
				label: String(c.legalName || c.tradeName || c.customerId || idOf(c)),
			}))
		},

		rateCardOptions() {
			return this.rateCards.map((c) => ({
				value: idOf(c),
				label: [
					c.level,
					c.hourlyRate !== undefined ? this.money(c.hourlyRate) : '',
				]
					.filter(Boolean)
					.join(', '),
			}))
		},

		retainerOptions() {
			return this.retainers.map((r) => ({
				value: idOf(r),
				label: String(r.label || r.scheduleId || idOf(r)),
			}))
		},

		needsRateCard() {
			return ['t_and_m', 'mixed'].includes(this.source.billingModel)
		},

		needsRetainer() {
			return ['retainer', 'mixed'].includes(this.source.billingModel)
		},

		needsFixedFee() {
			return ['fixed_fee', 'mixed'].includes(this.source.billingModel)
		},

		periodLabel() {
			return t('shillinq', 'Period {from} to {to}', {
				from: this.source.fromDate,
				to: this.source.toDate,
			})
		},

		lineGroups() {
			const noCosts = t(
				'shillinq',
				'No costs passed on to this customer in this period.',
			)
			const noReadings = t(
				'shillinq',
				'This customer has no rated readings in this period that are not on an invoice yet.',
			)
			const groups = [
				{
					key: 'timeEntryIds',
					title: t('shillinq', 'Hours'),
					label: t('shillinq', 'Description'),
					quantityLabel: t('shillinq', 'Hours'),
					empty: t('shillinq', 'No hours in this period.'),
					rows: this.candidates.timeEntries.map((e) => ({
						id: idOf(e),
						title: String(e.description || e.personId || ''),
						meta: [e.personId, e.date].filter(Boolean).join(' · '),
						quantity: Number(e.hours || 0).toLocaleString(undefined, {
							minimumFractionDigits: 1,
						}),
					})),
				},
				{
					key: 'expenseIds',
					title: t('shillinq', 'Costs passed on'),
					label: t('shillinq', 'Costs'),
					quantityLabel: t('shillinq', 'Amount'),
					empty: noCosts,
					rows: this.candidates.expenses.map((c) => ({
						id: idOf(c),
						title: String(c.description || c.claimNumber || ''),
						meta: [c.claimNumber, c.submittedDate]
							.filter(Boolean)
							.join(' · '),
						quantity: this.money(
							c.totalPassThroughAmount ?? c.totalAmount,
						),
					})),
				},
			]
			if (this.source.billingModel === 'usage') {
				groups.push({
					key: 'meterReadingIds',
					title: t('shillinq', 'Meter readings'),
					label: t('shillinq', 'Meter'),
					quantityLabel: t('shillinq', 'Usage'),
					empty: noReadings,
					rows: this.candidates.readings.map((r) => ({
						id: idOf(r),
						title: String(r.description || r.meterId || ''),
						meta: [r.periodStart, r.periodEnd]
							.filter(Boolean)
							.join(' to '),
						quantity: [r.quantity, r.unit]
							.filter((v) => v !== undefined && v !== '')
							.join(' '),
					})),
				})
			}
			return groups
		},

		count() {
			return chosenCount(this.chosen)
		},
	},

	watch: {
		customer(option) {
			this.source.customerId = option ? option.value : ''
		},

		rateCard(option) {
			this.source.rateCardId = option ? option.value : ''
		},

		retainer(option) {
			this.source.retainerScheduleId = option ? option.value : ''
		},
	},

	/**
	 * Load the active administration's customers, rate cards and retainers.
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	async mounted() {
		this.loading = true
		try {
			const context = await axios.get(
				generateUrl('/apps/shillinq/api/administrations/context'),
			)
			const administrationId = String(
				context.data?.activeAdministrationId || '',
			)
			const list = (schema) =>
				axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${schema}`,
					),
					{
						params: { administrationId, _limit: 500 },
					},
				)
			const [customers, rateCards, retainers] = await Promise.all([
				list('CustomerMaster'),
				list('RateCard'),
				list('RetainerSchedule'),
			])
			this.customers = rowsOf(customers)
			this.rateCards = rowsOf(rateCards)
			this.retainers = rowsOf(retainers)
		} catch (error) {
			this.error = errorText(
				error,
				t('shillinq', 'The customers could not be loaded.'),
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,
		n,
		money(value) {
			const amount = Number(value)
			if (!Number.isFinite(amount)) {
				return '—'
			}
			return amount.toLocaleString(undefined, {
				style: 'currency',
				currency: 'EUR',
			})
		},

		toggle(key, id, on) {
			const list = this.chosen[key].filter((x) => x !== id)
			this.chosen[key] = on ? [...list, id] : list
		},

		/**
		 * Check a step before moving on. Leaving Source loads the lines with
		 * all of them chosen; leaving Choose lines drafts the invoice.
		 *
		 * @param {string} stepId The step being left.
		 * @return {Promise<boolean|string>} True to go on, or the reason not to.
		 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
		 */
		async validate(stepId) {
			this.error = ''
			if (stepId === 'source') {
				if (
					!this.source.customerId
					|| !this.source.fromDate
					|| !this.source.toDate
				) {
					return t('shillinq', 'Choose a customer and a period first.')
				}
				if (this.source.fromDate > this.source.toDate) {
					return t('shillinq', 'The period ends before it starts.')
				}
				this.linesLoading = true
				try {
					this.candidates = await loadCandidates(this.source)
					this.chosen = {
						timeEntryIds: this.candidates.timeEntries.map(idOf),
						expenseIds: this.candidates.expenses.map(idOf),
						meterReadingIds: this.candidates.readings.map(idOf),
					}
				} catch (error) {
					return errorText(
						error,
						t('shillinq', 'The lines could not be loaded.'),
					)
				} finally {
					this.linesLoading = false
				}
				return true
			}
			if (stepId === 'lines') {
				try {
					this.draft = await draftInvoice(
						buildGenerateRequest(this.source, this.chosen),
					)
				} catch (error) {
					return errorText(error, t('shillinq', 'Failed to draft invoice'))
				}
				return true
			}
			return true
		},

		/**
		 * Post the draft to sales invoices and show the result.
		 *
		 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
		 */
		async onSubmit() {
			const id = this.draft?.id || this.draft?.invoiceId || ''
			try {
				await postInvoice(id)
				showSuccess(t('shillinq', 'Invoice posted to sales invoices'))
				this.$refs.wizard.setResult({
					success: true,
					message: t('shillinq', 'Invoice posted to sales invoices'),
				})
			} catch (error) {
				this.$refs.wizard.setResult({
					error: errorText(
						error,
						t('shillinq', 'The invoice could not be posted.'),
					),
				})
			}
		},
	},
}
</script>

<style scoped>
.igw__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px 16px;
}

.igw__source {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0 0 16px;
}

.igw__chip {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.igw__group {
	margin-bottom: 16px;
}

.igw__group-title {
	margin: 0 0 8px;
	font-size: 1em;
	font-weight: 700;
}

.igw__table {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	border-collapse: separate;
	border-spacing: 0;
}

.igw__table th,
.igw__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.igw__table tr:last-child td {
	border-bottom: none;
}

.igw__check {
	width: 44px;
}

.igw__num {
	text-align: end !important;
	white-space: nowrap;
}

.igw__title {
	display: block;
	font-weight: 600;
}

.igw__meta,
.igw__muted {
	color: var(--color-text-maxcontrast);
}

.igw__meta {
	display: block;
	font-size: 0.9em;
}

.igw__total {
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.igw__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 24px;
	margin: 0 0 16px;
}

.igw__summary dt {
	color: var(--color-text-maxcontrast);
}

.igw__strong {
	font-weight: 700;
}

.igw__error {
	color: var(--color-error-text);
}
</style>
