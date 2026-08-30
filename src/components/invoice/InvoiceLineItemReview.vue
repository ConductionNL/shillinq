<!--
  Invoice Line Item Review

  Modal-style breakdown of a drafted BillableInvoice's lines grouped by
  sourceType (time_entry, expense, retainer_charge, fixed_fee). Lets the
  admin scan rate/units/cost/VAT per row before posting (Task 16).

  @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-16

  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<section class="invoice-line-review">
		<header>
			<h3>{{ t('shillinq', 'Line items') }}</h3>
		</header>

		<table class="invoice-line-review__table">
			<thead>
				<tr>
					<th scope="col">#</th>
					<th scope="col">{{ t('shillinq', 'Source') }}</th>
					<th scope="col">{{ t('shillinq', 'Description') }}</th>
					<th scope="col" class="num">
						{{ t('shillinq', 'Units') }}
					</th>
					<th scope="col" class="num">
						{{ t('shillinq', 'Rate') }}
					</th>
					<th scope="col" class="num">
						{{ t('shillinq', 'Cost') }}
					</th>
					<th scope="col" class="num">
						{{ t('shillinq', 'VAT') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="line in lines" :key="line.lineNumber">
					<td>{{ line.lineNumber }}</td>
					<td>{{ sourceLabel(line.sourceType) }}</td>
					<td>{{ line.description }}</td>
					<td class="num">
						{{ formatUnits(line.billableUnits) }}
					</td>
					<td class="num">
						{{ formatRate(line.rateApplied) }}
					</td>
					<td class="num">€ {{ formatMoney(line.costAmount) }}</td>
					<td class="num">{{ line.vatRate }}%</td>
				</tr>
			</tbody>
		</table>

		<footer class="invoice-line-review__totals">
			<p>{{ t('shillinq', 'Net') }}: € {{ formatMoney(summary.netAmount) }}</p>
			<p>
				{{ t('shillinq', 'VAT/BTW') }}: €
				{{ formatMoney(summary.vatAmount) }}
			</p>
			<p>
				<strong
					>{{ t('shillinq', 'Gross') }}: €
					{{ formatMoney(summary.grossAmount) }}</strong
				>
			</p>
		</footer>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'InvoiceLineItemReview',

	props: {
		lines: {
			type: Array,
			default: () => [],
		},

		summary: {
			type: Object,
			default: () => ({ netAmount: 0, vatAmount: 0, grossAmount: 0 }),
		},
	},

	methods: {
		t,
		sourceLabel(sourceType) {
			const map = {
				time_entry: t('shillinq', 'Time entry'),
				expense: t('shillinq', 'Expense'),
				retainer_charge: t('shillinq', 'Retainer charge'),
				fixed_fee: t('shillinq', 'Fixed fee'),
				manual: t('shillinq', 'Manual'),
			}
			return map[sourceType] || sourceType
		},

		formatMoney(value) {
			const n = Number(value || 0)
			return n.toLocaleString('nl-NL', {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})
		},

		formatUnits(value) {
			if (value === null || value === undefined) return ''
			return Number(value).toLocaleString('nl-NL')
		},

		formatRate(rateApplied) {
			if (!rateApplied || rateApplied.rateCents === undefined) return ''
			return `€ ${(rateApplied.rateCents / 100).toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
		},
	},
}
</script>

<style scoped>
.invoice-line-review__table {
	width: 100%;
	border-collapse: collapse;
	margin: 12px 0;
}

.invoice-line-review__table th,
.invoice-line-review__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}

.num {
	text-align: right;
}

.invoice-line-review__totals {
	margin-top: 12px;
	text-align: right;
}
</style>
