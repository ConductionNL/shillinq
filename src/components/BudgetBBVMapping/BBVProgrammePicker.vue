<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 BBV Programme picker — autocomplete dropdown over the BBVProgramme
 register (slice 07 of bookkeeping-waterschappen-bbv-variant,
 REQ-BBVW-004).

 Backs the Budget Mapping detail page picker for `programmeCode`.
 Fetches `BBVProgramme` records for the current fiscal year (status =
 active) so archived programmes are not offered for new mappings
 (slice-01 D1). Displays programmeCode + programmeName in the dropdown
 row and surfaces the human-readable name beneath the picker so the
 operator can confirm they are linking the right programme.

 Uses NcSelect with an explicit `input-label` (hydra-gate-nc-input-labels)
 to keep the screen-reader association NcSelect builds internally —
 a manual <label> would break it.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<NcSelect
		:modelValue="selectedOption"
		:options="filteredOptions"
		:loading="loading"
		:inputLabel="t('shillinq', 'BBV programme')"
		:placeholder="t('shillinq', 'Search by programme code or name…')"
		:filterable="true"
		:clearable="false"
		label="display"
		trackBy="value"
		data-testid="bbv-programme-picker"
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
const SCHEMA_SLUG = 'BBVProgramme'

export default {
	name: 'BBVProgrammePicker',
	components: { NcSelect },
	props: {
		/**
		 * v-model: the selected programmeCode (string).
		 */
		modelValue: {
			type: String,
			default: '',
		},

		/**
		 * Optional administration scope. When set, the picker filters
		 * BBVProgramme records to this administration.
		 */
		administrationId: {
			type: String,
			default: '',
		},

		/**
		 * Fiscal year filter. Defaults to the calendar year and follows
		 * the effectiveFrom date of the parent mapping form.
		 */
		fiscalYear: {
			type: Number,
			default: () => new Date().getFullYear(),
		},
	},

	emits: ['update:modelValue', 'selected'],
	data() {
		return {
			loading: false,
			query: '',
			programmes: [],
			fetchError: '',
		}
	},

	computed: {
		options() {
			return this.programmes.map((p) => ({
				value: String(p.programmeCode ?? p.id ?? ''),
				display: this.formatDisplay(p),
				programme: p,
			}))
		},

		filteredOptions() {
			const q = (this.query || '').trim().toLowerCase()
			if (!q) {
				return this.options
			}
			return this.options.filter((o) => {
				const code = String(o.value || '').toLowerCase()
				const name = (o.programme?.programmeName || '').toLowerCase()
				return code.includes(q) || name.includes(q)
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
			return {
				value: String(this.modelValue),
				display: String(this.modelValue),
				programme: null,
			}
		},
	},

	watch: {
		fiscalYear() {
			this.fetchProgrammes()
		},

		administrationId() {
			this.fetchProgrammes()
		},
	},

	async created() {
		await this.fetchProgrammes()
	},

	methods: {
		t,
		formatDisplay(programme) {
			if (!programme) {
				return ''
			}
			const code = programme.programmeCode || ''
			const name = programme.programmeName || ''
			return [code, name].filter(Boolean).join(' · ')
		},

		onSearch(query) {
			this.query = query || ''
		},

		onSelected(option) {
			if (!option) {
				return
			}
			this.$emit('selected', option.programme || null)
		},

		onUpdateModelValue(option) {
			const value = option?.value ?? ''
			this.$emit('update:modelValue', value)
			if (option?.programme) {
				this.$emit('selected', option.programme)
			}
		},

		async fetchProgrammes() {
			this.loading = true
			this.fetchError = ''
			try {
				const params = {
					fiscalYear: this.fiscalYear,
					status: 'active',
					limit: 500,
				}
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
				this.programmes = Array.isArray(rows) ? rows : []
				if (this.modelValue) {
					const match = this.programmes.find(
						(p) =>
							String(p.programmeCode || p.id)
							=== String(this.modelValue),
					)
					if (match) {
						this.$emit('selected', match)
					}
				}
			} catch (e) {
				this.programmes = []
				this.fetchError =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load BBV programmes.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
