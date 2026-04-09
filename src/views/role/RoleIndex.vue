<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnIndexPage :title="t('shillinq', 'Roles')">
		<template #actions>
			<NcButton type="primary" @click="showCreateDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'Add Role') }}
			</NcButton>
		</template>

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
						<NcBadge v-if="role.isActive" type="success">
							{{ t('shillinq', 'Active') }}
						</NcBadge>
						<NcBadge v-else type="warning">
							{{ t('shillinq', 'Inactive') }}
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

		<NcDialog v-if="showCreateDialog"
			:name="t('shillinq', 'Add Role')"
			@close="showCreateDialog = false">
			<div class="shillinq-role-index__form">
				<label>{{ t('shillinq', 'Name') }}
					<input v-model="newRole.name" type="text">
				</label>
				<label>{{ t('shillinq', 'Description') }}
					<input v-model="newRole.description" type="text">
				</label>
				<label>{{ t('shillinq', 'Level') }}
					<input v-model.number="newRole.level" type="number">
				</label>
				<NcButton type="primary" @click="createRole">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</NcDialog>
	</CnIndexPage>
</template>

<script>
import { NcBadge, NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'RoleIndex',
	components: {
		CnIndexPage,
		NcButton,
		NcLoadingIcon,
		NcDialog,
		NcBadge,
		PlusIcon,
	},
	data() {
		return {
			roleStore: useRoleStore(),
			showCreateDialog: false,
			newRole: { name: '', description: '', level: 0 },
		}
	},
	created() {
		this.roleStore.fetchRoles()
	},
	methods: {
		async createRole() {
			await this.roleStore.saveRole({ ...this.newRole, isActive: true })
			this.showCreateDialog = false
			this.newRole = { name: '', description: '', level: 0 }
			this.roleStore.fetchRoles()
		},
	},
}
</script>

<style scoped>
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

.shillinq-role-index__form label {
	display: block;
	margin-bottom: 12px;
}

.shillinq-role-index__form input {
	display: block;
	width: 100%;
	margin-top: 4px;
}
</style>
