<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LedgerLinesGuidancePanel: the booking lines of a journal entry or a ledger
 transaction, each with its account's guidance under the account
 (ledger-booking-rules REQ-LBR-003). The guidance is the account's own
 description, which tells the bookkeeper when to use it. A control account
 is marked, because a person cannot post on it by hand (REQ-LBR-002).

 Rendered on JournalDetail and GeneralLedgerDetail through the page slots
 widget-journal-lines and widget-transaction-lines.

 @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
-->

<template>
	<section class="llg" data-testid="ledger-lines-guidance">
		<p v-if="loading" class="llg__muted">
			{{ t('shillinq', 'Loading lines…') }}
		</p>
		<p v-else-if="error" class="llg__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0" class="llg__muted">
			{{ t('shillinq', 'This entry has no lines yet.') }}
		</p>
		<table v-else class="llg__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('shillinq', 'Account') }}
					</th>
					<th scope="col">
						{{ t('shillinq', 'Debit') }}
					</th>
					<th scope="col">
						{{ t('shillinq', 'Credit') }}
					</th>
					<th scope="col">
						{{ t('shillinq', 'Cost centre') }}
					</th>
					<th scope="col">
						{{ t('shillinq', 'Project') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.key" data-testid="ledger-line">
					<td>
						<span class="llg__account">
							{{ row.accountNumber }} {{ row.accountName }}
						</span>
						<span
							v-if="row.controlAccountFor"
							class="llg__control"
							data-testid="ledger-line-control">
							{{
								t(
									'shillinq',
									'Control account: only its own ledger posts here',
								)
							}}
						</span>
						<span
							v-if="row.guidance"
							class="llg__guidance"
							data-testid="ledger-line-guidance">
							{{ row.guidance }}
						</span>
					</td>
					<td>{{ row.side === 'debit' ? money(row.amount) : '' }}</td>
					<td>{{ row.side === 'credit' ? money(row.amount) : '' }}</td>
					<td>{{ row.costCenterCode }}</td>
					<td>{{ row.projectCode }}</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { loadGuidanceRows } from '../../utils/accountGuidance.js'

export default {
	name: 'LedgerLinesGuidancePanel',

	props: {
		/** The entry's id, bound from the route by CnDetailPage. */
		objectId: {
			type: [String, Number],
			default: '',
		},

		/** JournalEntry or GLTransaction. */
		schema: {
			type: String,
			default: 'JournalEntry',
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			rows: [],
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/** @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the lines and their accounts.
		 *
		 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.rows = await loadGuidanceRows(
					this.schema,
					String(this.objectId),
				)
			} catch {
				this.error = t('shillinq', 'The lines could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * An amount in euros.
		 *
		 * @param {number} amount The amount.
		 * @return {string} The formatted amount.
		 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
		 */
		money(amount) {
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(amount) || 0)
		},
	},
}
</script>

<style scoped>
.llg__table {
	width: 100%;
	border-collapse: collapse;
}

.llg__table th,
.llg__table td {
	padding: 6px 8px;
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.llg__account {
	display: block;
	font-weight: bold;
}

.llg__guidance,
.llg__control {
	display: block;
	color: var(--color-text-maxcontrast);
}

.llg__muted {
	color: var(--color-text-maxcontrast);
}

.llg__error {
	color: var(--color-error-text);
}
</style>
