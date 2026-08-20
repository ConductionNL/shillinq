<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget Grid (`budget-grid-view`, REQ-BGV-001..009).

 The year-basis begroting screen the user asked for: rows are the current
 administration's LedgerGroup tree (verzamelposten, expandable to child
 groups or resolved grootboek accounts), columns are a caller-selected
 period range/granularity, past columns show actuals + a text-labelled
 deviation from budget, and the final TOTAAL column carries the running
 begroot/werkelijk cumulative pair. The whole payload (every row, every
 column's value, already computed) is fetched ONCE from GET /api/budget-grid
 on mount and whenever the range/granularity controls change; expand/collapse
 is a pure client-side operation over the already-fetched tree and issues
 ZERO further requests (design.md §1c).

 Row toggle follows BudgetLineCommitments.vue's own ADR-059-compliant
 pattern (tabindex="0", role="button", :aria-expanded, @click + @keyup.enter)
 and additionally binds @keyup.space (design.md §6, closing the Space-key
 gap that precedent left open). A grootboek (Account) leaf row is a real
 navigation link to ChartOfAccountsDetail, not a toggle (REQ-BGV-007).

 @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md
-->
<template>
	<NcAppContent>
		<div class="budget-grid">
			<header class="budget-grid__header">
				<h2 class="budget-grid__title">
					{{ t('shillinq', 'Budget grid') }}
				</h2>
				<p class="budget-grid__description">
					{{
						t(
							'shillinq',
							'Ledger groups roll up GL accounts across a selectable period range. Past periods show actuals and the deviation from budget; the final column carries the running cumulative totals.',
						)
					}}
				</p>

				<div class="budget-grid__controls">
					<div class="budget-grid__control">
						<label for="budget-grid-start-period">{{
							t('shillinq', 'Start period')
						}}</label>
						<input
							id="budget-grid-start-period"
							v-model="startPeriod"
							type="month"
							data-testid="budget-grid-start-period"
							@change="loadGrid" />
					</div>
					<div class="budget-grid__control">
						<label for="budget-grid-end-period">{{
							t('shillinq', 'End period')
						}}</label>
						<input
							id="budget-grid-end-period"
							v-model="endPeriod"
							type="month"
							data-testid="budget-grid-end-period"
							@change="loadGrid" />
					</div>
					<div class="budget-grid__control">
						<label for="budget-grid-granularity">{{
							t('shillinq', 'Granularity')
						}}</label>
						<select
							id="budget-grid-granularity"
							v-model="granularity"
							data-testid="budget-grid-granularity"
							@change="loadGrid">
							<option value="month">
								{{ t('shillinq', 'Month') }}
							</option>
							<option value="quarter">
								{{ t('shillinq', 'Quarter') }}
							</option>
							<option value="year">
								{{ t('shillinq', 'Year') }}
							</option>
						</select>
					</div>
				</div>
			</header>

			<section class="budget-grid__body">
				<NcLoadingIcon
					v-if="loading"
					:size="32"
					:name="t('shillinq', 'Loading budget grid')" />
				<NcEmptyContent
					v-else-if="!rows.length"
					:name="t('shillinq', 'No ledger groups')"
					:description="
						t(
							'shillinq',
							'No LedgerGroup records exist yet for this administration. Create ledger groups to build a budget.',
						)
					" />
				<div v-else class="budget-grid__table-wrapper">
					<table class="budget-grid__table">
						<thead>
							<tr>
								<th scope="col" class="budget-grid__row-header-col">
									{{ t('shillinq', 'Ledger group') }}
								</th>
								<th
									v-for="column in columns"
									:key="column.key"
									scope="col"
									data-testid="budget-grid-column-header"
									:class="{
										'budget-grid__total-col': column.isTotal,
									}"
									:data-testid-total="
										column.isTotal
											? 'budget-grid-total-column'
											: null
									">
									{{ column.label }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="row in visibleRows"
								:key="row.id"
								class="budget-grid__row"
								data-testid="budget-grid-row"
								:data-row-kind="row.kind">
								<th
									scope="row"
									class="budget-grid__row-header"
									:style="{
										paddingInlineStart:
											row.depth * 20 + 8 + 'px',
									}">
									<button
										v-if="
											row.kind === 'ledgerGroup'
											&& row.hasChildren
										"
										type="button"
										class="budget-grid__toggle"
										data-testid="budget-grid-expand-toggle"
										:aria-expanded="expandedIds.has(row.id)"
										@click="toggleRow(row.id)"
										@keyup.enter="toggleRow(row.id)"
										@keyup.space="toggleRow(row.id)">
										<ChevronDown
											v-if="expandedIds.has(row.id)"
											:size="16" />
										<ChevronRight v-else :size="16" />
										{{ row.label }}
									</button>
									<router-link
										v-else-if="row.kind === 'account'"
										:to="row.route"
										class="budget-grid__account-link"
										data-testid="budget-grid-account-link">
										{{ row.label }} ({{ row.accountNumber }})
									</router-link>
									<span v-else>{{ row.label }}</span>
								</th>
								<td
									v-for="column in columns"
									:key="column.key"
									class="budget-grid__cell"
									:class="{
										'budget-grid__total-col': column.isTotal,
									}">
									<BudgetGridCell
										:cell="row.cells[column.key]"
										:isAccount="row.kind === 'account'" />
								</td>
							</tr>
						</tbody>
						<tfoot v-if="computedRows.length">
							<tr
								v-for="row in computedRows"
								:key="row.code"
								class="budget-grid__computed-row"
								data-testid="budget-grid-row"
								data-row-kind="computed">
								<th scope="row">{{ row.label }}</th>
								<td
									v-for="column in columns"
									:key="column.key"
									class="budget-grid__cell"
									:class="{
										'budget-grid__total-col': column.isTotal,
									}">
									<BudgetGridCell
										:cell="row.cells[column.key]"
										:isAccount="false" />
								</td>
							</tr>
						</tfoot>
					</table>
				</div>
				<p v-if="errorMessage" class="budget-grid__error" role="alert">
					{{ errorMessage }}
				</p>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import BudgetGridCell from '../components/BudgetGridCell.vue'
