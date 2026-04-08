<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-11.2 -->
<template>
	<div class="three-way-matching-panel">
		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<div v-if="allMatched" class="three-way-matching-panel__banner--success">
				<Check :size="20" />
				<span>{{ t('shillinq', 'All lines matched — ready for payment approval') }}</span>
				<NcButton type="success" @click="approveForPayment">
					{{ t('shillinq', 'Approve for payment') }}
				</NcButton>
			</div>

			<table v-if="matchingLines.length" class="three-way-matching-panel__table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Product') }}</th>
						<th>{{ t('shillinq', 'Ordered Qty') }}</th>
						<th>{{ t('shillinq', 'Received Qty') }}</th>
						<th>{{ t('shillinq', 'Invoiced Qty') }}</th>
						<th>{{ t('shillinq', 'Match Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in matchingLines"
						:key="line.id"
						:class="{ 'three-way-matching-panel__row--discrepancy': line.status === 'discrepancy' }">
						<td>{{ line.productName }}</td>
						<td>{{ line.orderedQuantity }}</td>
						<td>{{ line.receivedQuantity }}</td>
						<td>{{ line.invoicedQuantity }}</td>
						<td>
							<span class="three-way-matching-panel__chip"
								:class="'three-way-matching-panel__chip--' + line.status">
								{{ line.statusLabel }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<p v-else class="three-way-matching-panel__empty">
				{{ t('shillinq', 'No matching data available.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Check from 'vue-material-design-icons/Check.vue'

export default {
	name: 'ThreeWayMatchingPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		Check,
	},
	props: {
		purchaseOrderId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			matchingLines: [],
		}
	},
	computed: {
		allMatched() {
			return (
				this.matchingLines.length > 0
				&& this.matchingLines.every((line) => line.status === 'matched')
			)
		},
	},
	watch: {
		purchaseOrderId: {
			immediate: true,
			handler() {
				this.fetchMatchingData()
			},
		},
	},
	methods: {
		t,
		async fetchMatchingData() {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/purchase-orders/{id}/matching', {
					id: this.purchaseOrderId,
				})
				const response = await axios.get(url)
				this.matchingLines = (response.data?.lines || []).map((line) => {
					const status = this.computeStatus(line)
					return {
						...line,
						status,
						statusLabel: status === 'matched'
							? this.t('shillinq', 'Matched')
							: this.t('shillinq', 'Discrepancy'),
					}
				})
			} catch (error) {
				console.error('Failed to fetch matching data:', error)
			} finally {
				this.loading = false
			}
		},
		computeStatus(line) {
			if (
				line.orderedQuantity === line.receivedQuantity
				&& line.receivedQuantity === line.invoicedQuantity
			) {
				return 'matched'
			}
			return 'discrepancy'
		},
		async approveForPayment() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/purchase-orders/{id}/approve-payment', {
					id: this.purchaseOrderId,
				})
				await axios.post(url)
			} catch (error) {
				console.error('Failed to approve for payment:', error)
			}
		},
	},
}
</script>

<style scoped>
.three-way-matching-panel__banner--success {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	margin-bottom: 16px;
	background-color: var(--color-success-light, #e8f8e8);
	border: 1px solid var(--color-success);
	border-radius: 8px;
	color: var(--color-success);
	font-weight: 600;
}

.three-way-matching-panel__table {
	width: 100%;
	border-collapse: collapse;
}

.three-way-matching-panel__table th,
.three-way-matching-panel__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.three-way-matching-panel__row--discrepancy {
	background-color: var(--color-warning-light, #fff8e1);
}

.three-way-matching-panel__chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.three-way-matching-panel__chip--matched {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.three-way-matching-panel__chip--discrepancy {
	background-color: var(--color-warning-light, #fff8e1);
	color: var(--color-warning);
}

.three-way-matching-panel__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
