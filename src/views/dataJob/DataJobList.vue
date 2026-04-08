<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="datajob-list">
		<Breadcrumb :items="breadcrumbItems" />

		<CnIndexPage
			:columns="columns"
			:filters="filters"
			:store="dataJobStore"
			:page-size="20"
			@view="onView" />

		<CsvImportDialog
			v-if="showImportDialog"
			@close="showImportDialog = false" />

		<NcButton v-if="!showImportDialog"
			type="primary"
			class="datajob-list__import-btn"
			@click="showImportDialog = true">
			<template #icon>
				<UploadIcon :size="20" />
			</template>
			{{ t('shillinq', 'Import CSV') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnIndexPage, columnsFromSchema, filtersFromSchema } from '@conduction/nextcloud-vue'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import Breadcrumb from '../../components/Breadcrumb.vue'
import CsvImportDialog from './CsvImportDialog.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'DataJobList',
	components: {
		NcButton,
		CnIndexPage,
		Breadcrumb,
		CsvImportDialog,
		UploadIcon,
	},

	data() {
		return {
			showImportDialog: false,
		}
	},

	computed: {
		dataJobStore() {
			return useDataJobStore()
		},
		columns() {
			return columnsFromSchema('dataJob')
		},
		filters() {
			return filtersFromSchema('dataJob')
		},
		breadcrumbItems() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Data Jobs') },
			]
		},
	},

	mounted() {
		if (this.$route.query.import === '1') {
			this.showImportDialog = true
		}
	},

	methods: {
		onView(item) {
			this.$router.push({
				name: 'DataJobDetail',
				params: { id: item.id },
			})
		},
	},
}
</script>

<style scoped>
.datajob-list__import-btn {
	margin-top: 16px;
}
</style>
