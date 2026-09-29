<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget entry on the budget grid page (planning-budget-editing, REQ-PBE-001,
 REQ-PBE-002). Ledger groups against the twelve months of one annual budget:
 a manual row (or a group without a line) is typed into, a row from another
 source is read-only and names its source. A cell saves on Enter or when it
 loses focus, sending the amount it started from; a refused stale save
 reloads the rows with the other person's value. Arrow keys and Enter move
 between cells.

 @spec openspec/changes/archive/2026-09-29-planning-budget-editing/specs/budget-grid-view/spec.md
-->
<template>
	<section class="budget-entry" data-testid="budget-entry">
		<div class="budget-entry__header">
			<h3>{{ t('shillinq', 'Enter the budget') }}</h3>
			<div class="budget-entry__control">
				<label for="budget-entry-budget">{{ t('shillinq', 'Budget') }}</label>
				<select
					id="budget-entry-budget"
					v-model="annualBudgetId"
					data-testid="budget-entry-budget"
					@change="load">
					<option value="">
						{{ t('shillinq', 'Choose a budget') }}
					</option>
					<option v-for="budget in budgets" :key="budget.annualBudgetId" :value="budget.annualBudgetId">
						{{ budget.fiscalYear }}: {{ budget.name }}
					</option>
				</select>
			</div>
		</div>

		<p v-if="message" class="budget-entry__message" role="status">
			{{ message }}
		</p>

		<div v-if="rows.length" class="budget-entry__table-wrapper">
			<table class="budget-entry__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('shillinq', 'Ledger group') }}
						</th>
						<th v-for="(label, index) in monthLabels" :key="index" scope="col">
							{{ label }}
						</th>
						<th scope="col">
							{{ t('shillinq', 'Year') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{ t('shillinq', 'Actions') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, rowIndex) in rows" :key="row.ledgerGroupId" data-testid="budget-entry-row">
						<th scope="row" :style="{ paddingInlineStart: row.depth * 20 + 8 + 'px' }">
							{{ row.name }}
							<span v-if="!row.editable" class="budget-entry__source" data-testid="budget-entry-source">
								{{ sourceLabel(row.source) }}
							</span>
						</th>
						<td v-for="(amount, col) in row.months" :key="col">
							<input
								v-if="row.editable"
								:ref="cellRef(rowIndex, col)"
								:value="drafts[cellKey(rowIndex, col)] ?? centsToInput(amount)"
								class="budget-entry__cell"
								inputmode="decimal"
								:aria-label="t('shillinq', '{group}, {month}', { group: row.name, month: monthLabels[col] })"
								data-testid="budget-entry-cell"
								@input="drafts = { ...drafts, [cellKey(rowIndex, col)]: $event.target.value }"
								@keydown="onKey($event, rowIndex, col)"
								@blur="save(rowIndex, col)">
							<span
								v-else
								tabindex="0"
								class="budget-entry__readonly"
								:aria-label="t('shillinq', '{amount}, {source}, read-only', { amount: centsToInput(amount), source: sourceLabel(row.source) })">
								{{ centsToInput(amount) }}
							</span>
						</td>
						<td class="budget-entry__total">
							{{ centsToInput(row.total) }}
						</td>
						<td>
							<NcButton
								v-if="row.editable"
								variant="tertiary"
								data-testid="budget-entry-spread"
								@click="openSpread(rowIndex)">
								{{ t('shillinq', 'Spread over months') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<form
			v-if="spreadRow !== null"
			class="budget-entry__spread"
			data-testid="budget-entry-spread-form"
			@submit.prevent="spread">
			<label for="budget-entry-yearly">
				{{ t('shillinq', 'Yearly amount for {group}', { group: rows[spreadRow].name }) }}
			</label>
			<input id="budget-entry-yearly" v-model="yearly" inputmode="decimal">
			<NcButton variant="primary" type="submit">
				{{ t('shillinq', 'Spread') }}
			</NcButton>
			<NcButton variant="tertiary" @click="spreadRow = null">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
		</form>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import {
	centsToInput,
	euroToCents,
	loadBudgetLines,
	loadMultiYear,
	nextCell,
	refusal,
	saveBudgetCell,
	spreadBudgetRow,
} from '../utils/budgetEditingApi.js'

export default {
	name: 'BudgetLinesEditor',

	components: { NcButton },

	props: {
		administrationId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			budgets: [],
			annualBudgetId: '',
			rows: [],
			drafts: {},
			message: '',
			spreadRow: null,
			yearly: '',
		}
	},

	computed: {
		/**
		 * Short month names in the user's language.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		monthLabels() {
			const format = new Intl.DateTimeFormat(undefined, { month: 'short' })
			return Array.from({ length: 12 }, (_, month) => format.format(new Date(2026, month, 1)))
		},
	},

	watch: {
		/**
		 * Reload the budgets when the administration is known.
		 *
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		administrationId() {
			this.loadBudgets()
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	mounted() {
		this.loadBudgets()
	},

	methods: {
		centsToInput,

		/**
		 * The administration's budgets, newest year first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		async loadBudgets() {
			if (!this.administrationId) {
				return
			}
			try {
				const view = await loadMultiYear(this.administrationId, new Date().getFullYear())
				this.budgets = view.budgets || []
			} catch (error) {
				this.message = refusal(error, t('shillinq', 'The budgets could not be loaded.'))
			}
		},

		/**
		 * The rows of the chosen budget.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		async load() {
			this.drafts = {}
			this.rows = []
			if (!this.annualBudgetId) {
				return
			}
			try {
				const data = await loadBudgetLines(this.administrationId, this.annualBudgetId)
				this.rows = data.rows || []
			} catch (error) {
				this.message = refusal(error, t('shillinq', 'The budget could not be loaded.'))
			}
		},

		/**
		 * The key of a cell in the drafts.
		 *
		 * @param {number} row The row index.
		 * @param {number} col The month index.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		cellKey(row, col) {
			return `${row}-${col}`
		},

		/**
		 * The ref name of a cell.
		 *
		 * @param {number} row The row index.
		 * @param {number} col The month index.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		cellRef(row, col) {
			return `cell-${row}-${col}`
		},

		/**
		 * The label of a line source.
		 *
		 * @param {string} source The source.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		sourceLabel(source) {
			const labels = {
				contract: t('shillinq', 'from contracts'),
				recurring: t('shillinq', 'from recurring items'),
				projected: t('shillinq', 'projected'),
				scenario: t('shillinq', 'from a scenario'),
			}
			return labels[source] || source
		},

		/**
		 * Move between cells with the arrow keys and Enter; Enter saves first.
		 *
		 * @param {KeyboardEvent} event The key press.
		 * @param {number} row The row index.
		 * @param {number} col The month index.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		async onKey(event, row, col) {
			const input = event.target
			const atStart = input.selectionStart === 0 && input.selectionEnd === 0
			const atEnd = input.selectionStart === input.value.length
			const next = nextCell(event.key, { row, col }, this.rows.length, atStart, atEnd)
			if (event.key === 'Enter') {
				event.preventDefault()
				await this.save(row, col)
			}
			if (next === null) {
				return
			}
			event.preventDefault()
			const target = this.$refs[this.cellRef(next.row, next.col)]
			const element = Array.isArray(target) ? target[0] : target
			element?.focus()
		},

		/**
		 * Save a typed cell, sending the amount it started from.
		 *
		 * @param {number} row The row index.
		 * @param {number} col The month index.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
		 */
		async save(row, col) {
			const key = this.cellKey(row, col)
			if (!(key in this.drafts)) {
				return
			}
			const current = this.rows[row]
			const amount = euroToCents(this.drafts[key])
			if (amount === null) {
				this.message = t('shillinq', 'Enter an amount in euros, for example 206000.')
				return
			}
			const rest = { ...this.drafts }
			delete rest[key]
			this.drafts = rest
			if (amount === current.months[col]) {
				return
			}
			try {
				const saved = await saveBudgetCell({
					administrationId: this.administrationId,
					annualBudgetId: this.annualBudgetId,
					ledgerGroupId: current.ledgerGroupId,
					month: col + 1,
					amount,
					expected: current.months[col],
				})
				this.rows.splice(row, 1, { ...current, ...saved })
				this.message = ''
			} catch (error) {
				this.message = refusal(error, t('shillinq', 'The amount could not be saved.'))
				await this.load()
			}
		},

		/**
		 * Open the spread form for a row.
		 *
		 * @param {number} row The row index.
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
		 */
		openSpread(row) {
			this.spreadRow = row
			this.yearly = centsToInput(this.rows[row].total)
		},

		/**
		 * Spread the yearly amount over the row's months.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
		 */
		async spread() {
			const row = this.spreadRow
			const yearly = euroToCents(this.yearly)
			if (yearly === null) {
				this.message = t('shillinq', 'Enter an amount in euros, for example 206000.')
				return
			}
			const current = this.rows[row]
			try {
				const saved = await spreadBudgetRow({
					administrationId: this.administrationId,
					annualBudgetId: this.annualBudgetId,
					ledgerGroupId: current.ledgerGroupId,
					yearly,
					expected: current.months,
				})
				this.rows.splice(row, 1, { ...current, ...saved })
				this.spreadRow = null
				this.message = ''
			} catch (error) {
				this.message = refusal(error, t('shillinq', 'The amount could not be saved.'))
				await this.load()
			}
		},
	},
}
</script>

<style scoped>
.budget-entry {
	margin-top: calc(var(--default-grid-baseline, 4px) * 6);
}

.budget-entry__header {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: 16px;
}

.budget-entry__control {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.budget-entry__table-wrapper {
	overflow-x: auto;
}

.budget-entry__table th,
.budget-entry__table td {
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
	text-align: start;
}

.budget-entry__cell {
	width: 9em;
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.budget-entry__readonly,
.budget-entry__total {
	font-variant-numeric: tabular-nums;
}

.budget-entry__source {
	margin-inline-start: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.budget-entry__message {
	color: var(--color-text-maxcontrast);
}

.budget-entry__spread {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: calc(var(--default-grid-baseline, 4px) * 3);
}
</style>
