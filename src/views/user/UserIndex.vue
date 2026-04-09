<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-user-index">
		<header class="shillinq-user-index__header">
			<h2>{{ t('shillinq', 'Users') }}</h2>
		</header>

		<NcLoadingIcon v-if="userStore.loading" />

		<table v-else class="shillinq-user-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Display Name') }}</th>
					<th>{{ t('shillinq', 'Email') }}</th>
					<th>{{ t('shillinq', 'Username') }}</th>
					<th>{{ t('shillinq', 'Last Login') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="user in userStore.users"
					:key="user.id"
					:class="{ 'shillinq-user-index__inactive': !user.isActive }">
					<td>{{ user.displayName }}</td>
					<td>{{ user.email }}</td>
					<td>{{ user.username }}</td>
					<td>{{ user.lastLogin || '—' }}</td>
					<td>
						<NcBadge :type="user.isActive ? 'success' : 'error'">
							{{ user.isActive ? t('shillinq', 'Active') : t('shillinq', 'Inactive') }}
						</NcBadge>
					</td>
					<td>
						<NcButton @click="$router.push({ name: 'UserDetail', params: { id: user.id } })">
							{{ t('shillinq', 'View') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcBadge, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useUserStore } from '../../store/modules/user.js'

export default {
	name: 'UserIndex',
	components: {
		NcBadge,
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			userStore: useUserStore(),
		}
	},
	created() {
		this.userStore.fetchUsers()
	},
}
</script>

<style scoped>
.shillinq-user-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-user-index__header {
	margin-bottom: 16px;
}

.shillinq-user-index__table {
	width: 100%;
	border-collapse: collapse;
}

.shillinq-user-index__table th,
.shillinq-user-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.shillinq-user-index__inactive {
	opacity: 0.5;
}
</style>
