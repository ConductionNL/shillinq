<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-5.2
-->
<template>
	<div class="organization-detail">
		<Breadcrumb :items="breadcrumbs" />
		<NcLoadingIcon v-if="loading" :size="64" />
		<template v-else-if="objectData && objectData.id">
			<div class="organization-detail__header">
				<h2>{{ objectData.name }}</h2>
				<div class="organization-detail__actions">
					<NcButton type="primary" @click="onEdit">
						{{ t('shillinq', 'Edit') }}
					</NcButton>
					<NcButton type="error" @click="showDeleteDialog = true">
						{{ t('shillinq', 'Delete') }}
					</NcButton>
				</div>
			</div>

			<NcAppSettingsDialog
				v-if="false"
				:open="false" />

			<div class="organization-detail__tabs">
				<NcButton :type="activeTab === 'details' ? 'primary' : 'secondary'"
					@click="activeTab = 'details'">
					{{ t('shillinq', 'Details') }}
				</NcButton>
				<NcButton :type="activeTab === 'settings' ? 'primary' : 'secondary'"
					@click="activeTab = 'settings'">
					{{ t('shillinq', 'Settings') }}
				</NcButton>
			</div>

			<div v-if="activeTab === 'details'" class="organization-detail__content">
				<dl class="organization-detail__fields">
					<template v-for="(value, key) in displayFields">
						<dt :key="key + '-label'">{{ key }}</dt>
						<dd :key="key + '-value'">{{ value || '—' }}</dd>
					</template>
				</dl>
			</div>

			<div v-if="activeTab === 'settings'" class="organization-detail__content">
				<p class="organization-detail__hint">
					{{ t('shillinq', 'Organization-specific settings will be available in a future update.') }}
				</p>
			</div>

			<CnFormDialog
				v-if="editing"
				:fields="formFields"
				:object="objectData"
				:title="t('shillinq', 'Edit Organization')"
				@save="onSave"
				@cancel="editing = false" />

			<CnDeleteDialog
				v-if="showDeleteDialog"
				:item="objectData"
				name-field="name"
				@confirm="onConfirmDelete"
				@close="showDeleteDialog = false" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnFormDialog, CnDeleteDialog, useDetailView, fieldsFromSchema } from '@conduction/nextcloud-vue'
import { useOrganizationStore } from '../../store/modules/organization.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'OrganizationDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		CnFormDialog,
		CnDeleteDialog,
		Breadcrumb,
	},
	setup() {
		const store = useOrganizationStore()
		const detail = useDetailView({
			objectType: 'organization',
			fetchFn: (type, id) => store.fetchObject(type, id),
			saveFn: (type, data) => store.saveObject(type, data),
			deleteFn: (type, id) => store.deleteObject(type, id),
		})
		return { ...detail }
	},
	data() {
		return {
			activeTab: 'details',
		}
	},
	computed: {
		breadcrumbs() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Organizations'), route: '/organizations' },
				{ label: this.objectData?.name || '...' },
			]
		},
		displayFields() {
			if (!this.objectData) return {}
			const { id, uuid, _schema, _register, ...fields } = this.objectData
			return fields
		},
		formFields() {
			return fieldsFromSchema(
				useOrganizationStore().schemaDefinitions?.organization || {},
			)
		},
	},
	async mounted() {
		await this.load(this.$route.params.id)
	},
	methods: {
		onEdit() {
			this.editing = true
		},
		async onSave(data) {
			await this.save(data)
			this.editing = false
		},
		async onConfirmDelete() {
			await this.executeDelete(this.objectData.id)
			this.$router.push('/organizations')
		},
	},
}
</script>

<style scoped>
.organization-detail {
	padding: 8px 4px 24px;
	max-width: 1000px;
}

.organization-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.organization-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
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
	grid-template-columns: 180px 1fr;
	gap: 8px 16px;
}

.organization-detail__fields dt {
	font-weight: 600;
	text-transform: capitalize;
}

.organization-detail__fields dd {
	margin: 0;
}

.organization-detail__hint {
	color: var(--color-text-maxcontrast);
}
</style>
