<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-12.1 -->
<template>
	<div class="rfq-detail">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="rfq">
			<header class="rfq-detail__header">
				<h2>{{ t('shillinq', 'RFQ') }}: {{ rfq.number }}</h2>
				<span class="rfq-detail__status-chip"
					:class="'rfq-detail__status-chip--' + rfq.status">
					{{ rfq.status }}
				</span>
			</header>

			<div class="rfq-detail__tabs">
				<NcButton v-for="tab in tabs"
					:key="tab.id"
					:type="activeTab === tab.id ? 'primary' : 'tertiary'"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</NcButton>
			</div>

			<!-- Details tab -->
			<div v-if="activeTab === 'details'" class="rfq-detail__panel">
				<table class="rfq-detail__properties">
					<tr>
						<th>{{ t('shillinq', 'Number') }}</th>
						<td>{{ rfq.number }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Title') }}</th>
						<td>{{ rfq.title }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Description') }}</th>
						<td>{{ rfq.description }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Type') }}</th>
						<td>{{ rfq.type }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Budget') }}</th>
						<td>{{ formatCurrency(rfq.budget, rfq.currency) }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Currency') }}</th>
						<td>{{ rfq.currency }}</td>
					</tr>
					<tr>
						<th>{{ t('shillinq', 'Due Date') }}</th>
						<td>{{ rfq.dueDate }}</td>
					</tr>
				</table>
			</div>

			<!-- Suppliers tab -->
			<div v-if="activeTab === 'suppliers'" class="rfq-detail__panel">
				<div class="rfq-detail__supplier-actions">
					<input v-model="supplierSearch"
						type="text"
						class="rfq-detail__supplier-search"
						:placeholder="t('shillinq', 'Search suppliers...')" />
					<NcButton v-if="rfq.status === 'draft'"
						type="primary"
						@click="publishAndInvite">
						<template #icon>
							<Send :size="20" />
						</template>
						{{ t('shillinq', 'Publish & Invite') }}
					</NcButton>
				</div>

				<table v-if="invitedSuppliers.length" class="rfq-detail__suppliers-table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Supplier') }}</th>
							<th>{{ t('shillinq', 'Invited Date') }}</th>
							<th>{{ t('shillinq', 'Response') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="supplier in invitedSuppliers" :key="supplier.id">
							<td>{{ supplier.name }}</td>
							<td>{{ supplier.invitedDate }}</td>
							<td>
								<span class="rfq-detail__response-chip"
									:class="'rfq-detail__response-chip--' + supplier.responseStatus">
									{{ supplier.responseStatus }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="rfq-detail__empty">
					{{ t('shillinq', 'No suppliers invited yet.') }}
				</p>
			</div>

			<!-- Quotes tab -->
			<div v-if="activeTab === 'quotes'" class="rfq-detail__panel">
				<table v-if="quotes.length" class="rfq-detail__quotes-table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Supplier') }}</th>
							<th>{{ t('shillinq', 'Total Amount') }}</th>
							<th>{{ t('shillinq', 'Validity Date') }}</th>
							<th>{{ t('shillinq', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="quote in quotes" :key="quote.id">
							<td>{{ quote.supplierName }}</td>
							<td>{{ formatCurrency(quote.totalAmount, quote.currency) }}</td>
							<td>{{ quote.validityDate }}</td>
							<td>
								<span class="rfq-detail__status-chip"
									:class="'rfq-detail__status-chip--' + quote.status">
									{{ quote.status }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="rfq-detail__empty">
					{{ t('shillinq', 'No quotes received yet.') }}
				</p>
			</div>

			<!-- Comparison tab -->
			<div v-if="activeTab === 'comparison'" class="rfq-detail__panel">
				<QuoteComparisonTable :rfq-id="rfq.id" />

				<div v-if="rfq.status === 'evaluating'" class="rfq-detail__award-actions">
					<NcButton type="primary" @click="showAwardDialog = true">
						<template #icon>
							<Trophy :size="20" />
						</template>
						{{ t('shillinq', 'Award') }}
					</NcButton>
				</div>
			</div>

			<!-- Award confirmation dialog -->
			<NcDialog v-if="showAwardDialog"
				:name="t('shillinq', 'Award RFQ')"
				@close="showAwardDialog = false">
				<p>{{ t('shillinq', 'Are you sure you want to award this RFQ? This action cannot be undone.') }}</p>
				<template #actions>
					<NcButton type="tertiary" @click="showAwardDialog = false">
						{{ t('shillinq', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="confirmAward">
						{{ t('shillinq', 'Confirm Award') }}
					</NcButton>
				</template>
			</NcDialog>
		</template>

		<p v-else class="rfq-detail__empty">
			{{ t('shillinq', 'RFQ not found.') }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import Send from 'vue-material-design-icons/Send.vue'
import Trophy from 'vue-material-design-icons/Trophy.vue'
import { useRfqStore } from '../../store/modules/rfq.js'
import QuoteComparisonTable from '../../components/QuoteComparisonTable.vue'

export default {
	name: 'RFQDetail',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		Send,
		Trophy,
		QuoteComparisonTable,
	},
	data() {
		return {
			loading: false,
			activeTab: 'details',
			supplierSearch: '',
			showAwardDialog: false,
			invitedSuppliers: [],
			quotes: [],
			tabs: [
				{ id: 'details', label: this.t('shillinq', 'Details') },
				{ id: 'suppliers', label: this.t('shillinq', 'Suppliers') },
				{ id: 'quotes', label: this.t('shillinq', 'Quotes') },
				{ id: 'comparison', label: this.t('shillinq', 'Comparison') },
			],
		}
	},
	computed: {
		rfqStore() {
			return useRfqStore()
		},
		rfq() {
			return this.rfqStore.current || null
		},
	},
	mounted() {
		this.fetchRfq()
	},
	methods: {
		t,
		async fetchRfq() {
			this.loading = true
			try {
				const id = this.$route.params.id
				await this.rfqStore.fetchOne(id)
				await this.fetchSuppliers()
				await this.fetchQuotes()
			} finally {
				this.loading = false
			}
		},
		async fetchSuppliers() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/rfqs/{id}/suppliers', {
					id: this.$route.params.id,
				})
				const response = await axios.get(url)
				this.invitedSuppliers = response.data?.results || []
			} catch (error) {
				console.error('Failed to fetch suppliers:', error)
			}
		},
		async fetchQuotes() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/rfqs/{id}/quotes', {
					id: this.$route.params.id,
				})
				const response = await axios.get(url)
				this.quotes = response.data?.results || []
			} catch (error) {
				console.error('Failed to fetch quotes:', error)
			}
		},
		formatCurrency(amount, currency) {
			if (amount == null) return ''
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'EUR' }).format(amount)
		},
		async publishAndInvite() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/rfqs/{id}/publish', {
					id: this.rfq.id,
				})
				await axios.post(url)
				await this.fetchRfq()
			} catch (error) {
				console.error('Failed to publish and invite:', error)
			}
		},
		async confirmAward() {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/rfqs/{id}/award', {
					id: this.rfq.id,
				})
				await axios.post(url)
				this.showAwardDialog = false
				await this.fetchRfq()
			} catch (error) {
				console.error('Failed to award RFQ:', error)
			}
		},
	},
}
</script>

