<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Service receipt entry (purchasing-order-dispatch-and-receipt, task 4.2,
 REQ-PODR-004). Opened from "Confirm service" on a purchase order with
 service lines (`?poId=`). The budget holder enters the period and, per
 service line, how much was delivered: a percentage complete, a quantity or
 an amount. Submitting creates the receipt, adds a line per entry and
 confirms it through the existing service-receipt endpoints, then opens the
 receipt. A custom page because each line picks one of three modes, which a
 single-object form cannot express.
-->
<template>
	<div class="service-receipt-form" data-testid="service-receipt-form">
		<h2>{{ t('shillinq', 'Confirm service') }}</h2>

		<p v-if="loading">
			{{ t('shillinq', 'Loading…') }}
		</p>
		<p v-else-if="error" class="service-receipt-form__error" data-testid="service-receipt-form-error">
			{{ error }}
		</p>

		<form v-if="!loading && purchaseOrder" @submit.prevent="submit">
			<p class="service-receipt-form__order" data-testid="service-receipt-form-order">
				{{ t('shillinq', 'Purchase order') }}: {{ purchaseOrder.poNumber || purchaseOrder.id }}
			</p>

			<div class="service-receipt-form__period">
				<NcTextField
					v-model="periodStart"
					type="date"
					:label="t('shillinq', 'Period start')"
					data-testid="service-receipt-form-period-start" />
				<NcTextField
					v-model="periodEnd"
					type="date"
					:label="t('shillinq', 'Period end')"
					data-testid="service-receipt-form-period-end" />
			</div>

			<p v-if="entries.length === 0" data-testid="service-receipt-form-no-lines">
				{{ t('shillinq', 'This purchase order has no service lines.') }}
			</p>

			<fieldset
				v-for="entry in entries"
				:key="entry.poLineId"
				class="service-receipt-form__line"
				:data-testid="`service-receipt-form-line-${entry.poLineId}`">
				<legend>
					{{ entry.description }} ({{ entry.quantityOrdered }} {{ entry.unitOfMeasure }})
				</legend>
				<NcSelect
					v-model="entry.mode"
					:options="modeOptions"
					:reduce="(option) => option.value"
					:inputLabel="t('shillinq', 'Confirm by')"
					:clearable="false" />
				<NcTextField
					v-model="entry.value"
					type="number"
					min="0"
					:label="modeLabel(entry.mode)" />
			</fieldset>

			<NcTextField
				v-model="notes"
				:label="t('shillinq', 'Notes')" />

			<NcButton
				type="submit"
				variant="primary"
				:disabled="submitting || payloads.length === 0"
				data-testid="service-receipt-form-submit">
				{{ submitting ? t('shillinq', 'Confirming…') : t('shillinq', 'Confirm service') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { createdRecordId } from '../../utils/createdRecordId.js'
import { isServiceLine, linePayload } from '../../utils/serviceReceiptLines.js'

const OBJECTS = '/apps/openregister/api/objects/shillinq'
const RECEIPTS = '/apps/shillinq/api/service-receipts'

export default {
	name: 'ServiceReceiptForm',
	components: { NcButton, NcSelect, NcTextField },

	data() {
		return {
			purchaseOrder: null,
			entries: [],
			periodStart: '',
			periodEnd: '',
			notes: '',
			loading: true,
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The three confirmation modes, labelled.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		modeOptions() {
			return [
				{ value: 'quantityConfirmed', label: this.t('shillinq', 'Quantity') },
				{ value: 'percentageComplete', label: this.t('shillinq', 'Percentage complete') },
				{ value: 'amountConfirmedCents', label: this.t('shillinq', 'Amount') },
			]
		},

		/**
		 * The line payloads of the entries that hold a valid value.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		payloads() {
			const period = { periodStart: this.periodStart, periodEnd: this.periodEnd }
			return this.entries
				.map((entry) => linePayload(entry, period, this.purchaseOrder?.administrationId))
				.filter((payload) => payload !== null)
		},
	},

	async created() {
		await this.load(this.$route?.query?.poId)
	},

	methods: {
		/**
		 * Read the order and its service lines.
		 *
		 * @param {string} poId The purchase order id.
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		async load(poId) {
			this.loading = true
			this.error = ''
			try {
				if (!poId) {
					throw new Error('no order')
				}
				const order = await axios.get(generateUrl(`${OBJECTS}/PurchaseOrder/${poId}`))
				this.purchaseOrder = order.data || null
				const lines = await axios.get(generateUrl(`${OBJECTS}/PurchaseOrderLine`), { params: { filter: { poId } } })
				const rows = lines.data?.results || lines.data || []
				this.entries = rows.filter(isServiceLine).map((line) => ({
					poLineId: line.id,
					description: line.description || line.productOrServiceCode || line.id,
					quantityOrdered: line.quantityOrdered,
					unitOfMeasure: line.unitOfMeasure,
					mode: 'quantityConfirmed',
					value: '',
				}))
			} catch {
				this.purchaseOrder = null
				this.error = this.t('shillinq', 'Failed to load purchase order')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The label of the value field for a mode.
		 *
		 * @param {string} mode A confirmation mode.
		 * @return {string}
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		modeLabel(mode) {
			const labels = {
				quantityConfirmed: this.t('shillinq', 'Quantity delivered'),
				percentageComplete: this.t('shillinq', 'Percentage complete (0–100)'),
				amountConfirmedCents: this.t('shillinq', 'Amount delivered (EUR)'),
			}
			return labels[mode] || ''
		},

		/**
		 * Create the receipt, add its lines, confirm it and open it.
		 *
		 * @spec openspec/changes/purchasing-order-dispatch-and-receipt/tasks.md#task-4.2
		 */
		async submit() {
			if (this.submitting || this.payloads.length === 0) {
				return
			}
			this.submitting = true
			this.error = ''
			const administrationId = this.purchaseOrder.administrationId
			try {
				const created = await axios.post(generateUrl(RECEIPTS), {
					administrationId,
					poIds: [this.purchaseOrder.id],
					periodStart: this.periodStart,
					periodEnd: this.periodEnd,
					notes: this.notes,
					costCenter: this.purchaseOrder.costCenter || '',
					projectCode: this.purchaseOrder.projectCode || '',
				})
				const receiptId = createdRecordId(created.data || {}, 'receiptNumber')
				for (const payload of this.payloads) {
					await axios.post(generateUrl(`${RECEIPTS}/${receiptId}/lines`), payload)
				}
				await axios.post(generateUrl(`${RECEIPTS}/${receiptId}/confirm`), { administrationId })
				this.$router.push({ name: 'ServiceReceiptDetail', params: { id: receiptId } })
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('shillinq', 'Could not confirm the service receipt.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.service-receipt-form {
	padding: 16px;
	max-width: 720px;
}

.service-receipt-form__period {
	display: flex;
	gap: 12px;
}

.service-receipt-form__line {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin: 12px 0;
}

.service-receipt-form__error {
	color: var(--color-error-text);
}
</style>
