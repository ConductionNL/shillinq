<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-12.1 -->
<template>
	<div class="rfq-index">
		<header class="rfq-index__header">
			<h2>{{ t('shillinq', 'Requests for Quotation') }}</h2>
			<NcButton type="primary" @click="showCreateDialog = true">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('shillinq', 'New RFQ') }}
			</NcButton>
		</header>

		<div class="rfq-index__filters">
			<div class="rfq-index__filter-group">
				<span class="rfq-index__filter-label">{{ t('shillinq', 'Type:') }}</span>
				<NcButton v-for="typeFilter in typeFilters"
					:key="typeFilter.value"
					:type="activeType === typeFilter.value ? 'primary' : 'secondary'"
					@click="activeType = typeFilter.value">
					{{ typeFilter.label }}
				</NcButton>
			</div>
			<div class="rfq-index__filter-group">
				<span class="rfq-index__filter-label">{{ t('shillinq', 'Status:') }}</span>
				<NcButton v-for="status in statusFilters"
					:key="status.value"
					:type="activeStatus === status.value ? 'primary' : 'secondary'"
					@click="activeStatus = status.value">
					{{ status.label }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<table v-else-if="filteredRfqs.length" class="rfq-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Number') }}</th>
					<th>{{ t('shillinq', 'Title') }}</th>
					<th>{{ t('shillinq', 'Type') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Budget') }}</th>
					<th>{{ t('shillinq', 'Due Date') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="rfq in filteredRfqs"
					:key="rfq.id"
					class="rfq-index__row"
					@click="navigateToDetail(rfq.id)">
					<td>{{ rfq.number }}</td>
					<td>{{ rfq.title }}</td>
					<td>
						<span class="rfq-index__type-chip">{{ rfq.type }}</span>
					</td>
					<td>
						<span class="rfq-index__status-chip"
							:class="'rfq-index__status-chip--' + rfq.status">
							{{ rfq.status }}
						</span>
					</td>
					<td>{{ formatCurrency(rfq.budget, rfq.currency) }}</td>
					<td>{{ rfq.dueDate }}</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="rfq-index__empty">
			{{ t('shillinq', 'No RFQs found.') }}
		</p>

		<RFQForm v-if="showCreateDialog"
			@close="showCreateDialog = false"
			@saved="onRfqCreated" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useRFQStore } from '../../store/modules/rFQ.js'
import RFQForm from './RFQForm.vue'

export default {
	name: 'RFQIndex',
	components: {
		NcButton,
		NcLoadingIcon,
		Plus,
		RFQForm,
	},
	data() {
		return {
			loading: false,
			showCreateDialog: false,
			activeType: 'all',
			activeStatus: 'all',
			typeFilters: [
				{ value: 'all', label: this.t('shillinq', 'All') },
				{ value: 'rfq', label: this.t('shillinq', 'RFQ') },
				{ value: 'rfi', label: this.t('shillinq', 'RFI') },
				{ value: 'rfp', label: this.t('shillinq', 'RFP') },
			],
			statusFilters: [
				{ value: 'all', label: this.t('shillinq', 'All') },
				{ value: 'draft', label: this.t('shillinq', 'Draft') },
				{ value: 'published', label: this.t('shillinq', 'Published') },
				{ value: 'evaluating', label: this.t('shillinq', 'Evaluating') },
				{ value: 'awarded', label: this.t('shillinq', 'Awarded') },
				{ value: 'closed', label: this.t('shillinq', 'Closed') },
			],
		}
	},
	computed: {
		rfqStore() {
			return useRFQStore()
		},
		rfqs() {
			return this.rfqStore.items || []
		},
		filteredRfqs() {
			return this.rfqs.filter((rfq) => {
				if (this.activeType !== 'all' && rfq.type !== this.activeType) return false
				if (this.activeStatus !== 'all' && rfq.status !== this.activeStatus) return false
				return true
			})
		},
	},
	mounted() {
		this.fetchRfqs()
	},
	methods: {
		t,
		async fetchRfqs() {
			this.loading = true
			try {
				await this.rfqStore.fetchAll()
			} finally {
				this.loading = false
			}
		},
		formatCurrency(amount, currency) {
			if (amount == null) return ''
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'EUR' }).format(amount)
		},
		navigateToDetail(id) {
			this.$router.push({ name: 'rfqDetail', params: { id } })
		},
		onRfqCreated() {
			this.showCreateDialog = false
			this.fetchRfqs()
		},
	},
}
</script>

<style scoped>
.rfq-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.rfq-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.rfq-index__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.rfq-index__filters {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.rfq-index__filter-group {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.rfq-index__filter-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.rfq-index__table {
	width: 100%;
	border-collapse: collapse;
}

.rfq-index__table th,
.rfq-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.rfq-index__row {
	cursor: pointer;
}

.rfq-index__row:hover {
	background-color: var(--color-background-hover);
}

.rfq-index__type-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: uppercase;
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.rfq-index__status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.rfq-index__status-chip--draft {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.rfq-index__status-chip--published {
	background-color: var(--color-primary-light);
	color: var(--color-primary);
}

.rfq-index__status-chip--evaluating {
	background-color: var(--color-warning-light, #fff8e1);
	color: var(--color-warning);
}

.rfq-index__status-chip--awarded {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.rfq-index__status-chip--closed {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.rfq-index__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
