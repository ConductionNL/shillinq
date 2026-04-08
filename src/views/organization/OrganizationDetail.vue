<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="organization-detail">
		<Breadcrumb :items="breadcrumbItems" />

		<div v-if="currentObject" class="organization-detail__content">
			<div class="organization-detail__header">
				<h2>{{ currentObject.name }}</h2>
				<div class="organization-detail__actions">
					<NcButton type="primary"
						@click="showEditDialog = true">
						{{ t('shillinq', 'Edit') }}
					</NcButton>
					<NcButton type="error"
						@click="onDelete">
						{{ t('shillinq', 'Delete') }}
					</NcButton>
				</div>
			</div>

			<NcAppContentDetails>
				<div class="organization-detail__tabs">
					<NcButton v-for="tab in tabs"
						:key="tab.id"
						:type="activeTab === tab.id ? 'primary' : 'tertiary'"
						@click="activeTab = tab.id">
						{{ tab.label }}
					</NcButton>
				</div>

				<div v-if="activeTab === 'details'" class="organization-detail__fields">
					<div v-for="(value, key) in displayFields"
						:key="key"
						class="organization-detail__field">
						<span class="organization-detail__label">
							{{ key }}
						</span>
						<span class="organization-detail__value">
							{{ value || '—' }}
						</span>
					</div>
				</div>

				<div v-if="activeTab === 'settings'" class="organization-detail__settings">
					<p>{{ t('shillinq', 'No additional settings for this organization.') }}</p>
				</div>
			</NcAppContentDetails>
		</div>

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
import { NcButton, NcAppContentDetails } from '@nextcloud/vue'
import { CnFormDialog, fieldsFromSchema } from '@conduction/nextcloud-vue'
import { useOrganizationStore } from '../../store/modules/organization.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'OrganizationDetail',
	components: {
		NcButton,
		NcAppContentDetails,
		CnFormDialog,
		Breadcrumb,
	},

	data() {
		return {
			showEditDialog: false,
			activeTab: 'details',
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
		displayFields() {
			if (!this.currentObject) return {}
			const skip = ['id', 'uuid', '_id', '_self', '_schema', '_register']
			const fields = {}
			Object.entries(this.currentObject).forEach(([key, value]) => {
				if (!skip.includes(key) && !key.startsWith('_')) {
					fields[key] = value
				}
			})
			return fields
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

<style scoped>
.organization-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.organization-detail__actions {
	display: flex;
	gap: 8px;
}

.organization-detail__tabs {
	display: flex;
	gap: 4px;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.organization-detail__fields {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 8px 16px;
}

.organization-detail__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.organization-detail__value {
	color: var(--color-main-text);
}
</style>
