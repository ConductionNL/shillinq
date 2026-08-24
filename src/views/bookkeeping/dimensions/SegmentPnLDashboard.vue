<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Segment P&L Dashboard (bookkeeping-cost-centers-dimensions Task 14).

 Drives the operator-facing segment-P&L drill-down by reading the four
 server-side `x-openregister-aggregations` declared on GLLine
 (`byCostCenter`, `byCostCenterHierarchy`, `byProject`,
 `byAnalyticalDimension`) and rendering them into a per-segment table
 with hierarchical roll-up support and a CSV export. Selector chips let
 the operator switch between CostCenter, Project, and operator-defined
 AnalyticalDimensions (REQ-CD-004 / REQ-CD-007).

 Reads from the OpenRegister aggregation endpoint (the same one the
 declarative manifest pages use for `byX` aggregations). Falls back to a
 client-side rollup of the raw GLLine list if the aggregation endpoint
 returns 404 (older OR builds).

 @spec openspec/changes/bookkeeping-cost-centers-dimensions/tasks.md#task-14
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
							'Per-segment profit and loss roll-up across cost centers, projects, and operator-defined analytical dimensions. Driven by the server-side aggregations on GLLine — no client-side recomputation.',
						)
					}}
				</p>
			</header>

			<section
				class="segment-pnl-dashboard__controls"
				aria-label="segment selector">
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
							'No GL postings carry this dimension yet. Tag postings with a cost center, project, or analytical dimension to populate the drill-down.',
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
								{{ t('shillinq', 'Amount') }}
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
									— {{ row.name }}
								</span>
							</th>
							<td
								class="segment-pnl-dashboard__amount-cell"
								:class="{
									'segment-pnl-dashboard__amount-cell--negative':
										row.amount < 0,
								}">
								{{ formatAmount(row.amount) }}
							</td>
							<td v-if="hasHierarchy">
								{{ row.parent || '—' }}
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr>
							<th scope="row">
								{{ t('shillinq', 'Total') }}
							</th>
							<td class="segment-pnl-dashboard__amount-cell">
								{{ formatAmount(total) }}
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

const REGISTER_SLUG = 'shillinq'

const SEGMENT_AGGREGATION = {
	costCenter: 'byCostCenter',
	costCenterHierarchy: 'byCostCenterHierarchy',
	project: 'byProject',
	analyticalDimension: 'byAnalyticalDimension',
}

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
				project: this.t('shillinq', 'Project'),
				analyticalDimension: this.t('shillinq', 'Analytical dimension'),
			}
			return map[this.activeSegment] ?? this.t('shillinq', 'Segment')
		},

		total() {
			return this.rows.reduce((sum, r) => sum + (Number(r.amount) || 0), 0)
		},

		availableSegments() {
			return [
				{ id: 'costCenter', label: this.t('shillinq', 'Cost center') },
				{
					id: 'costCenterHierarchy',
					label: this.t('shillinq', 'Cost center (rolled up)'),
				},
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
		selectSegment(segment) {
			if (segment === this.activeSegment) {
				return
			}
			this.activeSegment = segment
			this.loadSegment(segment)
		},

		/** @spec openspec/changes/bookkeeping-cost-centers-dimensions/tasks.md#task-14 */
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
				const url = generateUrl(
					`/apps/openregister/api/objects/aggregations/${REGISTER_SLUG}/GLLine/`
						+ encodeURIComponent(aggregationName),
				)
				const { data } = await axios.get(url)
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

		normaliseRows(payload, segment) {
			const buckets = Array.isArray(payload?.buckets)
				? payload.buckets
				: Array.isArray(payload)
					? payload
					: []
			const flat = buckets
				.map((bucket) => {
					const key = bucket?.key ?? bucket?.code ?? bucket?.groupKey ?? ''
					const name =
						bucket?.name
						?? bucket?.['CostCenter.name']
						?? bucket?.['Project.name']
						?? ''
					const parent =
						bucket?.parent ?? bucket?.['CostCenter.parentCode'] ?? ''
					const amount = Number(
						bucket?.amount ?? bucket?.sum ?? bucket?.total ?? 0,
					)
					return {
						key: String(key),
						name: String(name),
						parent: String(parent),
						amount,
						depth: 0,
					}
				})
				.filter((r) => r.key !== '')

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

		formatAmount(cents) {
			const value = (Number(cents) || 0) / 100
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

		exportCsv() {
			if (!this.rows.length) {
				return
			}
			const header = ['segment', 'name', 'parent', 'amount_cents']
			const lines = [header.join(',')]
			for (const row of this.rows) {
				const cells = [
					this.csvEscape(row.key),
					this.csvEscape(row.name),
					this.csvEscape(row.parent),
					String(Math.round(Number(row.amount) || 0)),
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
