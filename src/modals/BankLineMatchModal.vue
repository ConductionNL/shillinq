<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BankLineMatchModal: match one bank line by hand (banking-manual-match).

 Tab "Invoices" lists the open sales invoices for money in, or the open AP
 transactions for money out, of the line's administration, exact amounts
 first, searchable by number, counterparty and amount. Tab "Ledger account"
 books the line to an account, with the VAT split out when a rate and a VAT
 account are chosen. Tab "Payment plan", for money in, lists the active
 payment plans the line can pay (receivables-payment-plans REQ-RPPL-003):
 the plan whose reference the remittance names, or whose next instalment
 and customer account fit; confirming pays the plan. Both post to POST /api/v1/bank-lines/{lineId}/match;
 the server refuses a selection larger than the line and says why.

 Opened with spawnDialog from the "Match by hand" row actions
 (src/utils/bankMatchActions.js), so it lives in its own file (hydra gate-13).

 @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'Match by hand')"
		size="large"
		data-testid="bank-line-match-modal"
		@closing="close">
		<div class="blm">
			<p v-if="line" class="blm__line" data-testid="bank-line-match-summary">
				{{ lineSummary }}
			</p>
			<p v-else-if="loadError" class="blm__error" role="alert">
				{{ loadError }}
			</p>

			<div class="blm__tabs" role="tablist">
				<NcButton
					role="tab"
					:aria-selected="tab === 'invoices' ? 'true' : 'false'"
					:variant="tab === 'invoices' ? 'primary' : 'secondary'"
					data-testid="bank-line-match-tab-invoices"
					@click="tab = 'invoices'">
					{{ t('shillinq', 'Invoices') }}
				</NcButton>
				<NcButton
					role="tab"
					:aria-selected="tab === 'ledger' ? 'true' : 'false'"
					:variant="tab === 'ledger' ? 'primary' : 'secondary'"
					data-testid="bank-line-match-tab-ledger"
					@click="tab = 'ledger'">
					{{ t('shillinq', 'Ledger account') }}
				</NcButton>
				<NcButton
					v-if="isCredit"
					role="tab"
					:aria-selected="tab === 'plan' ? 'true' : 'false'"
					:variant="tab === 'plan' ? 'primary' : 'secondary'"
					data-testid="bank-line-match-tab-plan"
					@click="tab = 'plan'">
					{{ t('shillinq', 'Payment plan') }}
				</NcButton>
			</div>

			<section v-if="tab === 'invoices'" role="tabpanel">
				<NcTextField
					v-model="search"
					:label="
						t('shillinq', 'Search by number, counterparty or amount')
					"
					data-testid="bank-line-match-search" />
				<p v-if="candidates.length === 0" class="blm__empty">
					{{ t('shillinq', 'No open invoices found.') }}
				</p>
				<ul v-else class="blm__list">
					<li v-for="invoice in candidates" :key="invoice.id">
						<NcCheckboxRadioSwitch
							:modelValue="selected.includes(invoice.id)"
							:data-testid="
								'bank-line-match-invoice-' + invoice.number
							"
							@update:modelValue="toggle(invoice.id)">
							{{ invoice.number }} · {{ invoice.counterparty }} ·
							{{ money(invoice.amount) }}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
				<p class="blm__total" data-testid="bank-line-match-selected-total">
					{{
						t('shillinq', 'Selected: {amount}', {
							amount: money(selectedTotal),
						})
					}}
				</p>
			</section>

			<section v-else-if="tab === 'plan'" role="tabpanel">
				<p v-if="plans.length === 0" class="blm__empty">
					{{ t('shillinq', 'No active payment plan fits this line.') }}
				</p>
				<ul v-else class="blm__list">
					<li v-for="plan in plans" :key="plan.planId">
						<NcCheckboxRadioSwitch
							:modelValue="planId"
							:value="plan.planId"
							name="bank-line-plan"
							type="radio"
							:data-testid="'bank-line-match-plan-' + plan.planNumber"
							@update:modelValue="planId = plan.planId">
							{{ plan.planNumber }} · {{ plan.customerName }} ·
							{{ money(plan.nextDueAmount) }} ·
							{{
								plan.confidence === 'high'
									? t('shillinq', 'Reference found')
									: t('shillinq', 'Amount and account fit')
							}}
						</NcCheckboxRadioSwitch>
					</li>
				</ul>
			</section>

			<section v-else role="tabpanel">
				<NcSelect
					v-model="account"
					:options="accountOptions"
					:inputLabel="t('shillinq', 'Ledger account')"
					data-testid="bank-line-match-account" />
				<NcSelect
					v-model="vatRate"
					:options="vatOptions"
					:inputLabel="t('shillinq', 'VAT rate')"
					data-testid="bank-line-match-vat-rate" />
				<NcSelect
					v-if="vatRate && vatRate.value > 0"
					v-model="vatAccount"
					:options="accountOptions"
					:inputLabel="t('shillinq', 'VAT account')"
					data-testid="bank-line-match-vat-account" />
				<NcTextField
					v-model="description"
					:label="t('shillinq', 'Description')"
					data-testid="bank-line-match-description" />
			</section>

			<p
				v-if="error"
				class="blm__error"
				role="alert"
				data-testid="bank-line-match-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canConfirm || submitting"
				data-testid="bank-line-match-confirm"
				@click="confirm">
				{{ t('shillinq', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { payPlanFromLine, planCandidates } from '../utils/paymentPlanApi.js'

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
	name: 'BankLineMatchModal',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The line's uuid, or its `lineId` business key. */
		lineId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
	data() {
		return {
			tab: 'invoices',
			line: null,
			loadError: '',
			invoices: [],
			accounts: [],
			search: '',
			selected: [],
			account: null,
			vatRate: null,
			vatAccount: null,
			description: '',
			error: '',
			submitting: false,
			plans: [],
			planId: '',
		}
	},

	computed: {
		/**
		 * Whether the line brings money in.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		isCredit() {
			return Number(this.line?.amount ?? 0) >= 0
		},

		/**
		 * One line describing the bank line.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		lineSummary() {
			if (!this.line) {
				return ''
			}
			return [
				this.line.valueDate,
				this.line.counterpartyName,
				this.money(this.line.amount),
				this.line.remittanceInfo || this.line.reference || '',
			]
				.filter(Boolean)
				.join(' · ')
		},

		/**
		 * Open invoices matching the search, exact amounts first.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		candidates() {
			const lineAmount = Math.abs(Number(this.line?.amount ?? 0))
			const needle = this.search.trim().toLowerCase()
			return this.invoices
				.filter(
					(invoice) =>
						!needle
						|| invoice.number.toLowerCase().includes(needle)
						|| invoice.counterparty.toLowerCase().includes(needle)
						|| String(invoice.amount).includes(needle),
				)
				.sort((a, b) => {
					const exactA = Math.abs(a.amount - lineAmount) < 0.005 ? 0 : 1
					const exactB = Math.abs(b.amount - lineAmount) < 0.005 ? 0 : 1
					return exactA - exactB || a.number.localeCompare(b.number)
				})
		},

		/**
		 * Sum of the selected invoices.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		selectedTotal() {
			return this.invoices
				.filter((invoice) => this.selected.includes(invoice.id))
				.reduce((sum, invoice) => sum + invoice.amount, 0)
		},

		/**
		 * Ledger accounts as select options.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		accountOptions() {
			return this.accounts.map((account) => ({
				value: account.accountNumber,
				label: `${account.accountNumber} ${account.name || ''}`.trim(),
			}))
		},

		/**
		 * The VAT rates on offer.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		vatOptions() {
			return [0, 9, 21].map((rate) => ({ value: rate, label: `${rate}%` }))
		},

		/**
		 * Whether the current tab has what the match needs.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		canConfirm() {
			if (!this.line) {
				return false
			}
			if (this.tab === 'invoices') {
				return this.selected.length > 0
			}
			if (this.tab === 'plan') {
				return this.planId !== ''
			}
			if (!this.account) {
				return false
			}
			return !(this.vatRate && this.vatRate.value > 0 && !this.vatAccount)
		},
	},

	/**
	 * Load the line, then its candidate invoices and accounts.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	async mounted() {
		await this.loadLine()
		if (this.line) {
			await Promise.all([
				this.loadInvoices(),
				this.loadAccounts(),
				this.loadPlans(),
			])
		}
	},

	methods: {
		t,

		/**
		 * Format an amount as EUR with two decimals.
		 *
		 * @param {number|string} amount The amount.
		 * @return {string} The formatted amount.
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		money(amount) {
			return `EUR ${Math.abs(Number(amount || 0)).toFixed(2)}`
		},

		/**
		 * Select or deselect one invoice.
		 *
		 * @param {string} id The invoice id.
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		toggle(id) {
			this.selected = this.selected.includes(id)
				? this.selected.filter((existing) => existing !== id)
				: [...this.selected, id]
		},

		/**
		 * Read the line by uuid, falling back to its lineId.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		async loadLine() {
			const base = `/apps/openregister/api/objects/${REGISTER_SLUG}/BankStatementLine`
			try {
				const byId = await axios.get(
					generateUrl(`${base}/${encodeURIComponent(this.lineId)}`),
				)
				this.line = byId.data
			} catch {
				try {
					const byKey = await axios.get(generateUrl(base), {
						params: { lineId: this.lineId, _limit: 1 },
					})
					this.line = rowsOf(byKey)[0] ?? null
				} catch {
					this.line = null
				}
			}
			if (!this.line) {
				this.loadError = t('shillinq', 'Bank line not found')
			}
		},

		/**
		 * Read the open invoices of the line administration.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		async loadInvoices() {
			const schema = this.isCredit ? 'ARInvoice' : 'APTransaction'
			const stateField = this.isCredit ? 'lifecycleState' : 'state'
			const open = this.isCredit
				? ['issued', 'overdue']
				: ['issued', 'overdue', 'partially-paid']
			const response = await axios.get(
				generateUrl(
					`/apps/openregister/api/objects/${REGISTER_SLUG}/${schema}`,
				),
				{
					params: {
						administrationId: this.line.administrationId,
						_limit: 500,
					},
				},
			)
			this.invoices = rowsOf(response)
				.filter((row) => open.includes(row[stateField]))
				.map((row) => ({
					id: row.id,
					number: String(row.invoiceNumber || row.id),
					counterparty: String(
						row.buyerName || row.customerId || row.vendorId || '',
					),
					amount: Number(
						this.isCredit
							? (row.amountDue ?? row.grossAmount ?? 0)
							: (row.totalAmount ?? 0),
					),
				}))
		},

		/**
		 * Read the ledger accounts of the line administration.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		async loadAccounts() {
			const response = await axios.get(
				generateUrl(
					`/apps/openregister/api/objects/${REGISTER_SLUG}/Account`,
				),
				{
					params: {
						administrationId: this.line.administrationId,
						_limit: 1000,
					},
				},
			)
			this.accounts = rowsOf(response)
		},

		/**
		 * Post the selection or the ledger booking and report the answer.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		/**
		 * Load the active payment plans this line can pay.
		 *
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
		 */
		async loadPlans() {
			if (!this.isCredit) {
				return
			}
			try {
				this.plans = await planCandidates(this.line.id)
				if (this.plans.length > 0 && this.plans[0].confidence === 'high') {
					this.planId = this.plans[0].planId
				}
			} catch {
				// No plan tab content is not an error for the other tabs.
				this.plans = []
			}
		},

		/**
		 * Pay the chosen payment plan from the line.
		 *
		 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
		 */
		async confirmPlan() {
			try {
				const match = await payPlanFromLine(this.planId, this.line.id)
				showSuccess(t('shillinq', 'Bank line paid to the payment plan.'))
				emit('cn:page:refresh', {})
				this.$emit('close', match)
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('shillinq', 'The bank line could not be matched.')
			} finally {
				this.submitting = false
			}
		},

		async confirm() {
			this.submitting = true
			this.error = ''
			if (this.tab === 'plan') {
				await this.confirmPlan()
				return
			}
			const body =
				this.tab === 'invoices'
					? { targets: this.selected }
					: {
							ledgerAccount: {
								accountNumber: this.account?.value,
								vatRate: this.vatRate?.value ?? 0,
								vatAccountNumber: this.vatAccount?.value ?? '',
								description: this.description,
							},
						}
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/shillinq/api/v1/bank-lines/${encodeURIComponent(this.line.id)}/match`,
					),
					body,
				)
				const remainder = Number(response.data?.remainder ?? 0)
				showSuccess(
					remainder > 0
						? t(
								'shillinq',
								'Part payment recorded, {amount} remains open.',
								{ amount: this.money(remainder) },
							)
						: t('shillinq', 'Bank line matched.'),
				)
				emit('cn:page:refresh', {})
				this.$emit('close', response.data)
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('shillinq', 'The bank line could not be matched.')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without matching.
		 *
		 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
		 */
		close() {
			this.$emit('close', null)
		},
	},
}
</script>

<style scoped>
.blm {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.blm__tabs {
	display: flex;
	gap: 8px;
}

.blm__list {
	max-height: 320px;
	overflow-y: auto;
}

.blm__error {
	color: var(--color-error-text);
}

.blm__empty,
.blm__total,
.blm__line {
	color: var(--color-text-maxcontrast);
}
</style>
