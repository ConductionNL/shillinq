<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Segment P&L Dashboard (bookkeeping-cost-centers-dimensions Task 14,
 reporting-segment-results REQ-RSR-003).

 Shows revenue, costs and result per cost center, cost object, project or
 analytical dimension, for one period or all of them. The figures come from
 the `x-openregister-aggregations` declared on GLLine, which count only
 posted lines on profit and loss accounts (the stamps written by
 GLLineResultStampListener) and answer `{ groups: [{ key, values }] }`.
 Amounts are euros.

 @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
-->
<template>
	<NcAppContent>
		<div class="segment-pnl-dashboard">
			<header class="segment-pnl-dashboard__header">
				<h2 class="segment-pnl-dashboard__title">
					{{ t('shillinq', 'Segment P&L') }}
				</h2>
				<p class="segment-pnl-dashboard__description">
					{{
						t(
							'shillinq',
							'Revenue, costs and result per segment. Only posted lines on profit and loss accounts count.',
						)
					}}
				</p>
			</header>

			<section
				class="segment-pnl-dashboard__controls"
				:aria-label="t('shillinq', 'Segment and period')">
				<div class="segment-pnl-dashboard__chips">
					<NcButton
						v-for="segment in availableSegments"
						:key="segment.id"
						:variant="
							segment.id === activeSegment ? 'primary' : 'secondary'
						"
						@click="selectSegment(segment.id)">
						{{ segment.label }}
					</NcButton>
				</div>
				<div class="segment-pnl-dashboard__period">
					<label for="segment-pnl-period">{{ t('shillinq', 'Period') }}</label>
					<input
						id="segment-pnl-period"
						v-model="periodId"
						type="month"
						@change="loadSegment(activeSegment)">
				</div>
				<NcButton
					variant="tertiary"
					:disabled="!rows.length"
					@click="exportCsv">
					{{ t('shillinq', 'Export CSV') }}
				</NcButton>
			</section>

			<section class="segment-pnl-dashboard__body">
				<NcLoadingIcon
					v-if="loading"
					:size="32"
					:name="t('shillinq', 'Loading segment P&L')" />
				<NcEmptyContent
					v-else-if="!rows.length"
					:name="t('shillinq', 'No segment data')"
					:description="
						t(
							'shillinq',
							'No posted lines on profit and loss accounts carry this segment yet.',
						)
					" />
				<table
					v-else
					class="segment-pnl-dashboard__table"
					:data-segment="activeSegment">
					<thead>
						<tr>
							<th scope="col">
								{{ groupLabel }}
							</th>
							<th
								scope="col"
								class="segment-pnl-dashboard__amount-col">
								{{ t('shillinq', 'Revenue') }}
							</th>
							<th
								scope="col"
								class="segment-pnl-dashboard__amount-col">
								{{ t('shillinq', 'Costs') }}
							</th>
							<th
								scope="col"
								class="segment-pnl-dashboard__amount-col">
								{{ t('shillinq', 'Result') }}
							</th>
							<th v-if="hasHierarchy" scope="col">
								{{ t('shillinq', 'Parent') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in rows"
							:key="row.key"
							:class="{
								'segment-pnl-dashboard__row--child': row.depth > 0,
							}"
							:style="{ '--row-depth': row.depth }">
							<th
								scope="row"
								class="segment-pnl-dashboard__group-cell">
								<span class="segment-pnl-dashboard__group-code">{{
									row.key
								}}</span>
								<span
									v-if="row.name"
									class="segment-pnl-dashboard__group-name">
									{{ row.name }}
								</span>
							</th>
							<td class="segment-pnl-dashboard__amount-cell">
								{{ formatAmount(row.revenue) }}
							</td>
							<td class="segment-pnl-dashboard__amount-cell">
								{{ formatAmount(row.costs) }}
							</td>
							<td
								class="segment-pnl-dashboard__amount-cell"
								:class="{
									'segment-pnl-dashboard__amount-cell--negative':
										row.result < 0,
								}">
								{{ formatAmount(row.result) }}
							</td>
							<td v-if="hasHierarchy">
								{{ row.parent }}
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr>
							<th scope="row">
								{{ t('shillinq', 'Total') }}
							</th>
							<td class="segment-pnl-dashboard__amount-cell">
								{{ formatAmount(total.revenue) }}
							</td>
							<td class="segment-pnl-dashboard__amount-cell">
								{{ formatAmount(total.costs) }}
							</td>
							<td class="segment-pnl-dashboard__amount-cell">
								{{ formatAmount(total.result) }}
							</td>
							<td v-if="hasHierarchy" />
						</tr>
					</tfoot>
				</table>
				<p
					v-if="errorMessage"
					class="segment-pnl-dashboard__error"
					role="alert">
					{{ errorMessage }}
				</p>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppContent,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import {
	normaliseSegmentRows,
	SEGMENT_AGGREGATION,
	segmentQuery,
	segmentTotals,
} from '../../../utils/segmentResults.js'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'SegmentPnLDashboard',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			activeSegment: 'costCenter',
			rows: [],
			analyticalDimensions: [],
			administrationId: '',
			periodId: '',
		}
	},

	computed: {
		hasHierarchy() {
			return this.activeSegment === 'costCenterHierarchy'
		},

		groupLabel() {
			const map = {
				costCenter: this.t('shillinq', 'Cost center'),
				costCenterHierarchy: this.t('shillinq', 'Cost center (hierarchy)'),
				costObject: this.t('shillinq', 'Cost object'),
				project: this.t('shillinq', 'Project'),
				analyticalDimension: this.t('shillinq', 'Analytical dimension'),
			}
			return map[this.activeSegment] ?? this.t('shillinq', 'Segment')
		},

		total() {
			return segmentTotals(this.rows)
		},

		availableSegments() {
			return [
				{ id: 'costCenter', label: this.t('shillinq', 'Cost center') },
				{
					id: 'costCenterHierarchy',
					label: this.t('shillinq', 'Cost center (rolled up)'),
				},
				{ id: 'costObject', label: this.t('shillinq', 'Cost object') },
				{ id: 'project', label: this.t('shillinq', 'Project') },
				{
					id: 'analyticalDimension',
					label: this.t('shillinq', 'Analytical dimension'),
				},
			]
		},
	},

	mounted() {
		this.loadSegment(this.activeSegment)
	},

	methods: {
		/**
		 * Resolve the caller's active administration.
		 *
		 * These aggregations used to declare
		 * `administrationId: "@self.administrationId"`. That is not a
		 * placeholder OpenRegister implements — PlaceholderResolver only acts on
		 * values beginning with `$` — so it was left LITERAL and the filter
		 * matched nothing, which is why this dashboard returned zero rows over
		 * live data (#1216, swept in #1255).
		 *
		 * An administration is a shillinq layer over OpenRegister's organisation
		 * tenancy, so it is a normal property, and the scope comes from the
		 * CALLER as a narrowing filter (openregister#2852) — which can add a
		 * constraint but never relax one.
		 *
		 * Returns '' when the context cannot be read, and loadSegment() then
		 * REFUSES to query. That matters more here than it looks: the declared
		 * filter is gone, so an unscoped call would roll up EVERY
		 * administration's P&L into one plausible-looking total.
		 *
		 * @return {Promise<string>} The active administration id, or ''.
		 * @spec openspec/changes/bookkeeping-cost-centers-dimensions/tasks.md#task-14
		 */
		async resolveAdministrationId() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				return (
					data?.activeAdministrationId
					|| data?.administrations?.[0]?.administrationId
					|| ''
				)
			} catch {
				return ''
			}
		},

		/**
		 * Switch the dashboard to another segment and reload it.
		 *
		 * @param {string} segment One of SEGMENT_AGGREGATION's keys.
		 * @spec openspec/changes/bookkeeping-cost-centers-dimensions/tasks.md#task-14
		 */
		selectSegment(segment) {
			if (segment === this.activeSegment) {
				return
			}
			this.activeSegment = segment
			this.loadSegment(segment)
		},

		/**
		 * Load one segment's revenue, costs and result, scoped to the caller's
		 * administration and narrowed to the chosen period.
		 *
		 * @param {string} segment One of SEGMENT_AGGREGATION's keys.
		 * @spec openspec/changes/bookkeeping-cost-centers-dimensions/tasks.md#task-14
		 */
		async loadSegment(segment) {
			this.loading = true
			this.errorMessage = ''
			this.rows = []

			const aggregationName = SEGMENT_AGGREGATION[segment]
			if (!aggregationName) {
				this.errorMessage = this.t('shillinq', 'Unknown segment selected.')
				this.loading = false
				return
			}

			try {
				this.administrationId = await this.resolveAdministrationId()
				if (!this.administrationId) {
					// Deliberately not a fallback to an unscoped call: the
					// declaration no longer carries administrationId, so omitting
					// it here would roll up every administration the register
					// holds into one total that looks entirely reasonable.
					this.errorMessage = this.t(
						'shillinq',
						'No active administration, so the segment P&L cannot be scoped.',
					)
					return
				}

				const url = generateUrl(
					`/apps/openregister/api/objects/aggregations/${REGISTER_SLUG}/GLLine/`
						+ encodeURIComponent(aggregationName),
				)
				const { data } = await axios.get(url, {
					params: segmentQuery(this.administrationId, this.periodId),
				})
				this.rows = this.normaliseRows(data, segment)
			} catch (error) {
				const status = error?.response?.status
				if (status === 404 || status === 501) {
					// Older OR builds without the aggregation endpoint —
					// keep the dashboard navigable instead of breaking.
					this.errorMessage = this.t(
						'shillinq',
						'Aggregation endpoint unavailable on this OpenRegister build. Upgrade OR to read segment P&L roll-ups.',
					)
				} else if (status === 401 || status === 403) {
					this.errorMessage = this.t(
						'shillinq',
						'Permission required to read segment P&L data.',
					)
				} else {
					this.errorMessage = this.t(
						'shillinq',
						'Failed to load segment P&L.',
					)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Turn the aggregation envelope into rows, nested for the hierarchy.
		 *
		 * @param {object} payload The aggregation response.
		 * @param {string} segment The segment type.
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
		 */
		normaliseRows(payload, segment) {
			const flat = normaliseSegmentRows(payload)
			if (segment === 'costCenterHierarchy') {
				return this.applyHierarchy(flat)
			}
			return flat
		},

		applyHierarchy(flat) {
			// Compute parent-child depth for hierarchical display. Iterative —
			// no recursive call (avoids stack overflow on deep trees).
			const byKey = new Map(flat.map((r) => [r.key, r]))
			const result = []
			const seen = new Set()

			const visit = (row, depth) => {
				if (seen.has(row.key)) {
					return
				}
				seen.add(row.key)
				row.depth = depth
				result.push(row)
				for (const child of flat) {
					if (child.parent === row.key) {
						visit(child, depth + 1)
					}
				}
			}

			for (const row of flat) {
				if (!row.parent || !byKey.has(row.parent)) {
					visit(row, 0)
				}
			}

			// Append orphaned rows whose parent was not in the result set.
			for (const row of flat) {
				if (!seen.has(row.key)) {
					row.depth = 0
					result.push(row)
				}
			}

			return result
		},

		/**
		 * Format an amount in euros.
		 *
		 * @param {number} euros The amount.
		 * @return {string} The formatted amount.
		 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
		 */
		formatAmount(euros) {
			const value = Number(euros) || 0
			try {
				return new Intl.NumberFormat(undefined, {
					style: 'currency',
					currency: 'EUR',
					minimumFractionDigits: 2,
					maximumFractionDigits: 2,
				}).format(value)
			} catch (e) {
				return value.toFixed(2)
			}
		},

		/**
		 * Download the rows as CSV, one line per segment with revenue, costs and result.
		 *
		 * @spec openspec/changes/reporting-segment-results/tasks.md#task-2.2
		 */
		exportCsv() {
			if (!this.rows.length) {
				return
			}
			const header = ['segment', 'name', 'parent', 'revenue', 'costs', 'result']
			const lines = [header.join(',')]
			for (const row of this.rows) {
				const cells = [
					this.csvEscape(row.key),
					this.csvEscape(row.name),
					this.csvEscape(row.parent),
					row.revenue.toFixed(2),
					row.costs.toFixed(2),
					row.result.toFixed(2),
				]
				lines.push(cells.join(','))
			}
			const blob = new Blob([lines.join('\n') + '\n'], {
				type: 'text/csv;charset=utf-8',
			})
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download =
				'segment-pnl-'
				+ this.activeSegment
				+ '-'
				+ new Date().toISOString().slice(0, 10)
				+ '.csv'
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			URL.revokeObjectURL(url)
		},

		csvEscape(value) {
			if (value === null || value === undefined) {
				return ''
			}
			const str = String(value)
			if (str.includes(',') || str.includes('"') || str.includes('\n')) {
				return '"' + str.replace(/"/g, '""') + '"'
			}
			return str
		},
	},
}
</script>

<style scoped>
.segment-pnl-dashboard {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.segment-pnl-dashboard__title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.segment-pnl-dashboard__description {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast, #555);
}

.segment-pnl-dashboard__controls {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
	flex-wrap: wrap;
}

.segment-pnl-dashboard__chips {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
	flex-wrap: wrap;
}

.segment-pnl-dashboard__period {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: center;
}

.segment-pnl-dashboard__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.segment-pnl-dashboard__table th,
.segment-pnl-dashboard__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}

.segment-pnl-dashboard__amount-col,
.segment-pnl-dashboard__amount-cell {
	text-align: right;
	font-variant-numeric: tabular-nums;
}

.segment-pnl-dashboard__amount-cell--negative {
	color: var(--color-error, #d40000);
}

.segment-pnl-dashboard__row--child .segment-pnl-dashboard__group-cell {
	padding-left: calc(12px + var(--row-depth, 0) * 20px);
	font-weight: normal;
}

.segment-pnl-dashboard__group-code {
	font-weight: bold;
}

.segment-pnl-dashboard__group-name {
	color: var(--color-text-maxcontrast, #777);
	margin-left: 4px;
}

.segment-pnl-dashboard__table tfoot th,
.segment-pnl-dashboard__table tfoot td {
	font-weight: bold;
	border-top: 2px solid var(--color-border-dark, #aaa);
}

.segment-pnl-dashboard__error {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	color: var(--color-error, #d40000);
}
</style>
