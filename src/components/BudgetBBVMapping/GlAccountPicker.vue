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
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'

const REGISTER_SLUG = 'shillinq'
const SCHEMA_SLUG = 'Account'

export default {
	name: 'GlAccountPicker',
	components: { NcSelect },
	props: {
		/**
		 * v-model: the selected glAccountNumber (string).
		 */
		modelValue: {
			type: String,
			default: '',
		},

		/**
		 * Optional administration scope. When set, the picker filters
		 * accounts to this administration before applying the text query.
		 */
		administrationId: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue', 'selected'],
	data() {
		return {
			loading: false,
			query: '',
			accounts: [],
			fetchError: '',
		}
	},

	computed: {
		options() {
			return this.accounts.map((a) => ({
				value: String(a.accountNumber ?? a.id ?? ''),
				display: this.formatDisplay(a),
				account: a,
			}))
		},

		filteredOptions() {
			const q = (this.query || '').trim().toLowerCase()
			if (!q) {
				return this.options
			}
			return this.options.filter((o) => {
				const num = String(o.value || '').toLowerCase()
				const name = (
					o.account?.accountName
					|| o.account?.name
					|| ''
				).toLowerCase()
				return num.includes(q) || name.includes(q)
			})
		},

		selectedOption() {
			if (!this.modelValue) {
				return null
			}
			const match = this.options.find(
				(o) => o.value === String(this.modelValue),
			)
			if (match) {
				return match
			}
			// Account list may not have arrived yet — surface the raw value
			// so the form still shows the number while the fetch completes.
			return {
				value: String(this.modelValue),
				display: String(this.modelValue),
				account: null,
			}
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
			const number = account.accountNumber || account.id || ''
			const name = account.accountName || account.name || account.title || ''
			const type = account.accountType || account.type || ''
			const balanceCents = account.balance ?? account.balanceCents
			const balance =
				balanceCents !== null
				&& balanceCents !== undefined
				&& Number.isFinite(Number(balanceCents))
					? this.formatEuro(balanceCents)
					: ''
			const parts = [number, name, type, balance].filter(Boolean)
			return parts.join(' · ')
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

		onSearch(query) {
			this.query = query || ''
		},

		onSelected(option) {
			if (!option) {
				return
			}
			this.$emit('selected', option.account || null)
		},

		onUpdateModelValue(option) {
			const value = option?.value ?? ''
			this.$emit('update:modelValue', value)
			if (option?.account) {
				this.$emit('selected', option.account)
			}
		},

		async fetchAccounts() {
			this.loading = true
			this.fetchError = ''
			try {
				const params = { _limit: 500 }
				if (this.administrationId) {
					params.administrationId = this.administrationId
				}
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/${SCHEMA_SLUG}`,
					),
					{ params },
				)
				const rows =
					response.data?.results
					?? response.data?.objects
					?? response.data
					?? []
				this.accounts = Array.isArray(rows) ? rows : []
				// Surface the selected account once data lands.
				if (this.modelValue) {
					const match = this.accounts.find(
						(a) =>
							String(a.accountNumber || a.id)
							=== String(this.modelValue),
					)
					if (match) {
						this.$emit('selected', match)
					}
				}
			} catch (e) {
				this.accounts = []
				this.fetchError =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load chart of accounts.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
