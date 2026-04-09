<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-role-index">
		<header class="shillinq-role-index__header">
			<h2>{{ t('shillinq', 'Roles') }}</h2>
			<NcButton type="primary" @click="showAddDialog = true">
				{{ t('shillinq', 'Add Role') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="roleStore.loading" />

		<table v-else class="shillinq-role-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Level') }}</th>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="role in roleStore.roles" :key="role.id">
					<td>{{ role.name }}</td>
					<td>{{ role.level }}</td>
					<td>{{ role.description }}</td>
					<td>
						<NcBadge :type="role.isActive ? 'success' : 'error'">
							{{ role.isActive ? t('shillinq', 'Active') : t('shillinq', 'Inactive') }}
						</NcBadge>
					</td>
					<td>
						<NcButton @click="$router.push({ name: 'RoleDetail', params: { id: role.id } })">
							{{ t('shillinq', 'View') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcDialog v-if="showAddDialog"
			:name="t('shillinq', 'Add Role')"
			@closing="showAddDialog = false">
			<form @submit.prevent="saveNewRole">
				<div class="form-group">
					<label>{{ t('shillinq', 'Name') }}</label>
					<input v-model="newRole.name" type="text" required>
				</div>
				<div class="form-group">
					<label>{{ t('shillinq', 'Level') }}</label>
					<input v-model.number="newRole.level" type="number" min="0" max="100">
				</div>
				<div class="form-group">
					<label>{{ t('shillinq', 'Description') }}</label>
					<input v-model="newRole.description" type="text">
				</div>
				<NcButton type="primary" native-type="submit">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</form>
		</NcDialog>
	</div>
</template>

<script>
import { NcBadge, NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'RoleIndex',
	components: {
		NcBadge,
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	data() {
		return {
			roleStore: useRoleStore(),
			showAddDialog: false,
			newRole: { name: '', level: 0, description: '', isActive: true },
		}
	},
	created() {
		this.roleStore.fetchRoles()
	},
	methods: {
		async saveNewRole() {
			await this.roleStore.saveRole(this.newRole)
			this.showAddDialog = false
			this.newRole = { name: '', level: 0, description: '', isActive: true }
		},
	},
}
</script>

<style scoped>
.shillinq-role-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-role-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.shillinq-role-index__table {
	width: 100%;
	border-collapse: collapse;
}

.shillinq-role-index__table th,
.shillinq-role-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.form-group input {
	width: 100%;
}
</style>
