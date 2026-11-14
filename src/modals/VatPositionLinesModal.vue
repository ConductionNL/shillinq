<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VatPositionLinesModal: the posted lines behind one box of the running VAT
 position (tax-vat-position-and-suppletie REQ-VPS-001, task 1.3).

 Opened with spawnDialog from the "Show lines" row action on the position
 table of the BTW report (src/utils/vatSuppletieActions.js), so it lives in
 its own file (hydra gate-13). The lines arrive with the box: the position
 endpoint already returns them, so nothing is fetched here.

 @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.3
-->

<template>
	<NcDialog
		:name="title"
		size="large"
		data-testid="vat-position-lines-modal"
		@closing="$emit('close')">
		<p v-if="lines.length === 0">
			{{ t('shillinq', 'No posted lines make up this box yet.') }}
		</p>
		<table
			v-else
			class="vat-position-lines"
			data-testid="vat-position-lines">
			<thead>
				<tr>
					<th scope="col">
						{{ t('shillinq', 'Ledger') }}
					</th>
					<th scope="col">
						{{ t('shillinq', 'Description') }}
					</th>
					<th scope="col">
						{{ t('shillinq', 'Entry') }}
					</th>
					<th scope="col" class="vat-position-lines__amount">
						{{ t('shillinq', 'Base') }}
					</th>
					<th scope="col" class="vat-position-lines__amount">
						{{ t('shillinq', 'BTW') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(line, index) in lines" :key="line.glTransactionId + '-' + (line.lineNumber || index)">
					<td>{{ line.glAccountNumber }}</td>
					<td>{{ line.description }}</td>
					<td>{{ line.glTransactionId }}</td>
					<td class="vat-position-lines__amount">
						{{ money(line.taxableAmount) }}
					</td>
					<td class="vat-position-lines__amount">
						{{ money(line.vatAmount) }}
					</td>
				</tr>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row" colspan="3">
						{{ t('shillinq', 'Total') }}
					</th>
					<td class="vat-position-lines__amount">
						{{ money(totals.base) }}
					</td>
					<td class="vat-position-lines__amount">
						{{ money(totals.vat) }}
					</td>
				</tr>
			</tfoot>
		</table>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('shillinq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { positionLineTotals } from '../utils/vatPositionLines.js'

export default {
	name: 'VatPositionLinesModal',
	components: { NcButton, NcDialog },
	props: {
		/** The position box: `returnBox`, `type`, `taxRate`, `base`, `vat` and its `lines`. */
		box: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],
	computed: {
		lines() {
			return Array.isArray(this.box.lines) ? this.box.lines : []
		},

		totals() {
			return positionLineTotals(this.lines)
		},

		title() {
			return t('shillinq', 'Lines behind box {box}', { box: this.box.returnBox || '' })
		},
	},

	methods: {
		t,
		/**
		 * An amount in euros, as the locale writes it.
		 *
		 * @param {number} value The amount.
		 * @return {string}
		 */
		money(value) {
			return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR' }).format(Number(value) || 0)
		},
	},
}
</script>

<style scoped>
.vat-position-lines {
	width: 100%;
	border-collapse: collapse;
}

.vat-position-lines th,
.vat-position-lines td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.vat-position-lines__amount {
	text-align: end !important;
	font-variant-numeric: tabular-nums;
}
</style>
