<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-role-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Roles')" :to="{ name: 'Roles' }" />
			<NcBreadcrumb :name="role ? role.name : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="roleStore.loading" />

		<template v-else-if="role">
			<header class="shillinq-role-detail__header">
				<h2>{{ role.name }}</h2>
				<NcBadge v-if="role.isActive" type="success">
					{{ t('shillinq', 'Active') }}
				</NcBadge>
				<NcBadge v-else type="warning">
					{{ t('shillinq', 'Inactive') }}
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
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ role.description || '—' }}</dd>
					<dt>{{ t('shillinq', 'Level') }}</dt>
					<dd>{{ role.level }}</dd>
					<dt>{{ t('shillinq', 'Purchasing Limit') }}</dt>
					<dd>{{ role.purchasingLimitAmount || t('shillinq', 'No limit') }}</dd>
					<dt>{{ t('shillinq', 'Limit Category') }}</dt>
					<dd>{{ role.purchasingLimitCategory || t('shillinq', 'All categories') }}</dd>
				</dl>
			</div>

			<div v-if="activeTab === 'permissions'" class="shillinq-role-detail__section">
				<NcEmptyContent :name="t('shillinq', 'Field-level permissions for this role')">
					{{ t('shillinq', 'Configure which fields this role can read and write.') }}
				</NcEmptyContent>
			</div>

			<div v-if="activeTab === 'members'" class="shillinq-role-detail__section">
				<NcEmptyContent :name="t('shillinq', 'Users assigned to this role')">
					{{ t('shillinq', 'Users with this role will appear here.') }}
				</NcEmptyContent>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue'
import NcBadge from '@nextcloud/vue/dist/Components/NcBadge.js'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'RoleDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcBreadcrumbs,
		NcBreadcrumb,
		NcBadge,
	},
	data() {
		return {
			roleStore: useRoleStore(),
			activeTab: 'details',
		}
	},
	computed: {
		role() {
			return this.roleStore.role
		},
	},
	created() {
		this.roleStore.fetchRole(this.$route.params.id)
	},
	methods: {},
}
</script>

<style scoped>
.shillinq-role-detail {
	padding: 8px 16px 24px;
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
	grid-template-columns: 180px 1fr;
	gap: 8px;
}

.shillinq-role-detail__section dt {
	font-weight: bold;
}
</style>
