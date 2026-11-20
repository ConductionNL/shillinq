<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The account each receipt of an expense claim posts to, with the docudesk
 suggestion beside it (expenses-category-mapping task 3.3, REQ-ECM-003).
 The suggestion never replaces the account: it counts only once a person
 confirms it on the receipt. A receipt without an account says the post will
 stop there.

 @spec openspec/changes/expenses-category-mapping/specs/expense-capture-core/spec.md#requirement-each-expense-line-resolves-to-an-account-in-a-fixed-order-req-ecm-003
-->
<template>
	<div class="claim-receipt-accounts" data-testid="claim-receipt-accounts">
		<p v-if="loading" class="claim-receipt-accounts__muted">
			{{ t('shillinq', 'Loading the receipts…') }}
		</p>
		<p v-else-if="error" class="claim-receipt-accounts__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0" class="claim-receipt-accounts__muted">
			{{ t('shillinq', 'This claim has no receipts.') }}
		</p>
		<table v-else class="claim-receipt-accounts__table">
			<thead>
				<tr>
					<th scope="col">{{ t('shillinq', 'Receipt') }}</th>
					<th scope="col">{{ t('shillinq', 'Posts to') }}</th>
					<th scope="col">{{ t('shillinq', 'Suggested') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in rows"
					:key="row.id"
					data-testid="claim-receipt-accounts-row">
					<td>{{ row.label }}</td>
					<td
						:class="{
							'claim-receipt-accounts__error': !row.account.code,
						}">
						{{ accountText(row.account) }}
					</td>
					<td>
						<template v-if="row.suggestion">
							{{ row.suggestion.code }}
							<span
								v-if="row.relation === 'differs'"
								class="claim-receipt-accounts__muted"
								data-testid="claim-receipt-accounts-differs">
								{{ t('shillinq', '(not used until confirmed)') }}
							</span>
						</template>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	resolvedReceiptAccount,
	suggestionRelation,
} from '../../utils/expenseCategories.js'

/**
 * The rows of an OpenRegister list answer.
 *
 * @param {object} response The axios response.
 * @return {Array<object>} The rows.
 */
function rowsOf(response) {
	const rows = response?.data?.results ?? response?.data ?? []
	return Array.isArray(rows) ? rows : []
}

export default {
	name: 'ClaimReceiptAccounts',

	props: {
		/** The claim's id, bound from the route by CnDetailPage. */
		objectId: { type: [String, Number], default: '' },
	},

	data() {
		return {
			loading: true,
			error: '',
			receipts: [],
			categories: [],
		}
	},

	computed: {
		/**
		 * @return {Array<object>} One row per receipt with its account and suggestion.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.3
		 */
		rows() {
			return this.receipts.map((receipt) => {
				const account = resolvedReceiptAccount(receipt, this.categories)
				const suggestion = receipt.suggestedGlAccount?.code
					? receipt.suggestedGlAccount
					: null
				return {
					id: String(receipt.id ?? receipt.uuid ?? receipt.receiptNumber),
					label: [receipt.receiptNumber, receipt.vendorName]
						.filter(Boolean)
						.join(' · '),
					account,
					suggestion,
					relation: suggestionRelation(account, suggestion),
				}
			})
		},
	},

	watch: {
		objectId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * @param {{code: string|null, source: string|null, category: string}} account The resolved account.
		 * @return {string} The cell text.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.3
		 */
		accountText(account) {
			if (account.source === 'confirmed') {
				return t('shillinq', '{code} (confirmed)', { code: account.code })
			}
			if (account.source === 'category') {
				return t('shillinq', '{code} (category {category})', {
					code: account.code,
					category: account.category,
				})
			}
			return t('shillinq', 'No account: posting stops here')
		},

		/**
		 * Load the claim's receipts and their administration's categories.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.3
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				const base = '/apps/openregister/api/objects/shillinq'
				this.receipts = rowsOf(
					await axios.get(generateUrl(`${base}/Receipt`), {
						params: { claimId: this.objectId, _limit: 200 },
					}),
				)
				const administrationId = this.receipts.find(
					(receipt) => receipt.administrationId,
				)?.administrationId
				const params = { _limit: 500 }
				if (administrationId) {
					params.administrationId = administrationId
				}
				this.categories = rowsOf(
					await axios.get(generateUrl(`${base}/ExpenseCategory`), {
						params,
					}),
				)
			} catch {
				this.error = t(
					'shillinq',
					'The receipts of this claim could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.claim-receipt-accounts__table {
	width: 100%;
	border-collapse: collapse;
}

.claim-receipt-accounts__table th,
.claim-receipt-accounts__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.claim-receipt-accounts__muted {
	color: var(--color-text-maxcontrast);
}

.claim-receipt-accounts__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
