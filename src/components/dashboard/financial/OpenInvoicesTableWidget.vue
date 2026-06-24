<!--
  OpenInvoicesTableWidget — open debiteuren (ARInvoice) or
  crediteuren (APTransaction) as a due-date-sorted table with
  overdue flagging and row links to the invoice detail pages.
  The variant is picked via the manifest widget's `props.kind`
  (`debtors` | `creditors`).

  @spec openspec/changes/financial-dashboard-graphs/specs/financial-dashboard-graphs/spec.md
-->
<template>
	<div class="open-invoices" :data-testid="`open-invoices-${kind}`">
		<NcLoadingIcon v-if="loading" :size="32" class="open-invoices__loading" />
		<p v-else-if="rows.length === 0" class="open-invoices__empty">
			{{ kind === 'debtors'
				? t('shillinq', 'No open debtor invoices — everything is paid.')
				: t('shillinq', 'No open creditor invoices — nothing due.') }}
		</p>
		<template v-else>
			<table class="open-invoices__table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Invoice') }}</th>
						<th>{{ kind === 'debtors' ? t('shillinq', 'Customer') : t('shillinq', 'Vendor') }}</th>
						<th>{{ t('shillinq', 'Due date') }}</th>
						<th class="open-invoices__amount">
							{{ t('shillinq', 'Amount') }}
						</th>
						<th>{{ t('shillinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in visibleRows"
						:key="row.id"
						class="open-invoices__row"
						:class="{ 'open-invoices__row--overdue': row.overdue }"
						tabindex="0"
						role="link"
						:aria-label="t('shillinq', 'Open invoice {number}', { number: row.invoiceNumber })"
						@click="openRow(row)"
						@keyup.enter="openRow(row)">
						<td>{{ row.invoiceNumber }}</td>
						<td>{{ row.party }}</td>
						<td>{{ formatDate(row.dueDate) }}</td>
						<td class="open-invoices__amount">
							{{ formatEur(row.amount, 2) }}
						</td>
						<td>
							<span
								class="open-invoices__badge"
								:class="row.overdue ? 'open-invoices__badge--overdue' : 'open-invoices__badge--open'">
								{{ row.overdue ? t('shillinq', 'Overdue') : t('shillinq', 'Open') }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
			<div v-if="rows.length > visibleRows.length || rows.length > 0" class="open-invoices__footer">
				<a class="open-invoices__view-all" @click.prevent="openIndex">
					{{ t('shillinq', 'View all ({total})', { total: rows.length }) }}
				</a>
			</div>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { useFinancialData } from './useFinancialData.js'
import { openArRows, openApRows, formatEur } from './financialSeries.js'

const MAX_ROWS = 8

const ROUTES = {
	debtors: { detail: 'ARInvoiceDetail', index: 'AccountsReceivable' },
	creditors: { detail: 'SupplierInvoiceDetail', index: 'SupplierInvoices' },
}

export default {
	name: 'OpenInvoicesTableWidget',

	components: { NcLoadingIcon },

	props: {
		/** Layout item from CnDashboardPage's widget slot scope. */
		item: { type: Object, default: null },
		/** Widget definition from CnDashboardPage's widget slot scope. */
		widget: { type: Object, default: null },
	},

	setup() {
		const { loading, data, load, reload } = useFinancialData()
		return { loading, financialData: data, load, reload }
	},

	computed: {
		/** @return {'debtors'|'creditors'} Variant from the manifest widget props. */
		kind() {
			return this.widget?.props?.kind === 'creditors' ? 'creditors' : 'debtors'
		},
		rows() {
			if (!this.financialData) return []
			return this.kind === 'debtors'
				? openArRows(this.financialData.arInvoices, this.financialData.customers)
				: openApRows(this.financialData.apTransactions, this.financialData.vendors)
		},
		visibleRows() {
			return this.rows.slice(0, MAX_ROWS)
		},
	},

	mounted() {
		this.load()
		this._onRefresh = (payload) => {
			if (payload?.widgetId === this.item?.widgetId) this.reload()
		}
		subscribe('cn:widget:refresh', this._onRefresh)
	},

	beforeDestroy() {
		unsubscribe('cn:widget:refresh', this._onRefresh)
	},

	methods: {
		t,
		formatEur,
		formatDate(value) {
			const key = typeof value === 'string' ? value.slice(0, 10) : ''
			if (!key) return '—'
			const [y, m, d] = key.split('-').map(Number)
			return new Date(y, m - 1, d).toLocaleDateString()
		},
		openRow(row) {
			if (!row.id) return
			this.$router.push({ name: ROUTES[this.kind].detail, params: { id: String(row.id) } })
		},
		openIndex() {
			this.$router.push({ name: ROUTES[this.kind].index })
		},
	},
}
</script>

<style scoped>
.open-invoices__table {
	width: 100%;
	border-collapse: collapse;
}

.open-invoices__table th {
	text-align: start;
	padding: 6px 8px;
	color: var(--color-text-maxcontrast, #767676);
	font-weight: 600;
	font-size: 13px;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.open-invoices__table td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border, #ededed);
}

.open-invoices__row {
	cursor: pointer;
}

.open-invoices__row:hover,
.open-invoices__row:focus {
	background-color: var(--color-background-hover, #f5f5f5);
}

.open-invoices__row--overdue td:first-child {
	box-shadow: inset 3px 0 0 var(--color-error, #e04224);
}

.open-invoices__amount {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.open-invoices__badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 100px);
	font-size: 12px;
	font-weight: 600;
}

.open-invoices__badge--open {
	background-color: var(--color-primary-element-light, #d8ecf6);
	color: var(--color-primary-element, #0082c9);
}

.open-invoices__badge--overdue {
	background-color: var(--color-error, #e04224);
	color: var(--color-primary-element-text, #fff);
}

.open-invoices__empty {
	color: var(--color-text-maxcontrast, #767676);
	padding: 16px 8px;
}

.open-invoices__footer {
	padding-top: 8px;
	text-align: end;
}

.open-invoices__view-all {
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	font-size: 13px;
}

.open-invoices__loading {
	margin: 24px auto;
}
</style>
