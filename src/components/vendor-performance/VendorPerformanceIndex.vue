<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Vendor Performance Index view (slice 10 of bookkeeping-purchase-order-3way).

 Renders the most recent scorecard per (supplierId, period) tuple — one row
 per supplier with the current overall score, trend pill, eligibility badge
 and a drill-through link to the detail surface. Sorting is by overall
 score descending so the top performers float to the top of the AP team's
 weekly review.

 Filtering: a single "eligibility" dropdown narrows the row set to eligible /
 ineligible / all suppliers. The row count is bounded by the supplier base
 (typically <100 active suppliers) so an in-memory filter is well-sized.

 Data flow: GET /apps/openregister/api/objects/shillinq/VendorPerformance
 — paginated by the OR endpoint; sorted in-memory after fetch.

 @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
-->
<template>
	<div class="vp-index">
		<header class="vp-index__header">
			<h2 data-testid="vp-index-title">
				{{ t('shillinq', 'Vendor performance') }}
			</h2>
			<p class="vp-index__hint">
				{{
					t(
						'shillinq',
						'Latest monthly scorecard per supplier. Suppliers above 96 % are flagged for auto-review once the 90-day bootstrap window has passed.',
					)
				}}
			</p>
		</header>

		<div class="vp-index__filters" data-testid="vp-index-filters">
			<label class="vp-index__filter-label" for="vp-eligibility-filter">
				{{ t('shillinq', 'Eligibility') }}
			</label>
			<select
				id="vp-eligibility-filter"
				v-model="eligibilityFilter"
				data-testid="vp-eligibility-filter">
				<option value="">
					{{ t('shillinq', 'All suppliers') }}
				</option>
				<option value="eligible">
					{{ t('shillinq', 'Auto-review eligible') }}
				</option>
				<option value="ineligible">
					{{ t('shillinq', 'Not eligible') }}
				</option>
			</select>
		</div>

		<div v-if="loading" class="vp-index__loading" data-testid="vp-index-loading">
			{{ t('shillinq', 'Loading scorecards…') }}
		</div>

		<div v-else-if="error" class="vp-index__error" data-testid="vp-index-error">
			{{ error }}
		</div>

		<CnDataTable
			v-else
			class="vp-index__table"
			data-testid="vp-index-table"
			:columns="columns"
			:rows="filteredRows"
			:emptyLabel="t('shillinq', 'No scorecards recorded yet.')">
			<template #cell-supplierId="{ row }">
				{{ row.supplierId || '—' }}
			</template>
			<template #cell-period="{ row }">
				{{ row.period || '—' }}
			</template>
			<template #cell-overallScore="{ row }">
				<span
					class="vp-index__score"
					:class="scoreClass(row.overallScore)"
					:data-testid="`vp-score-${row.id}`">
					{{ formatBp(row.overallScore) }}
				</span>
			</template>
			<template #cell-scoreTrend="{ row }">
				<span
					class="vp-index__pill"
					:class="`vp-index__pill--${row.scoreTrend || 'stable'}`"
					:data-testid="`vp-trend-${row.id}`">
					{{ trendLabel(row.scoreTrend) }}
				</span>
			</template>
			<template #cell-disputeCount="{ row }">
				{{ row.disputeCount || 0 }}
			</template>
			<template #cell-automatedReviewEligible="{ row }">
				<span
					class="vp-index__pill"
					:class="
						row.automatedReviewEligible
							? 'vp-index__pill--eligible'
							: 'vp-index__pill--ineligible'
					"
					:data-testid="`vp-eligible-${row.id}`">
					{{
						row.automatedReviewEligible
							? t('shillinq', 'Yes')
							: t('shillinq', 'No')
					}}
				</span>
			</template>
			<template #cell-actions="{ row }">
				<router-link
					:to="{ name: 'VendorPerformanceDetail', params: { id: row.id } }"
					class="vp-index__link"
					:data-testid="`vp-open-${row.id}`">
					{{ t('shillinq', 'Open') }}
				</router-link>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'VendorPerformanceIndex',
	components: {
		CnDataTable,
	},

	data() {
		return {
			rows: [],
			loading: true,
			error: '',
			eligibilityFilter: '',
		}
	},

	computed: {
		/**
		 * CnDataTable column definitions for the vendor-performance list.
		 *
		 * @spec openspec/specs/list-views-cndatatable/spec.md
		 * @return {Array<object>} ordered column defs
		 */
		columns() {
			return [
				{
					key: 'supplierId',
					label: this.t('shillinq', 'Supplier'),
					sortable: true,
				},
				{
					key: 'period',
					label: this.t('shillinq', 'Period'),
					sortable: true,
				},
				{
					key: 'overallScore',
					label: this.t('shillinq', 'Overall score'),
					sortable: true,
				},
				{
					key: 'scoreTrend',
					label: this.t('shillinq', 'Trend'),
					sortable: true,
				},
				{
					key: 'disputeCount',
					label: this.t('shillinq', 'Disputes'),
					sortable: true,
				},
				{
					key: 'automatedReviewEligible',
					label: this.t('shillinq', 'Auto-review eligible'),
					sortable: true,
				},
				{ key: 'actions', label: '', sortable: false },
			]
		},

		latestPerSupplier() {
			const map = new Map()
			for (const row of this.rows) {
				const key = `${row.supplierId || ''}|${row.administrationId || ''}`
				const existing = map.get(key)
				if (!existing) {
					map.set(key, row)
					continue
				}
				const a = String(existing.period || '')
				const b = String(row.period || '')
				if (b > a) {
					map.set(key, row)
				}
			}
			const list = [...map.values()]
			list.sort(
				(a, b) => Number(b.overallScore || 0) - Number(a.overallScore || 0),
			)
			return list
		},

		filteredRows() {
			if (this.eligibilityFilter === 'eligible') {
				return this.latestPerSupplier.filter(
					(r) => r.automatedReviewEligible === true,
				)
			}
			if (this.eligibilityFilter === 'ineligible') {
				return this.latestPerSupplier.filter(
					(r) => r.automatedReviewEligible !== true,
				)
			}
			return this.latestPerSupplier
		},
	},

	async created() {
		await this.loadRows()
	},

	methods: {
		async loadRows() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/VendorPerformance`,
					),
				)
				const rows = response.data?.results || response.data || []
				this.rows = Array.isArray(rows) ? rows : []
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load vendor scorecards')
			} finally {
				this.loading = false
			}
		},

		formatBp(bp) {
			if (bp === null || bp === undefined) {
				return '—'
			}
			const value = Number(bp)
			if (!Number.isFinite(value)) {
				return '—'
			}
			return `${(value / 100).toFixed(2)} %`
		},

		scoreClass(bp) {
			const value = Number(bp || 0)
			if (value >= 9600) {
				return 'vp-index__score--high'
			}
			if (value >= 8500) {
				return 'vp-index__score--mid'
			}
			return 'vp-index__score--low'
		},

		trendLabel(trend) {
			const labels = {
				improving: this.t('shillinq', 'Improving'),
				stable: this.t('shillinq', 'Stable'),
				declining: this.t('shillinq', 'Declining'),
			}
			return labels[trend] || this.t('shillinq', 'Stable')
		},
	},
}
</script>

<style scoped>
.vp-index {
	padding: 1rem;
}

.vp-index__header h2 {
	margin: 0 0 0.25rem 0;
}

.vp-index__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 1rem 0;
}

.vp-index__filters {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 1rem;
}

.vp-index__filter-label {
	font-weight: 600;
}

.vp-index__loading,
.vp-index__error,
.vp-index__empty {
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.vp-index__error {
	color: var(--color-error);
}

.vp-index__table {
	width: 100%;
	border-collapse: collapse;
}

.vp-index__table th,
.vp-index__table td {
	padding: 0.5rem 0.75rem;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.vp-index__score {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius);
	font-weight: 700;
}

.vp-index__score--high {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.vp-index__score--mid {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.vp-index__score--low {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.vp-index__pill {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.875rem;
	background: var(--color-background-hover);
}

.vp-index__pill--improving,
.vp-index__pill--eligible {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.vp-index__pill--stable {
	background: var(--color-background-darker);
}

.vp-index__pill--declining {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.vp-index__pill--ineligible {
	background: var(--color-background-darker);
}

.vp-index__link {
	color: var(--color-primary);
}
</style>
