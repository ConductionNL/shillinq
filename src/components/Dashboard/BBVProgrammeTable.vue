<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — per-programme utilization table
 (member 05 of bookkeeping-waterschappen-bbv-variant).

 Renders one CnDataTable row per BBVProgramme with:
   Code | Name | Budget | YTD | Utilization % | Status badge

 The status badge maps the server-computed complianceStatus
 (slice-02 aggregation, REQ-BBVW-005) to the design.md emoji palette:
   on-track       🟢
   at-risk        🟡  (tooltip: "Projected to exceed budget — review allocations")
   non-compliant  🔴
   unconfigured   ⚪

 Row clicks navigate to the slice-07 mapping detail page
 (BudgetMappingDetail) so the officer can drill into the GL ↔ programme
 split. The router name is the manifest id slice 04 will register; if
 the route is not yet present the router will simply no-op (safe
 fallback) — the click handler also $emits 'row-click' so embedders
 (tests, parent dashboard) can intercept.

 ADR-031: all utilization / status / formatting derives from the
 BBVProgramme aggregation; no thresholds are recomputed here.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-programme-table" data-testid="bbv-programme-table">
		<CnDataTable
			:columns="columns"
			:rows="rows"
			:sortKey="sortKey"
			:sortOrder="sortOrder"
			:loading="loading"
			rowKey="rowKey"
			:loadingText="t('shillinq', 'Loading programmes…')"
			:emptyText="
				t('shillinq', 'No active programmes found for this fiscal year.')
			"
			@sort="onSort">
			<template #column-budget="{ row }">
				<span class="bbv-programme-table__money">{{
					formatEuro(row.totalBudget)
				}}</span>
			</template>
			<template #column-ytd="{ row }">
				<span class="bbv-programme-table__money">{{
					formatEuro(row.ytdSpend)
				}}</span>
			</template>
			<template #column-utilization="{ row }">
				<span
					class="bbv-programme-table__util"
					:class="utilizationClass(row.complianceStatus)">
					{{
						formatPercentage(row.utilizationPercentage, row.utilization)
					}}
				</span>
			</template>
			<template #column-status="{ row }">
				<span
					class="bbv-programme-table__badge"
					:class="badgeClass(row.complianceStatus)"
					:title="badgeTooltip(row.complianceStatus)"
					:data-testid="`bbv-status-${row.programmeCode}`">
					<span aria-hidden="true">{{
						badgeEmoji(row.complianceStatus)
					}}</span>
					<span>{{ badgeLabel(row.complianceStatus) }}</span>
				</span>
			</template>
			<template #row-actions="{ row }">
				<button
					type="button"
					class="bbv-programme-table__open"
					:data-testid="`bbv-open-${row.programmeCode}`"
					@click="openRow(row)">
					{{ t('shillinq', 'Open') }}
				</button>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'BBVProgrammeTable',
	components: { CnDataTable },
	props: {
		programmes: {
			type: Array,
			default: () => [],
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['row-click'],
	data() {
		return {
			sortKey: 'programmeCode',
			sortOrder: 'asc',
		}
	},

	computed: {
		columns() {
			return [
				{
					key: 'programmeCode',
					label: this.t('shillinq', 'Code'),
					sortable: true,
				},
				{
					key: 'programmeName',
					label: this.t('shillinq', 'Name'),
					sortable: true,
				},
				{
					key: 'budget',
					label: this.t('shillinq', 'Budget'),
					sortable: true,
				},
				{ key: 'ytd', label: this.t('shillinq', 'YTD'), sortable: true },
				{
					key: 'utilization',
					label: this.t('shillinq', 'Utilization %'),
					sortable: true,
				},
				{
					key: 'status',
					label: this.t('shillinq', 'Status'),
					sortable: true,
				},
			]
		},

		rows() {
			const decorated = this.programmes.map((p) => ({
				...p,
				rowKey: `${p.administrationId || ''}|${p.fiscalYear || ''}|${p.programmeCode || ''}`,
			}))
			const direction = this.sortOrder === 'desc' ? -1 : 1
			const key = this.sortKey
			decorated.sort((a, b) => {
				const valA = this.sortValue(a, key)
				const valB = this.sortValue(b, key)
				if (valA === valB) {
					return 0
				}
				if (valA === null || valA === undefined) {
					return 1
				}
				if (valB === null || valB === undefined) {
					return -1
				}
				return valA < valB ? -direction : direction
			})
			return decorated
		},
	},

	methods: {
		t,
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
		},

		sortValue(row, key) {
			if (key === 'budget') {
				return Number(row.totalBudget || 0)
			}
			if (key === 'ytd') {
				return Number(row.ytdSpend || 0)
			}
			if (key === 'utilization') {
				return Number(row.utilization || 0)
			}
			if (key === 'status') {
				const order = {
					'non-compliant': 0,
					'at-risk': 1,
					'on-track': 2,
					unconfigured: 3,
				}
				return order[row.complianceStatus] ?? 4
			}
			return row[key] ?? ''
		},

		openRow(row) {
			this.$emit('row-click', row)
			if (this.$router && row.programmeCode) {
				try {
					this.$router.push({
						name: 'BudgetMappingDetail',
						params: { id: row.programmeCode },
					})
				} catch (_e) {
					// Slice 04 may not have landed yet — fall through silently
					// so the click still emits row-click for embedders.
				}
			}
		},

		formatEuro(cents) {
			if (
				cents === null
				|| cents === undefined
				|| !Number.isFinite(Number(cents))
			) {
				return '—'
			}
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			}).format(Number(cents) / 100)
		},

		formatPercentage(percentField, ratioField) {
			let value = percentField
			if (value === null || value === undefined) {
				if (ratioField === null || ratioField === undefined) {
					return '—'
				}
				value = Number(ratioField) * 100
			}
			const numeric = Number(value)
			if (!Number.isFinite(numeric)) {
				return '—'
			}
			return `${numeric.toFixed(1)} %`
		},

		utilizationClass(status) {
			if (status === 'non-compliant')
				return 'bbv-programme-table__util--danger'
			if (status === 'at-risk') return 'bbv-programme-table__util--warning'
			if (status === 'on-track') return 'bbv-programme-table__util--ok'
			return 'bbv-programme-table__util--muted'
		},

		badgeClass(status) {
			if (status === 'non-compliant')
				return 'bbv-programme-table__badge--danger'
			if (status === 'at-risk') return 'bbv-programme-table__badge--warning'
			if (status === 'on-track') return 'bbv-programme-table__badge--ok'
			return 'bbv-programme-table__badge--muted'
		},

		badgeEmoji(status) {
			if (status === 'non-compliant') return '🔴'
			if (status === 'at-risk') return '🟡'
			if (status === 'on-track') return '🟢'
			return '⚪'
		},

		badgeLabel(status) {
			if (status === 'non-compliant')
				return this.t('shillinq', 'Non-compliant')
			if (status === 'at-risk') return this.t('shillinq', 'At-risk')
			if (status === 'on-track') return this.t('shillinq', 'On-track')
			return this.t('shillinq', 'Unconfigured')
		},

		badgeTooltip(status) {
			if (status === 'at-risk') {
				return this.t(
					'shillinq',
					'Projected to exceed budget — review allocations',
				)
			}
			if (status === 'non-compliant') {
				return this.t(
					'shillinq',
					'Spend already exceeds the on-track threshold',
				)
			}
			if (status === 'unconfigured') {
				return this.t(
					'shillinq',
					'No GL allocations configured for this programme',
				)
			}
			return this.t('shillinq', 'Programme spend is within the on-track band')
		},
	},
}
</script>

<style scoped>
.bbv-programme-table {
	width: 100%;
}

.bbv-programme-table__money {
	font-variant-numeric: tabular-nums;
}

.bbv-programme-table__util {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius);
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.bbv-programme-table__util--ok {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.bbv-programme-table__util--warning {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.bbv-programme-table__util--danger {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.bbv-programme-table__util--muted {
	background: var(--color-background-darker);
}

.bbv-programme-table__badge {
	display: inline-flex;
	align-items: center;
	gap: 0.35rem;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.875rem;
	cursor: help;
}

.bbv-programme-table__badge--ok {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.bbv-programme-table__badge--warning {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.bbv-programme-table__badge--danger {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.bbv-programme-table__badge--muted {
	background: var(--color-background-darker);
}

.bbv-programme-table__open {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-programme-table__open:hover {
	background: var(--color-background-hover);
}
</style>
