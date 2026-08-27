<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BudgetGrid cell renderer (`budget-grid-view`, REQ-BGV-003/004).

 Renders one column's value for one row: a future column shows the budget
 amount only; a past column shows the actual amount and a TEXT-LABELLED
 favorable/unfavorable deviation (never colour alone — WCAG 2.1 AA, the same
 rule BudgetLineCommitments.vue's own text-labelled columns already follow).
 An Account leaf row (REQ-BGV-007) has no budget at all — BudgetLine is
 LedgerGroup-scoped, not Account-scoped — so it renders actual-only.

 @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
-->
<template>
	<span class="budget-grid-cell">
		<template v-if="!cell">—</template>
		<template v-else-if="isAccount">
			{{ formatAmount(cell.actual) }}
		</template>
		<template v-else>
			<div class="budget-grid-cell__budget">
				{{ formatAmount(cell.budget) }}
			</div>
			<div
				v-if="cell.actual !== null && cell.actual !== undefined"
				class="budget-grid-cell__actual">
				{{
					t('shillinq', 'Actual: {amount}', {
						amount: formatAmount(cell.actual),
					})
				}}
			</div>
			<div
				v-if="deviationState !== null"
				class="budget-grid-cell__deviation"
				:class="{
					'budget-grid-cell__deviation--favorable':
						deviationState === true,
					'budget-grid-cell__deviation--unfavorable':
						deviationState === false,
				}">
				{{ deviationLabel }} {{ formatAmount(cell.deviation) }}
			</div>
			<div
				v-else-if="cell.deviation !== null && cell.deviation !== undefined"
				class="budget-grid-cell__deviation">
				{{
					t('shillinq', 'Difference: {amount}', {
						amount: formatAmount(cell.deviation),
					})
				}}
			</div>
		</template>
	</span>
</template>

<script>
import { favorableState, formatAmount } from '../views/budgetGridHelpers.js'

export default {
	name: 'BudgetGridCell',

	props: {
		/**
		 * The cell payload from the API: `{budget, actual, deviation,
		 * favorable}` for a LedgerGroup/computed row, `{actual}` for an
		 * Account leaf row.
		 */
		cell: {
			type: Object,
			default: null,
		},

		/**
		 * Whether this cell belongs to an Account leaf row (actual-only,
		 * REQ-BGV-007) rather than a LedgerGroup/computed row.
		 */
		isAccount: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		/**
		 * true/false when the deviation carries an explicit
		 * favorable/unfavorable framing (REQ-BGV-004); null when no framing
		 * applies (balance-sheet or mixed-accountType rows, §9.1) — in that
		 * case the raw difference is still shown, just without the
		 * favorable/unfavorable text label.
		 *
		 * @return {?boolean}
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
		 */
		deviationState() {
			return favorableState(this.cell)
		},

		/**
		 * The English source text label for the deviation state — text,
		 * never colour alone (WCAG 2.1 AA).
		 *
		 * @return {string}
		 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
		 */
		deviationLabel() {
			return this.deviationState === true
				? this.t('shillinq', 'Favorable:')
				: this.t('shillinq', 'Unfavorable:')
		},
	},

	methods: {
		formatAmount,
	},
}
</script>

<style scoped>
.budget-grid-cell {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.budget-grid-cell__actual {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.9em;
}

.budget-grid-cell__deviation {
	font-size: 0.9em;
}

.budget-grid-cell__deviation--favorable {
	color: var(--color-success, #2d7d46);
}

.budget-grid-cell__deviation--unfavorable {
	color: var(--color-error, #d40000);
}
</style>
