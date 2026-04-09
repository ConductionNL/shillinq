<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-role-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Roles')" :to="{ name: 'RoleIndex' }" />
			<NcBreadcrumb :name="role ? role.name : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="roleStore.loading" />

		<template v-else-if="role">
			<header class="shillinq-role-detail__header">
				<h2>{{ role.name }}</h2>
				<NcBadge :type="role.isActive ? 'success' : 'error'">
					{{ role.isActive ? t('shillinq', 'Active') : t('shillinq', 'Inactive') }}
				</NcBadge>
			</header>

			<div class="shillinq-role-detail__tabs">
				<NcButton :type="activeTab === 'details' ? 'primary' : 'secondary'"
					@click="activeTab = 'details'">
					{{ t('shillinq', 'Details') }}
				</NcButton>
				<NcButton :type="activeTab === 'permissions' ? 'primary' : 'secondary'"
					@click="activeTab = 'permissions'">
					{{ t('shillinq', 'Permissions') }}
				</NcButton>
				<NcButton :type="activeTab === 'members' ? 'primary' : 'secondary'"
					@click="activeTab = 'members'">
					{{ t('shillinq', 'Members') }}
				</NcButton>
			</div>

			<div v-if="activeTab === 'details'" class="shillinq-role-detail__section">
				<dl>
					<dt>{{ t('shillinq', 'Level') }}</dt>
					<dd>{{ role.level }}</dd>
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ role.description || '—' }}</dd>
					<dt>{{ t('shillinq', 'Purchasing Limit') }}</dt>
					<dd>{{ role.purchasingLimitAmount || '—' }}</dd>
					<dt>{{ t('shillinq', 'Purchasing Category') }}</dt>
					<dd>{{ role.purchasingLimitCategory || t('shillinq', 'All categories') }}</dd>
				</dl>
			</div>

			<div v-if="activeTab === 'permissions'" class="shillinq-role-detail__section">
				<p>{{ t('shillinq', 'Field-level permission settings for this role.') }}</p>
			</div>

			<div v-if="activeTab === 'members'" class="shillinq-role-detail__section">
				<p>{{ t('shillinq', 'Users assigned to this role.') }}</p>
			</div>
		</template>
	</div>
</template>

<script>
import { NcBadge, NcBreadcrumb, NcBreadcrumbs, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'RoleDetail',
	components: {
		NcBadge,
		NcBreadcrumb,
		NcBreadcrumbs,
		NcButton,
		NcLoadingIcon,
	},
	data() {
		return {
			roleStore: useRoleStore(),
			activeTab: 'details',
		}
	},
	computed: {
		role() {
			return this.roleStore.currentRole
		},
	},
	created() {
		this.roleStore.fetchRole(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-role-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-role-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.shillinq-role-detail__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.shillinq-role-detail__section dl {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 8px;
}

.shillinq-role-detail__section dt {
	font-weight: 600;
}
</style>
