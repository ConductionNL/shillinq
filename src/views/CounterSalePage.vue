<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 CounterSalePage: a quick sale at the counter, sized for a phone
 (receivables-provider-payouts REQ-RPPO-006, design D6).

 The assistant enters one or more lines (price including VAT, VAT per line)
 and, optionally, the customer. Take payment records the sale as a draft
 simplified invoice and sends the total to the payment app on the
 assistant's registered phone, or shows a QR code when the phone cannot take
 it (D5). The capture issues the invoice as paid; a failed payment cancels
 it on the server. Customer walked away cancels the draft at once, and
 nothing is booked. A custom page because it writes a sale and drives a
 payment, which no index, detail or form page type does.

 @spec openspec/changes/receivables-provider-payouts/specs/ar-invoice-payment-links/spec.md
-->

<template>
	<div class="csp" data-testid="counter-sale-page">
		<h2 class="csp__title">
			{{ t('shillinq', 'Counter sale') }}
		</h2>

		<form v-if="stage === 'edit'" class="csp__form" @submit.prevent="takePayment">
			<fieldset
				v-for="(line, index) in lines"
				:key="line.key"
				class="csp__line"
				:data-testid="'counter-sale-line-' + index">
				<legend class="csp__legend">
					{{ t('shillinq', 'Line {number}', { number: index + 1 }) }}
				</legend>
				<label class="csp__field">
					<span>{{ t('shillinq', 'Description') }}</span>
					<input
						v-model="line.description"
						type="text"
						maxlength="200"
						required
						:data-testid="'counter-sale-description-' + index">
				</label>
				<div class="csp__row">
					<label class="csp__field csp__field--small">
						<span>{{ t('shillinq', 'Quantity') }}</span>
						<input
							v-model.number="line.quantity"
							type="number"
							min="0.001"
							step="any"
							inputmode="decimal"
							required>
					</label>
					<label class="csp__field csp__field--small">
						<span>{{ t('shillinq', 'Price incl. VAT') }}</span>
						<input
							v-model.number="line.unitPrice"
							type="number"
							min="0"
							step="0.01"
							inputmode="decimal"
							required
							:data-testid="'counter-sale-price-' + index">
					</label>
					<label class="csp__field csp__field--small">
						<span>{{ t('shillinq', 'VAT') }}</span>
						<select v-model.number="line.vatRate" :data-testid="'counter-sale-vat-' + index">
							<option v-for="rate in vatRates" :key="rate" :value="rate">
								{{ rate }}%
							</option>
						</select>
					</label>
				</div>
				<NcButton
					v-if="lines.length > 1"
					variant="tertiary"
					:aria-label="t('shillinq', 'Remove line {number}', { number: index + 1 })"
					@click="removeLine(index)">
					{{ t('shillinq', 'Remove line') }}
				</NcButton>
			</fieldset>

			<NcButton data-testid="counter-sale-add-line" @click="addLine">
				{{ t('shillinq', 'Add line') }}
			</NcButton>

			<label class="csp__field">
				<span>{{ t('shillinq', 'Customer (optional)') }}</span>
				<select v-model="customerId" data-testid="counter-sale-customer">
					<option value="">
						{{ t('shillinq', 'Counter sales, no named customer') }}
					</option>
					<option v-for="customer in customers" :key="customer.id" :value="customer.id">
						{{ customer.legalName || customer.customerId || customer.id }}
					</option>
				</select>
			</label>

			<p class="csp__total" data-testid="counter-sale-total">
				{{ t('shillinq', 'Total {amount}', { amount: formatAmount(total) }) }}
			</p>

			<NcButton
				type="submit"
				variant="primary"
				:wide="true"
				:disabled="submitting || total <= 0 || !administrationId"
				data-testid="counter-sale-take-payment">
				{{ t('shillinq', 'Take payment') }}
			</NcButton>
		</form>

		<div v-else-if="stage === 'paying'" class="csp__paying">
			<p v-if="sale.mode === 'device'" data-testid="counter-sale-on-device">
				{{ t('shillinq', 'The payment of {amount} is on your phone. Once the customer has paid, the sale is issued as a paid invoice.', { amount: formatAmount(total) }) }}
			</p>
			<template v-else>
				<p data-testid="counter-sale-qr-intro">
					{{ t('shillinq', 'Your phone cannot take this payment. Let the customer scan this code to pay.') }}
				</p>
				<img
					v-if="qrImage"
					class="csp__qr"
					:src="qrImage"
					:alt="t('shillinq', 'QR code of the payment page')"
					data-testid="counter-sale-qr">
			</template>
			<NcButton :wide="true" data-testid="counter-sale-walked-away" :disabled="submitting" @click="walkedAway">
				{{ t('shillinq', 'Customer walked away') }}
			</NcButton>
			<NcButton variant="primary" :wide="true" data-testid="counter-sale-next" @click="reset">
				{{ t('shillinq', 'Next sale') }}
			</NcButton>
		</div>

		<div v-else class="csp__paying">
			<p data-testid="counter-sale-cancelled">
				{{ t('shillinq', 'The sale is cancelled. Nothing was booked.') }}
			</p>
			<NcButton variant="primary" :wide="true" @click="reset">
				{{ t('shillinq', 'Next sale') }}
			</NcButton>
		</div>

		<p v-if="!administrationId && loaded" class="csp__error" role="alert" data-testid="counter-sale-no-administration">
			{{ t('shillinq', 'Choose the administration you work in first.') }}
		</p>
		<p v-if="error" class="csp__error" role="alert" data-testid="counter-sale-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { fetchActiveAdministrationId } from '../api/administrationApi.js'
