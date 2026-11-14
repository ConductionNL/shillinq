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
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { recordPickerMixin } from './recordPickerMixin.js'

export default {
	name: 'BBVProgrammePicker',
	components: { NcSelect },
	mixins: [
		recordPickerMixin({
			schema: 'BBVProgramme',
			rowsKey: 'programmes',
			optionKey: 'programme',
			valueOf: (programme) =>
				String(programme.programmeCode ?? programme.id ?? ''),
			storedValueOf: (programme) =>
				String(programme.programmeCode || programme.id),
			nameOf: (programme) => programme?.programmeName || '',
		}),
	],

	props: {
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

		/**
		 * The translated text shown when the records cannot be loaded.
		 *
		 * @return {string} The text.
		 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
		 */
		loadFailedText() {
			return this.t('shillinq', 'Failed to load BBV programmes.')
		},

		/**
		 * Load the records this picker offers.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
		 */
		async fetchProgrammes() {
			const params = {
				fiscalYear: this.fiscalYear,
				status: 'active',
				limit: 500,
			}
			if (this.administrationId) {
				params.administrationId = this.administrationId
			}
			await this.loadRecords(params)
		},
	},
}
</script>
