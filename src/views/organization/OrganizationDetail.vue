<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="organization-detail">
		<Breadcrumb :items="breadcrumbItems" />

		<CnDetailPage
			:object="currentObject"
			:store="organizationStore"
			:tabs="tabs"
			@edit="showEditDialog = true"
			@delete="onDelete" />

		<CnFormDialog
			v-if="showEditDialog"
			:fields="fields"
			:object="currentObject"
			:title="t('shillinq', 'Edit Organization')"
			@save="onSave"
			@cancel="showEditDialog = false" />
	</div>
</template>

<script>
import {
	CnDetailPage,
	CnFormDialog,
	fieldsFromSchema,
} from '@conduction/nextcloud-vue'
import { useOrganizationStore } from '../../store/modules/organization.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'OrganizationDetail',
	components: {
		CnDetailPage,
		CnFormDialog,
		Breadcrumb,
	},

	data() {
		return {
			showEditDialog: false,
		}
	},

	computed: {
		organizationStore() {
			return useOrganizationStore()
		},
		currentObject() {
			return this.organizationStore.currentObject
		},
		fields() {
			return fieldsFromSchema('organization')
		},
		tabs() {
			return [
				{ id: 'details', label: t('shillinq', 'Details') },
				{ id: 'settings', label: t('shillinq', 'Settings') },
			]
		},
		breadcrumbItems() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Organizations'), route: '/organizations' },
				{ label: this.currentObject?.name ?? '' },
			]
		},
	},

	async mounted() {
		await this.organizationStore.fetchObject(this.$route.params.id)
	},

	methods: {
		async onSave(data) {
			await this.organizationStore.saveObject(data)
			this.showEditDialog = false
		},
		async onDelete() {
			await this.organizationStore.deleteObject(this.currentObject)
			this.$router.push({ name: 'OrganizationList' })
		},
	},
}
</script>
