<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  Shillinq Mobile invoice draft

  Draft an invoice on the phone: customer, date and lines with VAT, as the
  desktop quick draft takes them. The draft is queued on the phone and sent
  at once when there is a connection, else when it returns. Issuing happens
  on the home screen, once the draft is synced and only online: an issued
  invoice takes the next number of a sequence only the server holds.

  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
  @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-an-invoice-is-issued-from-the-phone-only-online-req-pmp-004
-->
<template>
	<section class="mobile-form">
		<h1>{{ t('shillinq', 'Draft an invoice') }}</h1>

		<p v-if="customers.length === 0" class="mobile-form__hint" role="note">
			{{ t('shillinq', 'No customers are known on this phone yet. Open this screen once with a connection.') }}
		</p>

		<form class="mobile-form__fields" @submit.prevent="save">
			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Customer') }}</span>
				<select v-model="form.customerId" required data-testid="mobile-invoice-customer">
					<option
						v-for="customer in customers"
						:key="customer.value"
						:value="customer.value">
						{{ customer.label }}
					</option>
				</select>
			</label>

			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Invoice date') }}</span>
				<input v-model="form.invoiceDate" type="date" required data-testid="mobile-invoice-date">
			</label>

			<label class="mobile-form__field">
				<span>{{ t('shillinq', 'Reference (optional)') }}</span>
				<input v-model="form.reference" type="text" data-testid="mobile-invoice-reference">
			</label>

			<fieldset
				v-for="(line, index) in form.lines"
				:key="index"
				class="mobile-form__line">
				<legend>{{ t('shillinq', 'Line {number}', { number: index + 1 }) }}</legend>
				<label class="mobile-form__field">
					<span>{{ t('shillinq', 'Description') }}</span>
					<input v-model="line.description" type="text">
				</label>
				<label class="mobile-form__field">
					<span>{{ t('shillinq', 'Quantity') }}</span>
					<input v-model.number="line.quantity" type="number" inputmode="decimal" min="0" step="any">
				</label>
				<label class="mobile-form__field">
					<span>{{ t('shillinq', 'Price excluding VAT (EUR)') }}</span>
					<input v-model.number="line.unitPrice" type="number" inputmode="decimal" min="0" step="0.01">
				</label>
				<label class="mobile-form__field">
					<span>{{ t('shillinq', 'VAT rate') }}</span>
					<select v-model.number="line.vatRate">
						<option v-for="rate in vatRates" :key="rate" :value="rate">
							{{ rate }}%
						</option>
					</select>
				</label>
				<NcButton
					v-if="form.lines.length > 1"
					variant="tertiary"
					@click="removeLine(index)">
					{{ t('shillinq', 'Remove line') }}
				</NcButton>
			</fieldset>

			<NcButton variant="secondary" @click="addLine">
				{{ t('shillinq', 'Add line') }}
			</NcButton>

			<div class="mobile-form__buttons">
				<NcButton variant="secondary" @click="back">
					{{ t('shillinq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="!complete || saving"
					data-testid="mobile-invoice-save">
					{{ t('shillinq', 'Save draft') }}
				</NcButton>
			</div>
		</form>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import { enqueue, isOnline, QUEUE_KINDS, syncQueue } from '../../composables/useOfflineQueue.js'
import { defaultDraftLine } from '../../modals/invoiceQuickDraft.js'
import {
	buildInvoicePayload,
	invoiceComplete,
	loadCustomers,
	mobileQueue,
	today,
	VAT_RATES,
} from './mobileApp.js'

export default {
	name: 'MobileInvoice',

	components: { NcButton },

	data() {
		return {
			form: { customerId: '', invoiceDate: today(), reference: '', lines: [defaultDraftLine()] },
			customers: [],
			vatRates: VAT_RATES,
			saving: false,
		}
	},

	computed: {
		complete() {
			return invoiceComplete(this.form)
		},
	},

	async mounted() {
		this.customers = await loadCustomers({ online: isOnline() })
	},

	methods: {
		t,

		/** @return {void} */
		addLine() {
			this.form.lines.push(defaultDraftLine())
		},

		/**
		 * @param {number} index The line to remove.
		 * @return {void}
		 */
		removeLine(index) {
			if (this.form.lines.length > 1) {
				this.form.lines.splice(index, 1)
			}
		},

		/**
		 * Queue the draft, send it now when online, and go back home.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
		 */
		async save() {
			if (!this.complete || this.saving) {
				return
			}
			this.saving = true
			try {
				const queue = await mobileQueue()
				await enqueue(queue.store, { kind: QUEUE_KINDS.INVOICE_DRAFT, payload: buildInvoicePayload(this.form) })
				if (isOnline()) {
					await syncQueue(queue.store, queue.transport)
				}
				this.back()
			} finally {
				this.saving = false
			}
		},

		/** @return {void} */
		back() {
			this.$router.push('/mobile')
		},
	},
}
</script>

<style scoped>
.mobile-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	max-width: 640px;
	margin: 0 auto;
}

.mobile-form__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.mobile-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-weight: bold;
}

.mobile-form__field input,
.mobile-form__field select {
	min-height: 44px;
	font-weight: normal;
}

.mobile-form__line {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.mobile-form__hint {
	color: var(--color-text-maxcontrast);
}

.mobile-form__buttons {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}
</style>
