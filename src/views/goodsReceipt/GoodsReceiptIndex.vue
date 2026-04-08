<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-11.1 -->
<template>
	<div class="goods-receipt-index">
		<header class="goods-receipt-index__header">
			<h2>{{ t('shillinq', 'Goods Receipts') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" />

		<table v-else-if="receipts.length" class="goods-receipt-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Receipt Number') }}</th>
					<th>{{ t('shillinq', 'PO Number') }}</th>
					<th>{{ t('shillinq', 'Received Date') }}</th>
					<th>{{ t('shillinq', 'Received By') }}</th>
					<th>{{ t('shillinq', 'Lines') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="receipt in receipts"
					:key="receipt.id"
					class="goods-receipt-index__row"
					@click="navigateToDetail(receipt.id)">
					<td>{{ receipt.receiptNumber }}</td>
					<td>{{ receipt.poNumber }}</td>
					<td>{{ receipt.receivedDate }}</td>
					<td>{{ receipt.receivedBy }}</td>
					<td>{{ receipt.lineCount }}</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="goods-receipt-index__empty">
			{{ t('shillinq', 'No goods receipts found.') }}
		</p>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useGoodsReceiptStore } from '../../store/modules/goodsReceipt.js'

export default {
	name: 'GoodsReceiptIndex',
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
		receipts() {
			return this.goodsReceiptStore.items || []
		},
	},
	mounted() {
		this.fetchReceipts()
	},
	methods: {
		t,
		async fetchReceipts() {
			this.loading = true
			try {
				await this.goodsReceiptStore.fetchAll()
			} finally {
				this.loading = false
			}
		},
		navigateToDetail(id) {
			this.$router.push({ name: 'goodsReceiptDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.goods-receipt-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.goods-receipt-index__header {
	margin-bottom: 16px;
}

.goods-receipt-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.goods-receipt-index__table {
	width: 100%;
	border-collapse: collapse;
}

.goods-receipt-index__table th,
.goods-receipt-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.goods-receipt-index__row {
	cursor: pointer;
}

.goods-receipt-index__row:hover {
	background-color: var(--color-background-hover);
}

.goods-receipt-index__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
