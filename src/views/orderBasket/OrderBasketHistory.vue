<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-9.2 -->
<template>
	<div class="basket-history">
		<header class="basket-history__header">
			<h2>{{ t('shillinq', 'Order History') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent
			v-else-if="baskets.length === 0"
			:name="t('shillinq', 'No order history')"
			:description="t('shillinq', 'Submitted baskets will appear here.')">
			<template #icon>
				<HistoryIcon :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="basket-history__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Submitted') }}</th>
					<th>{{ t('shillinq', 'Items') }}</th>
					<th>{{ t('shillinq', 'Total') }}</th>
					<th>{{ t('shillinq', 'Basket Status') }}</th>
					<th>{{ t('shillinq', 'PO Status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="basket in baskets" :key="basket.id">
					<td>{{ formatDate(basket.submittedAt) }}</td>
					<td>{{ basket.lineCount || (basket.lines && basket.lines.length) || 0 }}</td>
					<td>{{ formatPrice(basket.total, basket.currency) }}</td>
					<td>{{ basket.status }}</td>
					<td>
						<span
							v-if="basket.purchaseOrderStatus"
							:class="'basket-history__po-badge basket-history__po-badge--' + poStatusColor(basket.purchaseOrderStatus)">
							{{ basket.purchaseOrderStatus }}
						</span>
						<span v-else class="basket-history__po-badge basket-history__po-badge--grey">
							{{ t('shillinq', 'n/a') }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import HistoryIcon from 'vue-material-design-icons/History.vue'

const PO_STATUS_COLORS = {
	pending: 'amber',
	approved: 'blue',
	submitted: 'blue',
	acknowledged: 'blue',
	overdue: 'red',
	delivered: 'green',
	invoiced: 'teal',
	closed: 'grey',
}

export default {
	name: 'OrderBasketHistory',
	components: {
		NcEmptyContent,
		NcLoadingIcon,
		HistoryIcon,
	},

	data() {
		return {
			loading: false,
			baskets: [],
		}
	},

	async created() {
		await this.loadHistory()
	},

	methods: {
		t(app, text) {
			return t(app, text)
		},

		poStatusColor(status) {
			return PO_STATUS_COLORS[status] || 'grey'
		},

		formatDate(dateStr) {
			if (!dateStr) return '-'
			return new Date(dateStr).toLocaleDateString('nl-NL', {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		formatPrice(price, currency) {
			if (price == null) return '-'
			const cur = currency || 'EUR'
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: cur,
			}).format(price)
		},

		async loadHistory() {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/order-baskets?exclude_status=open&sort=-submittedAt')
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					this.baskets = data.results || data
				}
			} catch (error) {
				console.error('Failed to load basket history:', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.basket-history {
	padding: 8px 16px 24px;
	max-width: 1200px;
}

.basket-history__header h2 {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.basket-history__table {
	width: 100%;
	border-collapse: collapse;
}

.basket-history__table th,
.basket-history__table td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.basket-history__po-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.basket-history__po-badge--amber {
	background-color: #fff3cd;
	color: #856404;
}

.basket-history__po-badge--blue {
	background-color: #cce5ff;
	color: #004085;
}

.basket-history__po-badge--red {
	background-color: #f8d7da;
	color: #721c24;
}

.basket-history__po-badge--green {
	background-color: #d4edda;
	color: #155724;
}

.basket-history__po-badge--teal {
	background-color: #d1ecf1;
	color: #0c5460;
}

.basket-history__po-badge--grey {
	background-color: #e2e3e5;
	color: #383d41;
}
</style>
