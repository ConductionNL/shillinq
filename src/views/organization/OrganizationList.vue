<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-5.1
-->
<template>
	<div class="organization-list">
		<Breadcrumb :items="breadcrumbs" />
		<CnIndexPage
			v-bind="listProps"
			v-on="listEvents">
			<template #header-actions>
				<ExportButton object-type="organization" />
			</template>
		</CnIndexPage>
		<CnFormDialog
			v-if="showFormDialog"
			:fields="formFields"
			:object="formObject"
			:title="formTitle"
			@save="onFormSave"
			@cancel="showFormDialog = false" />
	</div>
</template>

<script>
import { CnIndexPage, CnFormDialog, useListView, fieldsFromSchema } from '@conduction/nextcloud-vue'
import { useOrganizationStore } from '../../store/modules/organization.js'
import Breadcrumb from '../../components/Breadcrumb.vue'
import ExportButton from '../../components/ExportButton.vue'

export default {
	name: 'OrganizationList',
	components: {
		CnIndexPage,
		CnFormDialog,
		Breadcrumb,
		ExportButton,
	},
	setup() {
		const list = useListView('organization')
		return { ...list }
	},
	data() {
		return {
			showFormDialog: false,
			formObject: null,
		}
	},
	computed: {
		breadcrumbs() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Organizations') },
			]
		},
		organizationStore() {
			return useOrganizationStore()
		},
		formFields() {
			return fieldsFromSchema(this.schema)
		},
		formTitle() {
			return this.formObject?.id
				? t('shillinq', 'Edit Organization')
				: t('shillinq', 'New Organization')
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
				add: this.onAdd,
				view: this.onView,
				edit: this.onEdit,
				delete: this.onDelete,
				search: this.onSearch,
				sort: this.onSort,
				'filter-change': this.onFilterChange,
				'page-change': this.onPageChange,
				refresh: this.refresh,
			}
		},
	},
	methods: {
		onAdd() {
			this.formObject = {}
			this.showFormDialog = true
		},
		onView(item) {
			this.$router.push(`/organizations/${item.id}`)
		},
		onEdit(item) {
			this.formObject = { ...item }
			this.showFormDialog = true
		},
		onDelete(item) {
			this.organizationStore.deleteObject('organization', item.id)
				.then(() => this.refresh())
		},
		async onFormSave(data) {
			await this.organizationStore.saveObject('organization', data)
			this.showFormDialog = false
			this.refresh()
		},
	},
}
</script>
