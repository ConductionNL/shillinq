<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-12.2 -->
<template>
	<div class="quote-comparison-table">
		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<table v-if="sortedQuotes.length" class="quote-comparison-table__table">
				<thead>
					<tr>
						<th class="quote-comparison-table__sortable"
							@click="toggleSort('supplierName')">
							{{ t('shillinq', 'Supplier') }}
							<span v-if="sortField === 'supplierName'">{{ sortAsc ? '▲' : '▼' }}</span>
						</th>
						<th class="quote-comparison-table__sortable"
							@click="toggleSort('totalAmount')">
							{{ t('shillinq', 'Total Amount') }}
							<span v-if="sortField === 'totalAmount'">{{ sortAsc ? '▲' : '▼' }}</span>
						</th>
						<th>{{ t('shillinq', 'Currency') }}</th>
						<th class="quote-comparison-table__sortable"
							@click="toggleSort('validityDate')">
							{{ t('shillinq', 'Validity Date') }}
							<span v-if="sortField === 'validityDate'">{{ sortAsc ? '▲' : '▼' }}</span>
						</th>
						<th class="quote-comparison-table__sortable"
							@click="toggleSort('evaluationScore')">
							{{ t('shillinq', 'Evaluation Score') }}
							<span v-if="sortField === 'evaluationScore'">{{ sortAsc ? '▲' : '▼' }}</span>
						</th>
						<th>{{ t('shillinq', 'Budget Variance') }}</th>
						<th>{{ t('shillinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="quote in sortedQuotes"
						:key="quote.id"
						:class="{ 'quote-comparison-table__row--best': quote.isBestPrice }">
						<td>
							{{ quote.supplierName }}
							<span v-if="quote.isBestPrice" class="quote-comparison-table__badge--best">
								{{ t('shillinq', 'Best price') }}
							</span>
						</td>
						<td>{{ formatCurrency(quote.totalAmount, quote.currency) }}</td>
						<td>{{ quote.currency }}</td>
						<td>{{ quote.validityDate }}</td>
						<td>
							<input v-model.number="quote.evaluationScore"
								type="number"
								min="0"
								max="100"
								step="1"
								class="quote-comparison-table__score-input"
								@change="updateScore(quote)" />
						</td>
						<td>
							<span class="quote-comparison-table__variance"
								:class="quote.budgetVariance > 0
									? 'quote-comparison-table__variance--over'
									: 'quote-comparison-table__variance--under'">
								{{ formatVariance(quote.budgetVariance, quote.currency) }}
							</span>
						</td>
						<td>
							<span class="quote-comparison-table__status-chip"
								:class="'quote-comparison-table__status-chip--' + quote.status">
								{{ quote.status }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<p v-else class="quote-comparison-table__empty">
				{{ t('shillinq', 'No quotes to compare.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'QuoteComparisonTable',
	components: {
		NcLoadingIcon,
	},
	props: {
		rfqId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			quotes: [],
			rfqBudget: 0,
			sortField: 'totalAmount',
			sortAsc: true,
		}
	},
	computed: {
		lowestAmount() {
			if (!this.quotes.length) return null
			return Math.min(...this.quotes.map((q) => q.totalAmount))
		},
		sortedQuotes() {
			const enriched = this.quotes.map((quote) => ({
				...quote,
				isBestPrice: quote.totalAmount === this.lowestAmount,
				budgetVariance: quote.totalAmount - this.rfqBudget,
			}))

			return [...enriched].sort((a, b) => {
				let aVal = a[this.sortField]
				let bVal = b[this.sortField]

				if (typeof aVal === 'string') {
					aVal = aVal.toLowerCase()
					bVal = (bVal || '').toLowerCase()
				}

				if (aVal < bVal) return this.sortAsc ? -1 : 1
				if (aVal > bVal) return this.sortAsc ? 1 : -1
				return 0
			})
		},
	},
	watch: {
		rfqId: {
			immediate: true,
			handler() {
				this.fetchComparison()
			},
		},
	},
	methods: {
		t,
		async fetchComparison() {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/rfqs/{id}/comparison', {
					id: this.rfqId,
				})
				const response = await axios.get(url)
				this.quotes = response.data?.quotes || []
				this.rfqBudget = response.data?.budget || 0
			} catch (error) {
				console.error('Failed to fetch comparison data:', error)
			} finally {
				this.loading = false
			}
		},
		toggleSort(field) {
			if (this.sortField === field) {
				this.sortAsc = !this.sortAsc
			} else {
				this.sortField = field
				this.sortAsc = true
			}
		},
		formatCurrency(amount, currency) {
			if (amount == null) return ''
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'EUR' }).format(amount)
		},
		formatVariance(variance, currency) {
			if (variance == null) return ''
			const sign = variance >= 0 ? '+' : ''
			return sign + new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: currency || 'EUR',
			}).format(variance)
		},
		async updateScore(quote) {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/rfqs/{rfqId}/quotes/{quoteId}', {
					rfqId: this.rfqId,
					quoteId: quote.id,
				})
				await axios.patch(url, { evaluationScore: quote.evaluationScore })
			} catch (error) {
				console.error('Failed to update evaluation score:', error)
			}
		},
	},
}
</script>

<style scoped>
.quote-comparison-table__table {
	width: 100%;
	border-collapse: collapse;
}

.quote-comparison-table__table th,
.quote-comparison-table__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.quote-comparison-table__sortable {
	cursor: pointer;
	user-select: none;
}

.quote-comparison-table__sortable:hover {
	color: var(--color-primary);
}

.quote-comparison-table__row--best {
	background-color: var(--color-success-light, #e8f8e8);
}

.quote-comparison-table__badge--best {
	display: inline-block;
	margin-left: 8px;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 700;
	background-color: var(--color-success);
	color: #fff;
}

.quote-comparison-table__score-input {
	width: 70px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	text-align: center;
}

.quote-comparison-table__variance--over {
	color: var(--color-error);
	font-weight: 600;
}

.quote-comparison-table__variance--under {
	color: var(--color-success);
	font-weight: 600;
}

.quote-comparison-table__status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.quote-comparison-table__status-chip--submitted {
	background-color: var(--color-primary-light);
	color: var(--color-primary);
}

.quote-comparison-table__status-chip--evaluated {
	background-color: var(--color-warning-light, #fff8e1);
	color: var(--color-warning);
}

.quote-comparison-table__status-chip--awarded {
	background-color: var(--color-success-light, #e8f8e8);
	color: var(--color-success);
}

.quote-comparison-table__status-chip--rejected {
	background-color: var(--color-error-light, #fff0f0);
	color: var(--color-error);
}

.quote-comparison-table__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 40px 0;
}
</style>
