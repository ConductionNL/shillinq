<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Multi-year budget, a section of the budget grid page (planning-budget-editing, REQ-PBE-003). Ledger groups
 against every fiscal year with an annual budget, from the current year up to
 four ahead, each cell the year's total. Start next year copies a chosen
 budget's manual lines into a draft budget for the following year, raised by
 a percentage.

 @spec openspec/changes/archive/2026-09-29-planning-budget-editing/specs/budget-grid-view/spec.md
-->
<template>
	<section class="multi-year" data-testid="multi-year">
		<h3>{{ t('shillinq', 'Multi-year budget') }}</h3>
		<p class="multi-year__description">
			{{
				t(
					'shillinq',
					'Each ledger group per fiscal year, from this year up to four years ahead. Start next year from a budget to copy its typed amounts with a percentage.',
				)
			}}
		</p>

		<form
			class="multi-year__start"
			data-testid="multi-year-start"
			@submit.prevent="start">
			<div class="multi-year__control">
				<label for="multi-year-source">{{
					t('shillinq', 'Start from')
				}}</label>
				<select
					id="multi-year-source"
					v-model="sourceId"
					data-testid="multi-year-source">
					<option
						v-for="budget in budgets"
						:key="budget.annualBudgetId"
						:value="budget.annualBudgetId">
						{{ budget.fiscalYear }}: {{ budget.name }}
					</option>
				</select>
			</div>
			<div class="multi-year__control">
				<label for="multi-year-percentage">{{
					t('shillinq', 'Change in percent')
				}}</label>
				<input
					id="multi-year-percentage"
					v-model="percentage"
					inputmode="decimal"
					data-testid="multi-year-percentage" />
			</div>
			<NcButton variant="primary" type="submit" :disabled="!sourceId || busy">
				{{ t('shillinq', 'Start next year') }}
			</NcButton>
		</form>

		<p v-if="message" class="multi-year__message" role="status">
			{{ message }}
		</p>

		<NcLoadingIcon
			v-if="loading"
			:size="32"
			:name="t('shillinq', 'Loading the multi-year budget')" />
		<NcEmptyContent
			v-else-if="!years.length"
			:name="t('shillinq', 'No budgets in these years')"
			:description="
				t(
					'shillinq',
					'Create an annual budget for this year or a later one to see it here.',
				)
			" />
		<div v-else class="multi-year__table-wrapper">
			<table class="multi-year__table" data-testid="multi-year-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('shillinq', 'Ledger group') }}
						</th>
						<th v-for="year in years" :key="year.fiscalYear" scope="col">
							{{ year.fiscalYear }}
							<span class="multi-year__state">{{
								stateLabel(year.state)
							}}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="row.ledgerGroupId"
						data-testid="multi-year-row">
						<th
							scope="row"
							:style="{
								paddingInlineStart: row.depth * 20 + 8 + 'px',
							}">
							{{ row.name }}
						</th>
						<td
							v-for="year in years"
							:key="year.fiscalYear"
							class="multi-year__amount">
							{{ formatEuro(row.amounts[year.fiscalYear]) }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { loadMultiYear, refusal, startNextYear } from '../utils/budgetEditingApi.js'

export default {
	name: 'MultiYearBudget',

	components: { NcButton, NcEmptyContent, NcLoadingIcon },

	props: {
		administrationId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			years: [],
			rows: [],
			budgets: [],
			sourceId: '',
			percentage: '3',
			message: '',
		}
	},

	watch: {
		/**
		 * Reload when the administration is known.
		 *
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
		 */
		administrationId() {
			this.load()
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the years, rows and budgets.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
		 */
		async load() {
			this.loading = true
			if (!this.administrationId) {
				this.loading = false
				return
			}
			try {
				const view = await loadMultiYear(
					this.administrationId,
					new Date().getFullYear(),
				)
				this.years = view.years || []
				this.rows = view.rows || []
				this.budgets = view.budgets || []
				if (!this.sourceId && this.budgets.length) {
					this.sourceId = this.budgets[0].annualBudgetId
				}
			} catch (error) {
				this.message = refusal(
					error,
					t('shillinq', 'The budgets could not be loaded.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Start next year's budget from the chosen one.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
		 */
		async start() {
			const percentage = Number(String(this.percentage).replace(',', '.'))
			if (!Number.isFinite(percentage)) {
				this.message = t('shillinq', 'Enter a percentage, for example 3.')
				return
			}
			this.busy = true
			try {
				const created = await startNextYear(
					this.administrationId,
					this.sourceId,
					percentage,
				)
				this.message = t('shillinq', 'Draft budget {year} started.', {
					year: created.fiscalYear,
				})
				await this.load()
			} catch (error) {
				this.message = refusal(
					error,
					t('shillinq', 'The budget could not be started.'),
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Cents as euros in the user's locale.
		 *
		 * @param {number} cents The amount.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
		 */
		formatEuro(cents) {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			}).format(Number(cents || 0) / 100)
		},

		/**
		 * The label of a budget state.
		 *
		 * @param {string} state The state.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
		 */
		stateLabel(state) {
			const labels = {
				draft: t('shillinq', 'draft'),
				active: t('shillinq', 'active'),
				closed: t('shillinq', 'closed'),
			}
			return labels[state] || state
		},
	},
}
</script>

<style scoped>
.multi-year {
	margin-top: calc(var(--default-grid-baseline, 4px) * 6);
}

.multi-year__description,
.multi-year__message,
.multi-year__state {
	color: var(--color-text-maxcontrast);
}

.multi-year__state {
	display: block;
	font-weight: normal;
	font-size: 0.85em;
}

.multi-year__start {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 16px;
	margin: calc(var(--default-grid-baseline, 4px) * 3) 0;
}

.multi-year__control {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.multi-year__table-wrapper {
	overflow-x: auto;
}

.multi-year__table th,
.multi-year__table td {
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	white-space: nowrap;
}

.multi-year__amount {
	text-align: end;
	font-variant-numeric: tabular-nums;
}
</style>
