<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-11.1 -->
<template>
	<div class="goods-receipt-detail">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="receipt">
			<header class="goods-receipt-detail__header">
				<h2>{{ t('shillinq', 'Goods Receipt') }}: {{ receipt.receiptNumber }}</h2>
			</header>

			<table class="goods-receipt-detail__properties">
				<tr>
					<th>{{ t('shillinq', 'Receipt Number') }}</th>
					<td>{{ receipt.receiptNumber }}</td>
				</tr>
				<tr>
					<th>{{ t('shillinq', 'Purchase Order') }}</th>
					<td>
						<a href="#" @click.prevent="navigateToPO">
							{{ receipt.poNumber }}
						</a>
					</td>
				</tr>
				<tr>
					<th>{{ t('shillinq', 'Received Date') }}</th>
					<td>{{ receipt.receivedDate }}</td>
				</tr>
				<tr>
					<th>{{ t('shillinq', 'Received By') }}</th>
					<td>{{ receipt.receivedBy }}</td>
				</tr>
				<tr>
					<th>{{ t('shillinq', 'Notes') }}</th>
					<td>{{ receipt.notes }}</td>
				</tr>
			</table>

			<h3>{{ t('shillinq', 'Receipt Lines') }}</h3>

			<table v-if="receipt.lines && receipt.lines.length" class="goods-receipt-detail__lines-table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Product') }}</th>
						<th>{{ t('shillinq', 'Ordered Qty') }}</th>
						<th>{{ t('shillinq', 'Received Qty') }}</th>
						<th>{{ t('shillinq', 'Discrepancy Note') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in receipt.lines" :key="line.id">
						<td>{{ line.productName }}</td>
						<td>{{ line.orderedQuantity }}</td>
						<td>{{ line.receivedQuantity }}</td>
						<td>{{ line.discrepancyNote }}</td>
					</tr>
				</tbody>
			</table>

			<p v-else class="goods-receipt-detail__empty">
				{{ t('shillinq', 'No receipt lines.') }}
			</p>
		</template>

		<p v-else class="goods-receipt-detail__empty">
			{{ t('shillinq', 'Goods receipt not found.') }}
		</p>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useGoodsReceiptStore } from '../../store/modules/goodsReceipt.js'

export default {
	name: 'GoodsReceiptDetail',
	components: {
		NcLoadingIcon,
	},
	data() {
		return {
			loading: false,
		}
	},
	computed: {
		goodsReceiptStore() {
			return useGoodsReceiptStore()
		},
		receipt() {
			return this.goodsReceiptStore.current || null
		},
	},
	mounted() {
		this.fetchReceipt()
	},
	methods: {
		t,
		async fetchReceipt() {
			this.loading = true
			try {
				await this.goodsReceiptStore.fetchOne(this.$route.params.id)
			} finally {
				this.loading = false
			}
		},
		navigateToPO() {
			if (this.receipt?.purchaseOrderId) {
				this.$router.push({
					name: 'purchaseOrderDetail',
					params: { id: this.receipt.purchaseOrderId },
				})
			}
		},
	},
}
</script>

<style scoped>
.goods-receipt-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.goods-receipt-detail__header {
	margin-bottom: 16px;
}

.goods-receipt-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.goods-receipt-detail__properties {
	width: 100%;
	max-width: 600px;
	margin-bottom: 24px;
}

.goods-receipt-detail__properties th {
	text-align: left;
	padding: 6px 16px 6px 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	width: 180px;
}

.goods-receipt-detail__properties td {
	padding: 6px 0;
}

.goods-receipt-detail__lines-table {
	width: 100%;
	border-collapse: collapse;
}

.goods-receipt-detail__lines-table th,
.goods-receipt-detail__lines-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.goods-receipt-detail__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
