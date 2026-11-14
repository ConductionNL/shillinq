<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Compliance Dashboard — realisation per task field
 (public-sector-task-field-allocation, REQ-BBV-012).

 Renders the `taskFieldRealisation` block of `GET /api/bbv-dashboard`:
 one row per BBV task field with the year's expenses and income from
 posted GL lines through the account mapping, and below it every account
 that carries postings but has no mapping, each with a link to the BBV
 mapping page. Amounts arrive in euros, already summed server-side; this
 component only formats them (ADR-031).

 @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
-->
<template>
	<div class="bbv-task-fields" data-testid="bbv-task-fields">
		<CnDataTable
			:columns="columns"
			:rows="taskFieldRows"
			:loading="loading"
			rowKey="taskField"
			:loadingText="t('shillinq', 'Loading task fields…')"
			:emptyText="t('shillinq', 'No posted lines on a mapped account this fiscal year.')">
			<template #column-expenses="{ row }">
				<span class="bbv-task-fields__money">{{ formatEuro(row.expenses) }}</span>
			</template>
			<template #column-income="{ row }">
				<span class="bbv-task-fields__money">{{ formatEuro(row.income) }}</span>
			</template>
		</CnDataTable>

		<section
			v-if="unmappedRows.length > 0"
			class="bbv-task-fields__unmapped"
			data-testid="bbv-task-fields-unmapped">
			<h3 class="bbv-task-fields__heading">
				{{ t('shillinq', 'Accounts with postings but no task field') }}
			</h3>
			<ul class="bbv-task-fields__list">
				<li
					v-for="row in unmappedRows"
					:key="row.accountNumber"
					:data-testid="`bbv-unmapped-${row.accountNumber}`">
					<span class="bbv-task-fields__account">{{ row.accountNumber }}</span>
					<span class="bbv-task-fields__money">{{ formatEuro(row.amount) }}</span>
					<a :href="row.href" class="bbv-task-fields__link">
						{{ t('shillinq', 'Map to a task field') }}
					</a>
				</li>
			</ul>
		</section>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The mapping page an unmapped account links to when the payload names none.
 */
const DEFAULT_MAPPING_PAGE = '/overheid/bbv-mapping'

export default {
	name: 'BBVTaskFieldRealisation',
	components: { CnDataTable },
	props: {
		realisation: {
			type: Object,
			default: null,
		},

		loading: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		columns() {
			return [
				{ key: 'taskField', label: this.t('shillinq', 'Task field') },
				{ key: 'expenses', label: this.t('shillinq', 'Expenses') },
				{ key: 'income', label: this.t('shillinq', 'Income') },
			]
		},

		taskFieldRows() {
			const fields = Array.isArray(this.realisation?.taskFields) ? this.realisation.taskFields : []
			return fields
				.filter((row) => row && String(row.taskField ?? '') !== '')
				.map((row) => ({
					taskField: String(row.taskField),
					expenses: Number(row.expenses) || 0,
					income: Number(row.income) || 0,
				}))
		},

		unmappedRows() {
			const accounts = Array.isArray(this.realisation?.unmapped) ? this.realisation.unmapped : []
			return accounts
				.filter((row) => row && String(row.accountNumber ?? '') !== '')
				.map((row) => ({
					accountNumber: String(row.accountNumber),
					amount: Number(row.amount) || 0,
					href: this.mappingHref(row.mappingPage),
				}))
		},
	},

	methods: {
		t,

		/**
		 * The in-app URL of the BBV mapping page.
		 *
		 * @param {string} page The app-relative page path from the payload.
		 * @return {string}
		 */
		mappingHref(page) {
			const path = typeof page === 'string' && page.startsWith('/') ? page : DEFAULT_MAPPING_PAGE
			return generateUrl(`/apps/shillinq${path}`)
		},

		/**
		 * A euro amount for display.
		 *
		 * @param {number} euros The amount in euros.
		 * @return {string}
		 */
		formatEuro(euros) {
			const value = Number(euros)
			if (!Number.isFinite(value)) {
				return '—'
			}
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			}).format(value)
		},
	},
}
</script>

<style scoped>
.bbv-task-fields {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 4);
}

.bbv-task-fields__money {
	font-variant-numeric: tabular-nums;
}

.bbv-task-fields__heading {
	margin: 0 0 calc(var(--default-grid-baseline) * 2);
	font-size: var(--default-font-size);
	font-weight: bold;
	color: var(--color-main-text);
}

.bbv-task-fields__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.bbv-task-fields__list li {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 4);
	align-items: baseline;
	padding: calc(var(--default-grid-baseline) * 1) 0;
	border-bottom: 1px solid var(--color-border);
}

.bbv-task-fields__account {
	min-width: 8em;
	font-weight: bold;
}

.bbv-task-fields__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
