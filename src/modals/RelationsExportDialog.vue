<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RelationsExportDialog: download Relations both ways as CSV for a period
 (reporting-relation-both-sides REQ-RRBS-003). One row per linked relation
 with invoiced sales, invoiced purchases, open receivable, open payable and
 net. Opened from the report's Export CSV header action. Its own file for
 hydra gate-13.

 @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Export relations both ways')"
		size="small"
		data-testid="relations-export-dialog"
		@closing="$emit('close')">
		<div class="relations-export">
			<label for="relations-export-from">{{ t('shillinq', 'From') }}</label>
			<input id="relations-export-from" v-model="from" type="date" />
			<label for="relations-export-to">{{ t('shillinq', 'To') }}</label>
			<input id="relations-export-to" v-model="to" type="date" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!from || !to || to < from"
				:href="downloadUrl"
				data-testid="relations-export-download"
				@click="$emit('close')">
				{{ t('shillinq', 'Download CSV') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'RelationsExportDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],

	data() {
		const year = new Date().getFullYear()
		return {
			from: year + '-01-01',
			to: year + '-12-31',
		}
	},

	computed: {
		/**
		 * The CSV download for the chosen period.
		 *
		 * @return {string} The URL.
		 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
		 */
		downloadUrl() {
			return (
				generateUrl('/apps/shillinq/api/relations/both-sides')
				+ '?format=csv&from='
				+ encodeURIComponent(this.from)
				+ '&to='
				+ encodeURIComponent(this.to)
			)
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.relations-export {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
}
</style>
