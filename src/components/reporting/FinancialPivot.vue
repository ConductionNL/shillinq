<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Financial pivot (reporting-custom-analysis REQ-RCA-002).

 Sums the result of posted ledger lines over two axes the user picks
 (account, account group, month, quarter, cost centre, project, customer)
 for a date range, with row and column totals, and exports what it shows to
 CSV and Excel. The sums come from GET /api/analysis/pivot, which caps each
 axis at 200 groups and says when it did; the page repeats that under the
 table.

 A custom page: two axis pickers over a cross table fit none of the typed
 archetypes (an index lists records, a dashboard lays out widgets).

 @spec openspec/specs/financial-dashboard-graphs/spec.md
-->

<template>
	<div class="financial-pivot" data-testid="financial-pivot">
		<h2 class="financial-pivot__title">
			{{ t('shillinq', 'Financial pivot') }}
		</h2>
		<p class="financial-pivot__intro">
			{{
				t(
					'shillinq',
					'The result of posted ledger lines, revenue positive and costs negative, over two axes you choose.',
				)
			}}
		</p>

		<div class="financial-pivot__controls">
			<div class="financial-pivot__field">
				<label for="financial-pivot-rows">{{ t('shillinq', 'Rows') }}</label>
				<select
					id="financial-pivot-rows"
					v-model="rowAxis"
					data-testid="financial-pivot-rows">
					<option
						v-for="axis in axes"
						:key="axis.value"
						:value="axis.value">
						{{ axis.label }}
					</option>
				</select>
			</div>
			<div class="financial-pivot__field">
				<label for="financial-pivot-columns">{{
					t('shillinq', 'Columns')
				}}</label>
				<select
					id="financial-pivot-columns"
					v-model="columnAxis"
					data-testid="financial-pivot-columns">
					<option
						v-for="axis in axes"
						:key="axis.value"
						:value="axis.value">
						{{ axis.label }}
					</option>
				</select>
			</div>
			<div class="financial-pivot__field">
				<label for="financial-pivot-from">{{ t('shillinq', 'From') }}</label>
				<input id="financial-pivot-from" v-model="from" type="date" />
			</div>
			<div class="financial-pivot__field">
				<label for="financial-pivot-to">{{ t('shillinq', 'To') }}</label>
				<input id="financial-pivot-to" v-model="to" type="date" />
			</div>
			<NcButton
				variant="primary"
				:disabled="loading || rowAxis === columnAxis"
				data-testid="financial-pivot-show"
				@click="load">
				{{ t('shillinq', 'Show') }}
			</NcButton>
		</div>

		<p v-if="rowAxis === columnAxis" class="financial-pivot__hint">
			{{ t('shillinq', 'Choose two different axes for rows and columns.') }}
		</p>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-if="pivot && !loading">
			<p v-if="pivot.rows.length === 0" class="financial-pivot__empty">
				{{ t('shillinq', 'No posted result lines in this range.') }}
			</p>
			<div v-else class="financial-pivot__table-wrap">
				<table
					class="financial-pivot__table"
					data-testid="financial-pivot-table">
					<thead>
						<tr>
							<th
								v-for="(heading, index) in table[0]"
								:key="'h' + index"
								scope="col">
								{{ heading }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(row, rowIndex) in table.slice(1)"
							:key="'r' + rowIndex"
							:class="{
								'financial-pivot__total':
									rowIndex === table.length - 2,
							}">
							<th scope="row">
								{{ row[0] }}
							</th>
							<td
								v-for="(value, cellIndex) in row.slice(1)"
								:key="'c' + cellIndex"
								class="financial-pivot__amount">
								{{ formatAmount(value) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<p
				v-if="pivot.capped.rows || pivot.capped.columns"
				class="financial-pivot__capped"
				data-testid="financial-pivot-capped">
				{{
					t(
						'shillinq',
						'The table shows the 200 largest groups. The totals include every line.',
					)
				}}
			</p>
			<p v-if="pivot.truncated" class="financial-pivot__capped">
				{{
					t(
						'shillinq',
						'This range holds more ledger lines than one pivot reads. Choose a shorter range for complete figures.',
					)
				}}
			</p>
			<div v-if="pivot.rows.length > 0" class="financial-pivot__exports">
				<NcButton data-testid="financial-pivot-csv" @click="exportCsv">
					{{ t('shillinq', 'Export CSV') }}
				</NcButton>
				<NcButton data-testid="financial-pivot-excel" @click="exportExcel">
					{{ t('shillinq', 'Export to Excel') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	defaultRange,
	pivotTable,
	toCsv,
	toSpreadsheetXml,
} from '../../utils/financialPivot.js'

export default {
	name: 'FinancialPivot',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		const range = defaultRange(new Date())
		return {
			rowAxis: 'accountGroup',
			columnAxis: 'quarter',
			from: range.from,
			to: range.to,
			pivot: null,
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The axes the pickers offer, with their labels.
		 *
		 * @return {Array<{value: string, label: string}>} The axes.
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		axes() {
			return [
				{ value: 'account', label: this.t('shillinq', 'Account') },
				{
					value: 'accountGroup',
					label: this.t('shillinq', 'Account group'),
				},
				{ value: 'period', label: this.t('shillinq', 'Month') },
				{ value: 'quarter', label: this.t('shillinq', 'Quarter') },
				{ value: 'costCenter', label: this.t('shillinq', 'Cost centre') },
				{ value: 'project', label: this.t('shillinq', 'Project') },
				{ value: 'customer', label: this.t('shillinq', 'Customer') },
			]
		},

		/**
		 * The table the page shows and exports.
		 *
		 * @return {Array<Array<string|number|null>>} Header, rows and totals.
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		table() {
			if (!this.pivot) {
				return []
			}
			const rowAxis = this.axes.find(
				(axis) => axis.value === this.pivot.rowAxis,
			)
			return pivotTable(this.pivot, {
				rowHeader: rowAxis ? rowAxis.label : '',
				total: this.t('shillinq', 'Total'),
				notSet: this.t('shillinq', 'Not set'),
			})
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Fetch the pivot for the chosen axes and range.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/analysis/pivot'),
					{
						params: {
							rows: this.rowAxis,
							columns: this.columnAxis,
							from: this.from,
							to: this.to,
						},
					},
				)
				this.pivot = response.data
			} catch (e) {
				this.pivot = null
				this.error =
					(e.response && e.response.data && e.response.data.error)
					|| this.t('shillinq', 'The pivot could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * An amount in the user's number format; an empty cell stays empty.
		 *
		 * @param {number|null} value The amount.
		 * @return {string} The text.
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		formatAmount(value) {
			if (value === null || value === undefined) {
				return ''
			}
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'EUR',
			}).format(value)
		},

		/**
		 * Download the shown table as CSV.
		 *
		 * @return {void}
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		exportCsv() {
			this.download(toCsv(this.table), 'text/csv', 'csv')
		},

		/**
		 * Download the shown table as an Excel workbook.
		 *
		 * @return {void}
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		exportExcel() {
			this.download(
				toSpreadsheetXml(this.table, this.t('shillinq', 'Financial pivot')),
				'application/vnd.ms-excel',
				'xls',
			)
		},

		/**
		 * Hand a file to the browser.
		 *
		 * @param {string} content The file content.
		 * @param {string} type The media type.
		 * @param {string} extension The file extension.
		 * @return {void}
		 * @spec openspec/specs/financial-dashboard-graphs/spec.md
		 */
		download(content, type, extension) {
			const url = URL.createObjectURL(new Blob([content], { type }))
			const link = document.createElement('a')
			link.href = url
			link.download =
				'pivot-'
				+ this.pivot.rowAxis
				+ '-'
				+ this.pivot.columnAxis
				+ '-'
				+ this.from
				+ '-'
				+ this.to
				+ '.'
				+ extension
			link.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.financial-pivot {
	padding: calc(var(--default-grid-baseline) * 4);
	max-width: 100%;
}

.financial-pivot__controls {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 3);
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.financial-pivot__field {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.financial-pivot__table-wrap {
	overflow-x: auto;
}

.financial-pivot__table {
	border-collapse: collapse;
	width: 100%;
}

.financial-pivot__table th,
.financial-pivot__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	white-space: nowrap;
}

.financial-pivot__amount {
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.financial-pivot__total {
	font-weight: bold;
}

.financial-pivot__capped,
.financial-pivot__hint,
.financial-pivot__empty {
	color: var(--color-text-maxcontrast);
}

.financial-pivot__exports {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 3);
}
</style>
