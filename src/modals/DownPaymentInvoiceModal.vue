<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DownPaymentInvoiceModal: invoice part of an order up front (sales-down-payments).

 The bookkeeper picks the customer and the order, and asks a percentage of
 the order or a fixed net amount. For an order shillinq holds, the server
 reads the order's lines; for an order typed in by hand (a quote in another
 app, for example) the dialog asks the order's net amount per VAT rate. It
 posts to POST /api/ar-invoices/down-payments, which creates a draft invoice
 of type 386; the server refuses a down payment above the order and says why.

 Opened with spawnDialog from the "New down-payment invoice" header action on
 Accounts Receivable (src/utils/downPaymentActions.js), so it lives in its
 own file (hydra gate-13).

 @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
-->

<template>
	<NcDialog
		:name="t('shillinq', 'New down-payment invoice')"
		size="normal"
		data-testid="down-payment-modal"
		@closing="close">
		<div class="dpm">
			<p class="dpm__hint">
				{{
					t(
						'shillinq',
						'The down payment is booked as an advance received. The final invoice of the order takes it off.',
					)
				}}
			</p>
			<NcSelect
				v-model="customer"
				:options="customerOptions"
				:loading="loading"
				:inputLabel="t('shillinq', 'Customer')"
				data-testid="down-payment-customer" />
			<NcSelect
				v-model="order"
				:options="orderOptions"
				:taggable="true"
				:createOption="typedOrder"
				:inputLabel="t('shillinq', 'Order')"
				:placeholder="t('shillinq', 'Choose an order or type its reference')"
				data-testid="down-payment-order" />

			<fieldset v-if="order && !order.shillinq" class="dpm__rates">
				<legend>{{ t('shillinq', 'Order net amount per VAT rate') }}</legend>
				<NcTextField
					v-for="rate in vatRates"
					:key="rate"
					v-model="rates[rate]"
					:label="t('shillinq', 'Net at {rate}%', { rate })"
					inputmode="decimal"
					:data-testid="'down-payment-rate-' + rate" />
			</fieldset>

			<div
				class="dpm__mode"
				role="radiogroup"
				:aria-label="t('shillinq', 'Down payment')">
				<NcCheckboxRadioSwitch
					v-model="mode"
					type="radio"
					value="percentage"
					name="down-payment-mode"
					data-testid="down-payment-mode-percentage">
					{{ t('shillinq', 'Percentage of the order') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="mode"
					type="radio"
					value="amount"
					name="down-payment-mode"
					data-testid="down-payment-mode-amount">
					{{ t('shillinq', 'Fixed net amount') }}
				</NcCheckboxRadioSwitch>
			</div>
			<NcTextField
				v-model="value"
				:label="
					mode === 'amount'
						? t('shillinq', 'Net amount (EUR)')
						: t('shillinq', 'Percentage')
				"
				inputmode="decimal"
				data-testid="down-payment-value" />
			<NcTextField
				v-model="invoiceDate"
				type="date"
				:label="t('shillinq', 'Invoice date')"
				data-testid="down-payment-date" />

			<p
				v-if="error"
				class="dpm__error"
				role="alert"
				data-testid="down-payment-error">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit || submitting"
				data-testid="down-payment-submit"
				@click="submit">
				{{ t('shillinq', 'Create draft') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import {
	buildRaiseRequest,
	errorMessage,
	parseAmount,
	raiseDownPayment,
	VAT_RATES,
} from '../utils/downPaymentApi.js'

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
	name: 'DownPaymentInvoiceModal',
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
			customers: [],
			orders: [],
			customer: null,
			order: null,
			mode: 'percentage',
			value: '',
			invoiceDate: new Date().toISOString().slice(0, 10),
			rates: Object.fromEntries(VAT_RATES.map((rate) => [rate, ''])),
			vatRates: VAT_RATES,
			error: '',
			submitting: false,
		}
	},

	computed: {
		/**
		 * The administration's customers, by name.
		 *
		 * @return {Array<object>} Select options.
		 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
		 */
		customerOptions() {
			return this.customers.map((customer) => ({
				value: String(customer.id ?? customer['@self']?.id ?? ''),
				label: String(
					customer.legalName
						|| customer.tradeName
						|| customer.customerId
						|| customer.id,
				),
			}))
		},

		/**
		 * The administration's sales orders held in shillinq.
		 *
		 * @return {Array<object>} Select options.
		 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
		 */
		orderOptions() {
			return this.orders.map((order) => ({
				value: String(order.id ?? order['@self']?.id ?? ''),
				label: String(order.orderNumber || order.description || order.id),
				shillinq: true,
			}))
		},

		/**
		 * Whether the form holds enough to ask the server.
		 *
		 * @return {boolean} True when it does.
		 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
		 */
		canSubmit() {
			const value = parseAmount(this.value)
			return Boolean(
				this.customer && this.order && Number.isFinite(value) && value > 0,
			)
		},
	},

	/**
	 * Load the active administration, its customers and its sales orders.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	async mounted() {
		this.loading = true
		try {
			const context = await axios.get(
				generateUrl('/apps/shillinq/api/administrations/context'),
			)
			this.administrationId = String(
				context.data?.activeAdministrationId || '',
			)
			const params = { administrationId: this.administrationId, _limit: 500 }
			const [customers, orders] = await Promise.all([
				axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/CustomerMaster`,
					),
					{ params },
				),
				axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/OrderPrimitive`,
					),
					{ params: { ...params, orderType: 'sales' } },
				),
			])
			this.customers = rowsOf(customers)
			this.orders = rowsOf(orders)
		} catch (error) {
			this.error = errorMessage(
				error,
				t('shillinq', 'The customers and orders could not be loaded.'),
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * An order typed by hand: shillinq cannot read its totals.
		 *
		 * @param {string} text The typed reference.
		 * @return {object} The option.
		 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
		 */
		typedOrder(text) {
			return {
				value: String(text).trim(),
				label: String(text).trim(),
				shillinq: false,
			}
		},

		/**
		 * Create the draft and close.
		 *
		 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
		 */
		async submit() {
			this.error = ''
			this.submitting = true
			try {
				const invoice = await raiseDownPayment(
					buildRaiseRequest({
						administrationId: this.administrationId,
						customerId: this.customer.value,
						order: this.order,
						mode: this.mode,
						value: this.value,
						invoiceDate: this.invoiceDate,
						rates: this.rates,
					}),
				)
				showSuccess(
					t('shillinq', 'Draft down-payment invoice {number} created', {
						number: invoice.invoiceNumber,
					}),
				)
				this.$emit('close', invoice)
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The down payment could not be saved.'),
				)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Close without saving.
		 *
		 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
		 */
		close() {
			this.$emit('close', null)
		},
	},
}
</script>

<style scoped>
.dpm {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.dpm__rates {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: none;
	padding: 0;
}

.dpm__mode {
	display: flex;
	gap: 16px;
}

.dpm__error {
	color: var(--color-error-text);
}

.dpm__hint {
	color: var(--color-text-maxcontrast);
}
</style>
