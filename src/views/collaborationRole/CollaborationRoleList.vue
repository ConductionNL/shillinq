<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="collaboration-role-list">
		<header class="collaboration-role-list__header">
			<h2>{{ t('shillinq', 'Collaboration roles') }}</h2>
			<NcButton type="primary" @click="showForm = true">
				<template #icon>
					<AccountPlusOutline :size="20" />
				</template>
				{{ t('shillinq', 'Add member') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :size="44" />

		<CnIndexPage v-else>
			<template #default>
				<table class="collaboration-role-list__table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'Principal ID') }}</th>
							<th>{{ t('shillinq', 'Principal type') }}</th>
							<th>{{ t('shillinq', 'Role') }}</th>
							<th>{{ t('shillinq', 'Granted by') }}</th>
							<th>{{ t('shillinq', 'Granted at') }}</th>
							<th>{{ t('shillinq', 'Expires at') }}</th>
							<th>{{ t('shillinq', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="role in roles"
							:key="role.id"
							:class="{ 'collaboration-role-list__row--expired': isExpired(role) }">
							<td>{{ role.principalId }}</td>
							<td>{{ role.principalType }}</td>
							<td>
								<span
									class="collaboration-role-list__badge"
									:class="'collaboration-role-list__badge--' + role.role">
									{{ role.role }}
								</span>
							</td>
							<td>{{ role.grantedBy }}</td>
							<td>{{ formatTimestamp(role.grantedAt) }}</td>
							<td>
								<span v-if="role.expiresAt" :class="{ 'collaboration-role-list__expired-text': isExpired(role) }">
									{{ formatTimestamp(role.expiresAt) }}
									<AlertOutline v-if="isExpired(role)" :size="16" class="collaboration-role-list__warning-icon" />
								</span>
								<span v-else class="collaboration-role-list__no-expiry">{{ t('shillinq', 'Never') }}</span>
							</td>
							<td>
								<NcButton type="tertiary" @click="removeRole(role)">
									<template #icon>
										<DeleteOutline :size="20" />
									</template>
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<NcEmptyContent
					v-if="!loading && roles.length === 0"
					:name="t('shillinq', 'No collaboration roles')"
					:description="t('shillinq', 'Add members to start collaborating on this item.')">
					<template #icon>
						<AccountGroupOutline :size="64" />
					</template>
				</NcEmptyContent>
			</template>
		</CnIndexPage>

		<CollaborationRoleForm
			v-if="showForm"
			@close="showForm = false"
			@created="onRoleCreated" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useCollaborationRoleStore } from '../../store/modules/collaborationRole.js'
import CollaborationRoleForm from './CollaborationRoleForm.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'

export default {
	name: 'CollaborationRoleList',
	components: {
		CnIndexPage,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		CollaborationRoleForm,
		AccountPlusOutline,
		AccountGroupOutline,
		DeleteOutline,
		AlertOutline,
	},
	data() {
		return {
			roleStore: useCollaborationRoleStore(),
			showForm: false,
		}
	},
	computed: {
		roles() {
			return this.roleStore.roles
		},
		loading() {
			return this.roleStore.loading
		},
	},
	mounted() {
		this.roleStore.fetchRoles('', '')
	},
	methods: {
		isExpired(role) {
			if (!role.expiresAt) return false
			return new Date(role.expiresAt) < new Date()
		},
		formatTimestamp(ts) {
			if (!ts) return ''
			const date = new Date(ts)
			return date.toLocaleString()
		},
		async removeRole(role) {
			await this.roleStore.deleteRole(role.id)
		},
		onRoleCreated() {
			this.showForm = false
		},
	},
}
</script>

<style scoped>
.collaboration-role-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.collaboration-role-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.collaboration-role-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.collaboration-role-list__table {
	width: 100%;
	border-collapse: collapse;
}

.collaboration-role-list__table th {
	text-align: left;
	padding: 8px 12px;
	font-weight: 600;
	border-bottom: 2px solid var(--color-border);
}

.collaboration-role-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.collaboration-role-list__row--expired {
	background-color: var(--color-warning-hover, rgba(255, 193, 7, 0.1));
}

.collaboration-role-list__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.collaboration-role-list__badge--approver {
	background-color: var(--color-primary-element-light, #e8f0fe);
	color: var(--color-primary-element, #0082c9);
}

.collaboration-role-list__badge--reviewer {
	background-color: var(--color-success-hover, #e8f5e9);
	color: var(--color-success, #46ba61);
}

.collaboration-role-list__badge--contributor {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast);
}

.collaboration-role-list__badge--viewer {
	background-color: var(--color-background-hover, #f5f5f5);
	color: var(--color-text-lighter);
}

.collaboration-role-list__expired-text {
	color: var(--color-warning);
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.collaboration-role-list__warning-icon {
	color: var(--color-warning);
}

.collaboration-role-list__no-expiry {
	color: var(--color-text-maxcontrast);
}
</style>