import {
	cancelCounterSale,
	checkoutQrImage,
	counterSaleTotal,
	refusalMessage,
	startCounterSale,
} from '../utils/pointOfSaleApi.js'

let nextKey = 1

/**
 * An empty line, at the standard VAT rate.
 *
 * @return {object}
 */
function emptyLine() {
	return { key: nextKey++, description: '', quantity: 1, unitPrice: null, vatRate: 21 }
}

export default {
	name: 'CounterSalePage',

	components: {
		NcButton,
	},

	data() {
		return {
			administrationId: '',
			loaded: false,
			customers: [],
			customerId: '',
			lines: [emptyLine()],
			vatRates: [21, 9, 0],
			stage: 'edit',
			sale: null,
			qrImage: '',
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * What the customer pays.
		 *
		 * @return {number}
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
		 */
		total() {
			return counterSaleTotal(this.lines)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * The active administration, and its customers for the optional picker.
		 *
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
		 */
		async load() {
			this.administrationId = await fetchActiveAdministrationId()
			this.loaded = true
			if (!this.administrationId) {
				return
			}
			try {
				const response = await axios.get(generateUrl('/apps/openregister/api/objects/shillinq/CustomerMaster'), {
					params: { administrationId: this.administrationId, _limit: 100 },
				})
				const rows = response?.data?.results ?? response?.data?.objects ?? []
				this.customers = (Array.isArray(rows) ? rows : [])
					.filter((row) => row?.id && row.customerId !== 'COUNTER-SALES')
			} catch {
				// The picker is optional: without it every sale goes to the counter-sales debtor.
				this.customers = []
			}
		},

		addLine() {
			this.lines.push(emptyLine())
		},

		/**
		 * Remove a line.
		 *
		 * @param {number} index The line's position.
		 */
		removeLine(index) {
			this.lines.splice(index, 1)
		},

		/**
		 * An amount in euros, as the shop shows it.
		 *
		 * @param {number} amount The amount.
		 * @return {string}
		 */
		formatAmount(amount) {
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(amount)
		},

		/**
		 * Record the sale and send its payment to the phone, or show the QR code.
		 *
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
		 */
		async takePayment() {
			this.error = ''
			this.submitting = true
			try {
				const sale = await startCounterSale({
					administrationId: this.administrationId,
					customerId: this.customerId,
					lines: this.lines.map((line) => ({
						description: String(line.description || '').trim(),
						quantity: Number(line.quantity),
						unitPrice: Number(line.unitPrice),
						vatRate: Number(line.vatRate),
					})),
				})
				this.qrImage = sale.mode === 'qr' ? await checkoutQrImage(sale.checkoutUrl) : ''
				this.sale = sale
				this.stage = 'paying'
			} catch (error) {
				this.error = refusalMessage(error, t('shillinq', 'The payment could not be started.'))
			} finally {
				this.submitting = false
			}
		},

		/**
		 * The customer walked away: cancel the draft, nothing is booked.
		 *
		 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-3.2
		 */
		async walkedAway() {
			this.error = ''
			this.submitting = true
			try {
				await cancelCounterSale(this.sale?.invoice?.id)
				this.stage = 'cancelled'
			} catch (error) {
				this.error = refusalMessage(error, t('shillinq', 'The sale could not be cancelled.'))
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Start the next sale with an empty form.
		 */
		reset() {
			this.lines = [emptyLine()]
			this.customerId = ''
			this.sale = null
			this.qrImage = ''
			this.error = ''
			this.stage = 'edit'
		},
	},
}
</script>

<style scoped>
.csp {
	max-width: 420px;
	margin: 0 auto;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.csp__title {
	margin: 0;
}

.csp__form,
.csp__paying {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.csp__line {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 8px 12px 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.csp__legend {
	padding: 0 4px;
	color: var(--color-text-maxcontrast);
}

.csp__row {
	display: flex;
	gap: 8px;
}

.csp__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.csp__field--small {
	flex: 1 1 0;
}

.csp__field input,
.csp__field select {
	width: 100%;
	min-height: var(--default-clickable-area);
}

.csp__total {
	font-size: 1.4em;
	font-weight: bold;
	margin: 0;
}

.csp__qr {
	width: 240px;
	height: 240px;
	align-self: center;
	background-color: var(--color-main-background);
}

.csp__error {
	color: var(--color-error-text);
	margin: 0;
}
</style>
