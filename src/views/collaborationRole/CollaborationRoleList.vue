<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-5.1
-->
<template>
	<CnIndexPage
		:title="t('shillinq', 'Team Roles')"
		:objects="roles"
		:columns="columns"
		:loading="loading"
		:selectable="false"
		:show-mass-import="false"
		:show-mass-export="false"
		:show-mass-copy="false"
		:show-mass-delete="false"
		:show-form-dialog="false"
		:empty-text="t('shillinq', 'No roles assigned')"
		row-key="id">
		<template #action-items>
			<NcButton type="primary" @click="showForm = true">
				{{ t('shillinq', 'Add Member') }}
			</NcButton>
		</template>
		<template #column-role="{ value }">
			<span :class="'role-badge role-badge--' + value">
				{{ t('shillinq', value) }}
			</span>
		</template>
		<template #column-expiresAt="{ value }">
			{{ value ? formatDate(value) : '—' }}
		</template>
		<template #column-grantedAt="{ value }">
			{{ formatDate(value) }}
		</template>
		<CollaborationRoleForm
			v-if="showForm"
			@close="showForm = false"
			@created="onRoleCreated" />
	</CnIndexPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import CollaborationRoleForm from './CollaborationRoleForm.vue'
import { useCollaborationRoleStore } from '../../store/modules/collaborationRole.js'

export default {
	name: 'CollaborationRoleList',
	components: {
		NcButton,
		CnIndexPage,
		CollaborationRoleForm,
	},
	data() {
		return {
			showForm: false,
			columns: [
				{ key: 'principalId', label: this.t('shillinq', 'Principal') },
				{ key: 'principalType', label: this.t('shillinq', 'Type') },
				{ key: 'role', label: this.t('shillinq', 'Role') },
				{ key: 'grantedBy', label: this.t('shillinq', 'Granted By') },
				{ key: 'grantedAt', label: this.t('shillinq', 'Granted At') },
				{ key: 'expiresAt', label: this.t('shillinq', 'Expires') },
			],
		}
	},
	computed: {
		roleStore() {
			return useCollaborationRoleStore()
		},
		loading() {
			return this.roleStore.loading
		},
		roles() {
			return this.roleStore.roles
		},
	},
	mounted() {
		// Fetch all roles for the context provided via route params, or show empty state.
		const targetType = this.$route.query.targetType || ''
		const targetId = this.$route.query.targetId || ''
		if (targetType && targetId) {
			this.roleStore.fetchByTarget(targetType, targetId)
		}
	},
	methods: {
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString()
		},
		onRoleCreated() {
			this.showForm = false
			const targetType = this.$route.query.targetType || ''
			const targetId = this.$route.query.targetId || ''
			if (targetType && targetId) {
				this.roleStore.fetchByTarget(targetType, targetId)
			}
		},
	},
}
</script>

<style scoped>
.role-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.role-badge--approver { background-color: var(--color-primary-element-light); color: var(--color-primary-element); }

.role-badge--reviewer { background-color: var(--color-success-hover); color: var(--color-success); }

.role-badge--contributor { background-color: var(--color-background-dark); }

.role-badge--viewer { background-color: var(--color-background-darker); }
</style>
