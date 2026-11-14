<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 GL Account picker — autocomplete dropdown over the Chart of Accounts
 (slice 07 of bookkeeping-waterschappen-bbv-variant, REQ-BBVW-004).

 Backs the Budget Mapping detail page picker for `glAccountNumber`.
 Fetches `Account` objects from the OpenRegister object endpoint and
 filters by accountNumber or accountName as the user types. Displays
 the account name + type + balance beneath the dropdown row so the
 operator can confirm they are linking the right ledger account.

 Uses NcSelect with an explicit `input-label` (hydra-gate-nc-input-labels)
 — accessibility wiring (label, aria, combobox listbox) is provided by
 NcSelect itself; a manual <label> would break that wiring.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<NcSelect
		:modelValue="selectedOption"
		:options="filteredOptions"
		:loading="loading"
		:inputLabel="t('shillinq', 'GL account')"
		:placeholder="t('shillinq', 'Search by account number or name…')"
		:filterable="true"
		:clearable="false"
		label="display"
		trackBy="value"
		data-testid="bbv-gl-account-picker"
		@search="onSearch"
		@option:selected="onSelected"
		@update:modelValue="onUpdateModelValue" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { accountLabelParts, isAmount } from './accountLabel.js'
import { recordPickerMixin } from './recordPickerMixin.js'

export default {
	name: 'GlAccountPicker',
	components: { NcSelect },
	mixins: [
		recordPickerMixin({
			schema: 'Account',
			rowsKey: 'accounts',
			optionKey: 'account',
			valueOf: (account) => String(account.accountNumber ?? account.id ?? ''),
			storedValueOf: (account) => String(account.accountNumber || account.id),
			nameOf: (account) => account?.accountName || account?.name || '',
		}),
	],

	props: {
		/**
		 * Optional administration scope. When set, the picker filters
		 * accounts to this administration before applying the text query.
		 */
		administrationId: {
			type: String,
			default: '',
		},
	},

	watch: {
		administrationId: {
			immediate: false,
			handler() {
				this.fetchAccounts()
			},
		},
	},

	async created() {
		await this.fetchAccounts()
	},

	methods: {
		t,
		formatDisplay(account) {
			if (!account) {
				return ''
			}
			const parts = [...accountLabelParts(account), this.balanceText(account)].filter(Boolean)
			return parts.join(' · ')
		},

		balanceText(account) {
			const balanceCents = account.balance ?? account.balanceCents
			return isAmount(balanceCents) ? this.formatEuro(balanceCents) : ''
		},

		formatEuro(cents) {
			const numeric = Number(cents)
			if (!Number.isFinite(numeric)) {
				return ''
			}
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			}).format(numeric / 100)
		},

		/**
		 * The translated text shown when the records cannot be loaded.
		 *
		 * @return {string} The text.
		 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
		 */
		loadFailedText() {
			return this.t('shillinq', 'Failed to load chart of accounts.')
		},

		/**
		 * Load the records this picker offers.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
		 */
		async fetchAccounts() {
			const params = { _limit: 500 }
			if (this.administrationId) {
				params.administrationId = this.administrationId
			}
			await this.loadRecords(params)
		},
	},
}
</script>
