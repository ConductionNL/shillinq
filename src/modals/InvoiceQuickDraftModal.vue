<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 InvoiceQuickDraftModal — shillinq-invoice-quick-draft.

 A compact single-screen NcDialog launched from the Financial overview
 dashboard's "Create invoice" action — a declarative `config.headerActions[]`
 open-modal action (ADR-049 Phase-4) targeting this component's registry id.
 It covers the common case — a one-or-few-line draft invoice for a known
 customer — without sending the user to the full AR index + multi-step
 form. On save it creates an ARInvoice in state `draft` directly through
 the OpenRegister object API (ADR-022: invoices are OR objects; no
 app-local CRUD controller) and emits `cn:widget:refresh` so the
 dashboard receivables widget reloads.

 Modal isolation (hydra gate-13): this dialog lives in its own .vue file
 under src/modals/ and is registered as a kind:"modal" so the dashboard
 headerAction's open-modal `target` resolves it. This component owns its
 own form state, customer/GL lookups, the POST and the toast.

 @spec openspec/changes/shillinq-invoice-quick-draft/proposal.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Quick draft invoice')"
		size="normal"
		data-testid="invoice-quick-draft-modal"
		@closing="onClose">
		<div class="iqd">
			<!-- Customer -->
			<div class="iqd__field">
				<NcSelect
					:modelValue="selectedCustomer"
					:options="customerOptions"
					:loading="loadingCustomers"
					:inputLabel="t('shillinq', 'Customer')"
					:placeholder="t('shillinq', 'Search customer by name…')"
					:filterable="true"
					label="display"
					trackBy="value"
					data-testid="iqd-customer"
					@option:selected="onCustomerSelected"
					@update:modelValue="onCustomerSelected" />
			</div>

			<!-- Dates -->
			<div class="iqd__row">
				<div class="iqd__field">
					<label class="iqd__label" for="iqd-invoice-date">
						{{ t('shillinq', 'Invoice date') }}
					</label>
					<input
						id="iqd-invoice-date"
						v-model="form.invoiceDate"
						type="date"
						class="iqd__input"
						data-testid="iqd-invoice-date"
						@change="recomputeDueDate" />
				</div>
				<div class="iqd__field">
					<label class="iqd__label" for="iqd-due-date">
						{{ t('shillinq', 'Due date') }}
					</label>
					<input
						id="iqd-due-date"
						v-model="form.dueDate"
						type="date"
						class="iqd__input"
						data-testid="iqd-due-date" />
				</div>
			</div>

			<!-- Reference -->
			<div class="iqd__field">
				<label class="iqd__label" for="iqd-reference">
					{{ t('shillinq', 'Reference / PO number') }}
				</label>
				<input
					id="iqd-reference"
					v-model="form.reference"
					type="text"
					class="iqd__input"
					data-testid="iqd-reference"
					:placeholder="t('shillinq', 'Optional')" />
			</div>

			<!-- Line items -->
			<div class="iqd__lines">
				<div class="iqd__lines-header">
					<span class="iqd__label">{{ t('shillinq', 'Line items') }}</span>
				</div>
				<div
					v-for="(line, idx) in form.lines"
					:key="idx"
					class="iqd__line"
					data-testid="iqd-line">
					<input
						v-model="line.description"
						type="text"
						class="iqd__input iqd__input--desc"
						:aria-label="t('shillinq', 'Line description')"
						:placeholder="t('shillinq', 'Description')"
						data-testid="iqd-line-description" />
					<input
						v-model.number="line.quantity"
						type="number"
						min="0"
						step="0.01"
						class="iqd__input iqd__input--qty"
						:aria-label="t('shillinq', 'Line quantity')"
						:placeholder="t('shillinq', 'Qty')"
						data-testid="iqd-line-quantity" />
					<input
						v-model.number="line.unitPrice"
						type="number"
						min="0"
						step="0.01"
						class="iqd__input iqd__input--price"
						:aria-label="t('shillinq', 'Line unit price')"
						:placeholder="t('shillinq', 'Unit price')"
						data-testid="iqd-line-unit-price" />
					<NcSelect
						:modelValue="vatOptionFor(line)"
						:options="vatOptions"
						:inputLabel="t('shillinq', 'VAT')"
						:clearable="false"
						label="display"
						trackBy="value"
						class="iqd__input--vat"
						data-testid="iqd-line-vat"
						@update:modelValue="(o) => onVatSelected(line, o)" />
					<button
						type="button"
						class="iqd__line-remove"
						:disabled="form.lines.length <= 1"
						:aria-label="t('shillinq', 'Remove line')"
						data-testid="iqd-line-remove"
						@click="removeLine(idx)">
						×
					</button>
				</div>
				<button
					type="button"
					class="iqd__add-line"
					data-testid="iqd-add-line"
					@click="addLine">
					+ {{ t('shillinq', 'Add line') }}
				</button>
			</div>

			<!-- GL account default for the lines -->
			<div class="iqd__field">
				<GlAccountPicker
					v-model="form.glAccount"
					data-testid="iqd-gl-account" />
			</div>

			<!-- Totals -->
			<div class="iqd__totals" data-testid="iqd-totals">
				<span>{{ t('shillinq', 'Net') }}: {{ formatEuro(netAmount) }}</span>
				<span>{{ t('shillinq', 'VAT') }}: {{ formatEuro(vatAmount) }}</span>
				<strong
					>{{ t('shillinq', 'Total') }}:
					{{ formatEuro(grossAmount) }}</strong
				>
			</div>

			<p v-if="error" class="iqd__error" data-testid="iqd-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton :disabled="saving" data-testid="iqd-cancel" @click="onClose">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSave"
				data-testid="iqd-save"
				@click="onSave">
				{{ saving ? t('shillinq', 'Saving…') : t('shillinq', 'Save draft') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import GlAccountPicker from '../components/BudgetBBVMapping/GlAccountPicker.vue'
import {
	buildInvoicePayload,
	computeTotals,
	defaultDraftLine,
	dueDateFromTerms,
	loadQuickDraftPrefs,
	saveQuickDraftPrefs,
} from './invoiceQuickDraft.js'

const REGISTER_SLUG = 'shillinq'
const AR_SCHEMA = 'ARInvoice'
const CUSTOMER_SCHEMA = 'CustomerMaster'

export default {
	name: 'InvoiceQuickDraftModal',
	components: { NcDialog, NcButton, NcSelect, GlAccountPicker },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'created'],
	data() {
		return {
			loadingCustomers: false,
			customers: [],
			selectedCustomer: null,
			administrationId: '',
			saving: false,
			error: '',
			vatOptions: [
				{ value: 21, display: '21%' },
				{ value: 9, display: '9%' },
				{ value: 0, display: '0%' },
			],

			form: {
				invoiceDate: this.today(),
				dueDate: '',
				reference: '',
				glAccount: '',
				lines: [defaultDraftLine()],
			},
		}
	},

	computed: {
		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		customerOptions() {
			return this.customers.map((c) => ({
				value: String(c.customerId ?? c.id ?? ''),
				display: c.legalName || c.tradeName || c.customerId || c.id || '',
				customer: c,
			}))
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		netAmount() {
			return computeTotals(this.form.lines).net
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		vatAmount() {
			return computeTotals(this.form.lines).vat
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		grossAmount() {
			return computeTotals(this.form.lines).gross
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		canSave() {
			if (this.saving) return false
			if (!this.selectedCustomer || !this.selectedCustomer.value) return false
			return this.form.lines.some(
				(l) =>
					(l.description || '').trim().length > 0
					&& Number(l.unitPrice) > 0,
			)
		},
	},

	watch: {
		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		open: {
			// `immediate` so the customer list also loads when the modal is
			// mounted with `open` already true (the manifest open-modal action
			// passes props.open=true, so a false→true transition never fires).
			immediate: true,
			handler(next) {
				if (next === true) {
					this.reset()
					this.fetchCustomers()
				}
			},
		},
	},

	methods: {
		t,
		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		today() {
			return new Date().toISOString().slice(0, 10)
		},

		/**
		 * @param amount
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 */
		formatEuro(amount) {
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(amount) || 0)
		},

		/**
		 * @param line
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 */
		vatOptionFor(line) {
			return (
				this.vatOptions.find((o) => o.value === Number(line.vatRate))
				|| this.vatOptions[0]
			)
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		reset() {
			this.error = ''
			this.selectedCustomer = null
			this.form = {
				invoiceDate: this.today(),
				dueDate: '',
				reference: '',
				glAccount: '',
				lines: [defaultDraftLine()],
			}
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		async fetchCustomers() {
			this.loadingCustomers = true
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${CUSTOMER_SCHEMA}`,
					),
					{ params: { _limit: 500 } },
				)
				const rows =
					response.data?.results
					?? response.data?.objects
					?? response.data
					?? []
				this.customers = Array.isArray(rows) ? rows : []
			} catch (e) {
				this.customers = []
			} finally {
				this.loadingCustomers = false
			}
			this.fetchAdministrationId()
		},

		/**
		 * Load the configured administration id. The ARInvoice schema
		 * requires administrationId, so the quick draft sources it from the
		 * app settings rather than asking the operator for it.
		 *
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 * @return {Promise<void>}
		 */
		async fetchAdministrationId() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/shillinq/api/settings'),
				)
				this.administrationId =
					data?.administration_id ?? data?.administrationId ?? ''
			} catch (e) {
				this.administrationId = ''
			}
		},

		/**
		 * @param option
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 */
		onCustomerSelected(option) {
			this.selectedCustomer = option || null
			const customer = option?.customer
			if (!customer) return
			// Default GL account from the customer master, if present.
			if (customer.defaultGlAccount && !this.form.glAccount) {
				this.form.glAccount = String(customer.defaultGlAccount)
			}
			// Apply last-used per-customer preferences (REQ-IQD-004).
			const prefs = loadQuickDraftPrefs(option.value)
			if (prefs) {
				if (prefs.glAccount) this.form.glAccount = String(prefs.glAccount)
				const line = this.form.lines[0]
				if (line) {
					if (prefs.description && !line.description)
						line.description = prefs.description
					if (prefs.unitPrice && !line.unitPrice)
						line.unitPrice = prefs.unitPrice
					if (prefs.vatCode !== undefined && prefs.vatCode !== null)
						line.vatRate = prefs.vatCode
				}
			}
			this.recomputeDueDate(customer)
		},

		/**
		 * @param customerOrEvent
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 */
		recomputeDueDate(customerOrEvent) {
			const customer =
				customerOrEvent && customerOrEvent.customerId
					? customerOrEvent
					: this.selectedCustomer?.customer
			const terms = customer?.paymentTerms || 'net30'
			this.form.dueDate = dueDateFromTerms(this.form.invoiceDate, terms)
		},

		/**
		 * @param line
		 * @param option
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 */
		onVatSelected(line, option) {
			line.vatRate = option ? Number(option.value) : 21
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		addLine() {
			this.form.lines.push(defaultDraftLine())
		},

		/**
		 * @param idx
		 * @spec openspec/specs/shillinq-invoice-quick-draft/spec.md
		 */
		removeLine(idx) {
			if (this.form.lines.length <= 1) return
			this.form.lines.splice(idx, 1)
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		onClose() {
			if (this.saving) return
			this.$emit('close')
		},

		/** @spec openspec/specs/shillinq-invoice-quick-draft/spec.md */
		async onSave() {
			if (!this.canSave) return
			this.saving = true
			this.error = ''
			try {
				const payload = buildInvoicePayload({
					customerId: this.selectedCustomer.value,
					invoiceDate: this.form.invoiceDate,
					dueDate: this.form.dueDate,
					reference: this.form.reference,
					glAccount: this.form.glAccount,
					administrationId: this.administrationId,
					lines: this.form.lines,
				})
				const response = await axios.post(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${AR_SCHEMA}`,
					),
					payload,
				)
				const created = response.data?.object ?? response.data ?? {}
				const invoiceId = created.id ?? created.uuid ?? ''
				const invoiceNumber =
					created.invoiceNumber || payload.invoiceNumber || ''

				// Persist per-customer preferences for next time (REQ-IQD-004).
				const firstLine = this.form.lines[0] || {}
				saveQuickDraftPrefs(this.selectedCustomer.value, {
					glAccount: this.form.glAccount,
					vatCode: Number(firstLine.vatRate),
					description: firstLine.description,
					unitPrice: Number(firstLine.unitPrice),
				})

				showSuccess(
					t('shillinq', 'Draft invoice {number} created.', {
						number: invoiceNumber || invoiceId,
					}),
				)
				// REQ-IQD-005: refresh the receivables widget without navigation.
				emit('cn:widget:refresh', { widget: 'widget-open-debtors' })
				this.$emit('created', { id: invoiceId, invoiceNumber })
				this.$emit('close')
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| e?.response?.data?.message
					|| e?.message
					|| t('shillinq', 'Failed to create draft invoice.')
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.iqd {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 2px 8px;
	min-width: min(520px, 80vw);
}

.iqd__row {
	display: flex;
	gap: 12px;
}

.iqd__field {
	display: flex;
	flex-direction: column;
	flex: 1;
	gap: 4px;
}

.iqd__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.iqd__input {
	box-sizing: border-box;
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 6px 8px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-family: inherit;
}

.iqd__lines {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.iqd__line {
	display: flex;
	gap: 6px;
	align-items: center;
}

.iqd__input--desc {
	flex: 3;
}

.iqd__input--qty {
	flex: 1;
	max-width: 70px;
}

.iqd__input--price {
	flex: 1;
	max-width: 110px;
}

.iqd__input--vat {
	flex: 1;
	min-width: 90px;
}

.iqd__line-remove {
	border: none;
	background: transparent;
	color: var(--color-error);
	font-size: 20px;
	line-height: 1;
	cursor: pointer;
	padding: 4px 8px;
}

.iqd__line-remove:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.iqd__add-line {
	align-self: flex-start;
	border: 1px dashed var(--color-border);
	background: transparent;
	color: var(--color-primary-element);
	border-radius: var(--border-radius);
	padding: 4px 10px;
	cursor: pointer;
}

.iqd__totals {
	display: flex;
	gap: 16px;
	justify-content: flex-end;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

.iqd__error {
	color: var(--color-error);
	margin: 0;
}
</style>
