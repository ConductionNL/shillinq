<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Own records page (platform-custom-record-types, REQ-CRT-002/003): one card
 per own record type an administrator chose to show, each opening the
 generic `/records/:schema` list page. Reuses the shared ClusterOverview card
 grid. When nothing is shown, or a typed URL named a type that is not shown
 (the router guard sends it here with `?unavailable=<slug>`), it says so.

 @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
-->
<template>
	<div class="custom-record-types" data-testid="custom-record-types">
		<NcNoteCard
			v-if="unavailable"
			type="warning"
			data-testid="custom-record-types-unavailable">
			{{ t('shillinq', 'The record type "{type}" is not available in shillinq.', { type: unavailable }) }}
		</NcNoteCard>
		<NcLoadingIcon v-if="loading" :size="32" />
		<NcEmptyContent
			v-else-if="sections.length === 0"
			:name="t('shillinq', 'No own records yet')"
			:description="t('shillinq', 'An administrator can show record types they added to the shillinq register in Open Register, in the shillinq admin settings.')"
			data-testid="custom-record-types-empty">
			<template #icon>
				<DatabaseOutline />
			</template>
		</NcEmptyContent>
		<ClusterOverview
			v-else
			clusterId="custom-record-types"
			:title="t('shillinq', 'Own records')"
			:sections="sections" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import ClusterOverview from '../cluster-overview/ClusterOverview.vue'
import { cardSections, fetchShownTypes } from '../../utils/customRecordTypes.js'

export default {
	name: 'CustomRecordTypes',

	components: {
		ClusterOverview,
		DatabaseOutline,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			types: [],
		}
	},

	computed: {
		/**
		 * The card section of the shown types.
		 *
		 * @return {Array<object>} ClusterOverview sections.
		 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
		 */
		sections() {
			return cardSections(this.types, t('shillinq', 'Own record types'))
		},

		/**
		 * The slug a refused URL named, if any.
		 *
		 * @return {string} The slug, or an empty string.
		 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-only-shown-record-types-can-be-opened-req-crt-003
		 */
		unavailable() {
			const value = this.$route?.query?.unavailable
			return typeof value === 'string' ? value : ''
		},
	},

	/**
	 * Read the shown types once.
	 *
	 * @spec openspec/changes/platform-custom-record-types/specs/app-administration/spec.md#requirement-shown-record-types-have-list-and-detail-pages-req-crt-002
	 */
	async created() {
		this.types = await fetchShownTypes()
		this.loading = false
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.custom-record-types {
	padding: calc(var(--default-grid-baseline) * 2);
}
</style>
