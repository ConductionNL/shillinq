<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnIndexPage :title="t('shillinq', 'Users')">
		<NcLoadingIcon v-if="userStore.loading" />

		<table v-else class="shillinq-user-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Display Name') }}</th>
					<th>{{ t('shillinq', 'Email') }}</th>
					<th>{{ t('shillinq', 'Last Login') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="user in userStore.users"
					:key="user.id"
					:class="{ 'shillinq-user-index__row--inactive': !user.isActive }">
					<td>{{ user.displayName }}</td>
					<td>{{ user.email }}</td>
					<td>{{ user.lastLogin || '—' }}</td>
					<td>
						<NcBadge v-if="user.isActive" type="success">
							{{ t('shillinq', 'Active') }}
						</NcBadge>
						<NcBadge v-else type="error">
							{{ t('shillinq', 'Inactive') }}
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
	</CnIndexPage>
</template>

<script>
import { NcBadge, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useUserStore } from '../../store/modules/user.js'

export default {
	name: 'UserIndex',
	components: { CnIndexPage, NcButton, NcLoadingIcon, NcBadge },
	data() {
		return { userStore: useUserStore() }
	},
	created() {
		this.userStore.fetchUsers()
	},
}
</script>

<style scoped>
.shillinq-user-index__table { width: 100%; border-collapse: collapse; }
.shillinq-user-index__table th, .shillinq-user-index__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.shillinq-user-index__row--inactive { opacity: 0.5; }
</style>
