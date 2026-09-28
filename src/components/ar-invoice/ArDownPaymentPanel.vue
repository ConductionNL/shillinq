<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ArDownPaymentPanel: the down payments of an invoice's order (sales-down-payments).

 On a down-payment or final invoice it lists every down payment of the order
 with its amount, whether it is paid, and the invoice that deducted it
 (REQ-SDP-005). On a draft invoice whose customer has issued down payments
 not yet deducted, it offers per order to make this the final invoice
 (REQ-SDP-003): the server adds the negative lines and lowers the totals.
 The page reloads after a deduction, because the detail page's other
 widgets (totals, lines) have no refresh event to listen to.

 Rendered on ARInvoiceDetail through the page slot
 widget-invoice-down-payments. The server checks the administration and
 every rule; this component only decides what to show.

 @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
-->

<template>
	<section class="adp" data-testid="ar-down-payments">
		<p v-if="loading" class="adp__muted">
			{{ t('shillinq', 'Loading down payments…') }}
		</p>
		<p
			v-else-if="error"
			class="adp__error"
			role="alert"
			data-testid="ar-down-payments-error">
			{{ error }}
		</p>
		<template v-else>
			<div v-if="data.position.length > 0">
				<h4 class="adp__heading">
					{{
						t('shillinq', 'Down payments on order {order}', {
							order: data.orderLabel,
						})
					}}
				</h4>
				<table class="adp__table" data-testid="ar-down-payments-position">
					<thead>
						<tr>
							<th scope="col">
								{{ t('shillinq', 'Invoice') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Amount') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Paid') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Deducted on') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in data.position" :key="row.id">
							<td>{{ row.invoiceNumber }}</td>
							<td>{{ money(row.grossAmount) }}</td>
							<td>
								{{
									row.paid
										? t('shillinq', 'Paid')
										: t('shillinq', 'Not paid yet')
								}}
							</td>
							<td>
								{{
									row.deductedOnInvoiceNumber
									|| t('shillinq', 'Not deducted yet')
								}}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div
				v-for="order in data.openOrders"
				:key="order.orderReference"
				class="adp__open">
				<p>
					{{
						t(
							'shillinq',
							'Down payments to deduct for order {order}: {amount}',
							{
								order: order.orderLabel,
								amount: money(order.grossAmount),
							},
						)
					}}
				</p>
				<NcButton
					:disabled="deducting"
					:data-testid="'ar-down-payments-deduct-' + order.orderReference"
					@click="deduct(order.orderReference)">
					{{ t('shillinq', 'Deduct down payments') }}
				</NcButton>
			</div>

			<p
				v-if="data.position.length === 0 && data.openOrders.length === 0"
				class="adp__muted">
				{{ t('shillinq', 'No down payments for this invoice.') }}
			</p>
		</template>
	</section>
</template>

<script>
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import {
	deductDownPayments,
	errorMessage,
	loadDownPayments,
} from '../../utils/downPaymentApi.js'

export default {
	name: 'ArDownPaymentPanel',
	components: { NcButton },

	props: {
		/** This invoice's id, bound from the route by CnDetailPage. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			deducting: false,
			error: '',
			data: { position: [], openOrders: [], orderLabel: '' },
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/changes/sales-down-payments/tasks.md#task-3.2 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the order's position and the orders left to deduct.
		 *
		 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const data = await loadDownPayments(String(this.objectId))
				this.data = {
					position: data?.position ?? [],
					openOrders: data?.openOrders ?? [],
					orderLabel: data?.orderLabel ?? '',
				}
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The down payments could not be loaded.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Make this draft the final invoice of the order.
		 *
		 * @param {string} orderReference The order.
		 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
		 */
		async deduct(orderReference) {
			this.deducting = true
			this.error = ''
			try {
				await deductDownPayments(String(this.objectId), orderReference)
				showSuccess(
					t('shillinq', 'The down payments are deducted on this invoice.'),
				)
				window.location.reload()
			} catch (error) {
				this.error = errorMessage(
					error,
					t('shillinq', 'The down payments could not be deducted.'),
				)
			} finally {
				this.deducting = false
			}
		},

		/**
		 * An amount in euros.
		 *
		 * @param {number} amount The amount.
		 * @return {string} The formatted amount.
		 * @spec openspec/changes/sales-down-payments/tasks.md#task-3.2
		 */
		money(amount) {
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(amount) || 0)
		},
	},
}
</script>

<style scoped>
.adp {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.adp__table {
	width: 100%;
	border-collapse: collapse;
}

.adp__table th,
.adp__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.adp__heading {
	margin: 0 0 8px;
}

.adp__muted {
	color: var(--color-text-maxcontrast);
}

.adp__error {
	color: var(--color-error-text);
}
</style>
