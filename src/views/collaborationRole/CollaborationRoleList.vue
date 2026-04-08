<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  @spec openspec/changes/collaboration/tasks.md#task-5.1
-->
<template>
	<div class="role-list">
		<h2>{{ t('shillinq', 'Team Roles') }}</h2>
		<NcButton type="primary" @click="showForm = true">
			{{ t('shillinq', 'Add Member') }}
		</NcButton>
		<NcLoadingIcon v-if="loading" :size="44" />
		<table v-else class="role-list__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Principal') }}</th>
					<th>{{ t('shillinq', 'Type') }}</th>
					<th>{{ t('shillinq', 'Role') }}</th>
					<th>{{ t('shillinq', 'Granted By') }}</th>
					<th>{{ t('shillinq', 'Granted At') }}</th>
					<th>{{ t('shillinq', 'Expires') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="role in roles"
					:key="role.id"
					:class="{ 'role-list__row--expired': isExpired(role) }">
					<td>{{ role.principalId }}</td>
					<td>{{ role.principalType }}</td>
					<td>
						<span :class="'role-badge role-badge--' + role.role">
							{{ t('shillinq', role.role) }}
						</span>
					</td>
					<td>{{ role.grantedBy }}</td>
					<td>{{ formatDate(role.grantedAt) }}</td>
					<td>{{ role.expiresAt ? formatDate(role.expiresAt) : '—' }}</td>
				</tr>
			</tbody>
		</table>
		<NcEmptyContent
			v-if="!loading && roles.length === 0"
			:name="t('shillinq', 'No roles assigned')">
			<template #icon>
				<AccountGroupOutline :size="20" />
			</template>
		</NcEmptyContent>
		<CollaborationRoleForm
			v-if="showForm"
			@close="showForm = false"
			@created="onRoleCreated" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroup.vue'
import CollaborationRoleForm from './CollaborationRoleForm.vue'
import { useCollaborationRoleStore } from '../../store/modules/collaborationRole.js'

export default {
	name: 'CollaborationRoleList',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AccountGroupOutline,
		CollaborationRoleForm,
	},
	data() {
		return {
			showForm: false,
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
	methods: {
		isExpired(role) {
			if (!role.expiresAt) return false
			return new Date(role.expiresAt) < new Date()
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString()
		},
		onRoleCreated() {
			this.showForm = false
		},
	},
}
</script>

<style scoped>
.role-list {
	padding: 20px;
}

.role-list__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 16px;
}

.role-list__table th,
.role-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.role-list__row--expired {
	opacity: 0.6;
	background-color: var(--color-warning-hover);
}

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
