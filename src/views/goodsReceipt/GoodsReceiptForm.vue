<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-11.1 -->
<template>
	<div class="goods-receipt-form">
		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<header class="goods-receipt-form__header">
				<h2>{{ t('shillinq', 'Record Goods Receipt') }}</h2>
				<p v-if="purchaseOrder" class="goods-receipt-form__subtitle">
					{{ t('shillinq', 'For Purchase Order:') }} {{ purchaseOrder.poNumber }}
				</p>
			</header>

			<form class="goods-receipt-form__form" @submit.prevent="submit">
				<table v-if="lines.length" class="goods-receipt-form__table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Product') }}</th>
							<th>{{ t('shillinq', 'Ordered Qty') }}</th>
							<th>{{ t('shillinq', 'Previously Received') }}</th>
							<th>{{ t('shillinq', 'Outstanding') }}</th>
							<th>{{ t('shillinq', 'Received Qty') }} *</th>
							<th>{{ t('shillinq', 'Discrepancy Note') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(line, index) in lines" :key="line.purchaseOrderLineId">
							<td>{{ line.productName }}</td>
							<td>{{ line.orderedQuantity }}</td>
							<td>{{ line.previouslyReceived }}</td>
							<td>{{ line.outstanding }}</td>
							<td>
								<input v-model.number="line.receivedQuantity"
									type="number"
									min="0"
									:max="line.outstanding"
									class="goods-receipt-form__qty-input"
									required
									@input="validateLine(index)" />
								<span v-if="line.error" class="goods-receipt-form__error">
									{{ line.error }}
								</span>
							</td>
							<td>
								<input v-model="line.discrepancyNote"
									type="text"
									class="goods-receipt-form__note-input"
									:placeholder="t('shillinq', 'Optional note...')" />
							</td>
						</tr>
					</tbody>
				</table>

				<p v-else class="goods-receipt-form__empty">
					{{ t('shillinq', 'No outstanding lines to receive.') }}
				</p>

				<div class="goods-receipt-form__actions">
					<NcButton type="tertiary" @click="$router.back()">
						{{ t('shillinq', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						native-type="submit"
						:disabled="saving || !isValid">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ t('shillinq', 'Submit Goods Receipt') }}
					</NcButton>
				</div>
			</form>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { usePurchaseOrderStore } from '../../store/modules/purchaseOrder.js'

export default {
	name: 'GoodsReceiptForm',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			loading: false,
			saving: false,
			purchaseOrder: null,
			lines: [],
		}
	},
	computed: {
		purchaseOrderStore() {
			return usePurchaseOrderStore()
		},
		isValid() {
			return (
				this.lines.length > 0
				&& this.lines.every((line) => !line.error && line.receivedQuantity >= 0)
				&& this.lines.some((line) => line.receivedQuantity > 0)
			)
		},
	},
	mounted() {
		this.loadPurchaseOrder()
	},
	methods: {
		t,
		async loadPurchaseOrder() {
			this.loading = true
			try {
				const purchaseOrderId = this.$route.query.purchaseOrderId
				if (!purchaseOrderId) {
					console.error('No purchaseOrderId provided')
					return
				}
				await this.purchaseOrderStore.fetchOne(purchaseOrderId)
				this.purchaseOrder = this.purchaseOrderStore.current

				if (this.purchaseOrder?.lines) {
					this.lines = this.purchaseOrder.lines.map((poLine) => {
						const outstanding = poLine.quantity - (poLine.receivedQuantity || 0)
						return {
							purchaseOrderLineId: poLine.id,
							productName: poLine.productName,
							orderedQuantity: poLine.quantity,
							previouslyReceived: poLine.receivedQuantity || 0,
							outstanding,
							receivedQuantity: 0,
							discrepancyNote: '',
							error: '',
						}
					})
				}
			} finally {
				this.loading = false
			}
		},
		validateLine(index) {
			const line = this.lines[index]
			if (line.receivedQuantity < 0) {
				line.error = this.t('shillinq', 'Quantity cannot be negative.')
			} else if (line.receivedQuantity > line.outstanding) {
				line.error = this.t('shillinq', 'Cannot exceed outstanding quantity ({outstanding}).', {
					outstanding: line.outstanding,
				})
			} else {
				line.error = ''
			}
		},
		async submit() {
			if (!this.isValid || this.saving) return
			this.saving = true
			try {
				const payload = {
					purchaseOrderId: this.purchaseOrder.id,
					lines: this.lines
						.filter((line) => line.receivedQuantity > 0)
						.map((line) => ({
							purchaseOrderLineId: line.purchaseOrderLineId,
							receivedQuantity: line.receivedQuantity,
							discrepancyNote: line.discrepancyNote || null,
						})),
				}
				const url = generateUrl('/apps/shillinq/api/v1/goods-receipts')
				await axios.post(url, payload)
				this.$router.push({
					name: 'purchaseOrderDetail',
					params: { id: this.purchaseOrder.id },
				})
			} catch (error) {
				console.error('Failed to submit goods receipt:', error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.goods-receipt-form {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.goods-receipt-form__header {
	margin-bottom: 16px;
}

.goods-receipt-form__header h2 {
	margin: 0 0 4px;
	font-size: 22px;
	font-weight: 600;
}

.goods-receipt-form__subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.goods-receipt-form__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 20px;
}

.goods-receipt-form__table th,
.goods-receipt-form__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.goods-receipt-form__qty-input {
	width: 80px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.goods-receipt-form__note-input {
	width: 100%;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.goods-receipt-form__error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 2px;
}

.goods-receipt-form__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.goods-receipt-form__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