<style scoped>
.rfq-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.rfq-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.rfq-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.rfq-detail__status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.rfq-detail__status-chip--draft {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.rfq-detail__status-chip--published {
	background-color: var(--color-primary-light);
	color: var(--color-primary);
}

.rfq-detail__status-chip--evaluating {
	background-color: var(--color-warning-light, #fff8e1);
	color: var(--color-warning);
}

.rfq-detail__status-chip--awarded {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.rfq-detail__status-chip--closed {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.rfq-detail__tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.rfq-detail__panel {
	padding: 16px 0;
}

.rfq-detail__properties {
	width: 100%;
	max-width: 600px;
}

.rfq-detail__properties th {
	text-align: left;
	padding: 6px 16px 6px 0;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	width: 150px;
}

.rfq-detail__properties td {
	padding: 6px 0;
}

.rfq-detail__supplier-actions {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-bottom: 16px;
}

.rfq-detail__supplier-search {
	flex: 1;
	max-width: 400px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.rfq-detail__suppliers-table,
.rfq-detail__quotes-table {
	width: 100%;
	border-collapse: collapse;
}

.rfq-detail__suppliers-table th,
.rfq-detail__suppliers-table td,
.rfq-detail__quotes-table th,
.rfq-detail__quotes-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.rfq-detail__response-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.rfq-detail__response-chip--pending {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.rfq-detail__response-chip--accepted {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.rfq-detail__response-chip--declined {
	background-color: var(--color-error-light, #fff0f0);
	color: var(--color-error);
}

.rfq-detail__award-actions {
	margin-top: 16px;
}

.rfq-detail__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