import { fetchAdministrationContext } from '../api/administrationApi.js'
import { defaultRange, flattenVisibleRows } from './budgetGridHelpers.js'

export default {
	name: 'BudgetGrid',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		ChevronDown,
		ChevronRight,
		BudgetGridCell,
	},

	data() {
		const range = defaultRange()
		return {
			loading: true,
			errorMessage: '',
			administrationId: null,
			startPeriod: range.startPeriod,
			endPeriod: range.endPeriod,
			granularity: range.granularity,
			columns: [],
			rows: [],
			computedRows: [],
			expandedIds: new Set(),
		}
	},

	computed: {
		/**
		 * The currently-visible flat row list, derived purely from the
		 * already-fetched tree + expandedIds — zero network calls
		 * (design.md §1c, REQ-BGV-002).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
		 */
		visibleRows() {
			return flattenVisibleRows(this.rows, this.expandedIds)
		},
	},

	/**
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
	 */
	async mounted() {
		await this.resolveAdministration()
		await this.loadGrid()
	},

	methods: {
		/**
		 * Resolve the operator's currently-active administration the same
		 * way every other multi-administration page in this app does
		 * (AdministratieSwitcher's own session context) — this page does not
		 * add its own administration selector (design.md §1a).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
		 */
		async resolveAdministration() {
			try {
				const context = await fetchAdministrationContext()
				this.administrationId = context?.activeAdministrationId || null
			} catch {
				this.administrationId = null
			}
		},

		/**
		 * Fetch the whole grid payload (row tree + columns + computed rows)
		 * for the current administration/range/granularity in ONE request.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-001
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
		 */
		async loadGrid() {
			if (!this.administrationId) {
				this.loading = false
				this.errorMessage = this.t(
					'shillinq',
					'No accessible administration.',
				)
				return
			}

			this.loading = true
			this.errorMessage = ''

			try {
				const url = generateUrl('/apps/shillinq/api/budget-grid')
				const { data } = await axios.get(url, {
					params: {
						administrationId: this.administrationId,
						startPeriod: this.startPeriod,
						endPeriod: this.endPeriod,
						granularity: this.granularity,
					},
				})
				this.columns = Array.isArray(data?.columns) ? data.columns : []
				this.rows = Array.isArray(data?.rows) ? data.rows : []
				this.computedRows = Array.isArray(data?.computedRows)
					? data.computedRows
					: []
				this.expandedIds = new Set()
			} catch (error) {
				const status = error?.response?.status
				if (status === 404) {
					this.errorMessage = this.t(
						'shillinq',
						'Administration not found.',
					)
				} else if (status === 401) {
					this.errorMessage = this.t('shillinq', 'Not logged in.')
				} else {
					this.errorMessage = this.t(
						'shillinq',
						'Failed to load the budget grid.',
					)
				}
				this.columns = []
				this.rows = []
				this.computedRows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Toggle a row's expand state — a pure local state change, no
		 * network request (design.md §1c, REQ-BGV-002 scenario 3).
		 *
		 * @param {string} id The row id to toggle.
		 * @return {void}
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
		 */
		toggleRow(id) {
			const next = new Set(this.expandedIds)
			if (next.has(id)) {
				next.delete(id)
			} else {
				next.add(id)
			}
			this.expandedIds = next
		},
	},
}
</script>

<style scoped>
.budget-grid {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.budget-grid__title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.budget-grid__description {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast, #555);
}

.budget-grid__controls {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
}

.budget-grid__control {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.budget-grid__table-wrapper {
	overflow-x: auto;
}

.budget-grid__table {
	width: 100%;
	border-collapse: collapse;
}

.budget-grid__table th,
.budget-grid__table td {
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
	white-space: nowrap;
}

.budget-grid__row-header-col {
	min-width: 220px;
}

.budget-grid__toggle {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	cursor: pointer;
	font: inherit;
	color: inherit;
	padding: 4px;
}

.budget-grid__toggle:focus-visible {
	outline: 2px solid var(--color-primary-element, #0082c9);
}

.budget-grid__account-link {
	padding: 4px;
	display: inline-block;
}

.budget-grid__cell {
	text-align: right;
	font-variant-numeric: tabular-nums;
}

.budget-grid__total-col {
	font-weight: bold;
	border-inline-start: 2px solid var(--color-border-dark, #999);
}

.budget-grid__computed-row th {
	font-weight: bold;
}

.budget-grid__error {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	color: var(--color-error, #d40000);
}
</style>
