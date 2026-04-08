<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="organization-list">
		<Breadcrumb :items="breadcrumbItems" />

		<CnIndexPage
			:columns="columns"
			:filters="filters"
			:store="organizationStore"
			:page-size="20"
			@create="showCreateDialog = true"
			@view="onView"
			@delete="onDelete" />

		<CnFormDialog
			v-if="showCreateDialog"
			:fields="fields"
			:object="editObject"
			:title="editObject ? t('shillinq', 'Edit Organization') : t('shillinq', 'New Organization')"
			@save="onSave"
			@cancel="closeDialog" />

		<ExportButton
			v-if="organizationStore.objectList?.length"
			:store="organizationStore"
			:schema="'organization'" />
	</div>
</template>

<script>
import {
	CnIndexPage,
	CnFormDialog,
	columnsFromSchema,
	filtersFromSchema,
	fieldsFromSchema,
} from '@conduction/nextcloud-vue'
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

	data() {
		return {
			showCreateDialog: false,
			editObject: null,
		}
	},

	computed: {
		organizationStore() {
			return useOrganizationStore()
		},
		columns() {
			return columnsFromSchema('organization')
		},
		filters() {
			return filtersFromSchema('organization')
		},
		fields() {
			return fieldsFromSchema('organization')
		},
		breadcrumbItems() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Organizations') },
			]
		},
	},

	mounted() {
		if (this.$route.query.create === '1') {
			this.showCreateDialog = true
		}
	},

	methods: {
		onView(item) {
			this.$router.push({
				name: 'OrganizationDetail',
				params: { id: item.id },
			})
		},
		async onDelete(item) {
			await this.organizationStore.deleteObject(item)
		},
		async onSave(data) {
			await this.organizationStore.saveObject(data)
			this.closeDialog()
		},
		closeDialog() {
			this.showCreateDialog = false
			this.editObject = null
		},
	},
}
</script>
