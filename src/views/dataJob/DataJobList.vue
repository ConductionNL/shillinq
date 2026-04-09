<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-7.1
-->
<template>
	<div class="data-job-list">
		<Breadcrumb :items="breadcrumbs" />
		<CnIndexPage
			v-bind="listProps"
			v-on="listEvents">
			<template #header-actions>
				<NcButton type="primary" @click="showImportDialog = true">
					<template #icon>
						<UploadIcon :size="20" />
					</template>
					{{ t('shillinq', 'Import CSV') }}
				</NcButton>
			</template>
		</CnIndexPage>
		<CsvImportDialog
			v-if="showImportDialog"
			@close="showImportDialog = false"
			@imported="onImported" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import Breadcrumb from '../../components/Breadcrumb.vue'
import CsvImportDialog from './CsvImportDialog.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'DataJobList',
	components: {
		NcButton,
		CnIndexPage,
		Breadcrumb,
		CsvImportDialog,
		UploadIcon: Upload,
	},
	setup() {
		const list = useListView('dataJob')
		return { ...list }
	},
	data() {
		return {
			showImportDialog: false,
		}
	},
	computed: {
		breadcrumbs() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Data Jobs') },
			]
		},
		dataJobStore() {
			return useDataJobStore()
		},
		listProps() {
			return {
				schema: this.schema,
				objects: this.objects,
				loading: this.loading,
				pagination: this.pagination,
			}
		},
		listEvents() {
			return {
				view: this.onView,
				search: this.onSearch,
				sort: this.onSort,
				'filter-change': this.onFilterChange,
				'page-change': this.onPageChange,
				refresh: this.refresh,
			}
		},
	},
	methods: {
		onView(item) {
			this.$router.push(`/data-jobs/${item.id}`)
		},
		onImported() {
			this.showImportDialog = false
			this.refresh()
		},
	},
}
</script>
