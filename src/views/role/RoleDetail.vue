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

			<CnConfigurationCard v-if="activeTab === 'details'" :title="role.name">
				<div class="shillinq-role-detail__status">
					<NcBadge v-if="role.isActive" type="success">
						{{ t('shillinq', 'Active') }}
					</NcBadge>
					<NcBadge v-else type="warning">
						{{ t('shillinq', 'Inactive') }}
					</NcBadge>
				</div>
				<dl class="shillinq-role-detail__fields">
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ role.description || '—' }}</dd>
					<dt>{{ t('shillinq', 'Level') }}</dt>
					<dd>{{ role.level }}</dd>
					<dt>{{ t('shillinq', 'Purchasing Limit') }}</dt>
					<dd>{{ role.purchasingLimitAmount || t('shillinq', 'No limit') }}</dd>
					<dt>{{ t('shillinq', 'Limit Category') }}</dt>
					<dd>{{ role.purchasingLimitCategory || t('shillinq', 'All categories') }}</dd>
				</dl>
			</CnConfigurationCard>

			<CnConfigurationCard v-if="activeTab === 'permissions'" :title="t('shillinq', 'Field-level Permissions')">
				<NcEmptyContent :name="t('shillinq', 'No field-level permissions configured')">
					{{ t('shillinq', 'Configure which fields this role can read and write.') }}
				</NcEmptyContent>
			</CnConfigurationCard>

			<CnConfigurationCard v-if="activeTab === 'members'" :title="t('shillinq', 'Members')">
				<NcEmptyContent :name="t('shillinq', 'No members assigned to this role')">
					{{ t('shillinq', 'Users with this role will appear here.') }}
				</NcEmptyContent>
			</CnConfigurationCard>
		</template>
	</div>
</template>

<script>
import { NcBadge, NcBreadcrumb, NcBreadcrumbs, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnConfigurationCard } from '@conduction/nextcloud-vue'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'RoleDetail',
	components: {
		CnConfigurationCard,
		NcBadge,
		NcBreadcrumb,
		NcBreadcrumbs,
		NcButton,
		NcEmptyContent,
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
			return this.roleStore.role
		},
	},
	created() {
		this.roleStore.fetchRole(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-role-detail {
	padding: 8px 16px 24px;
	max-width: 1200px;
}

.shillinq-role-detail__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.shillinq-role-detail__status {
	margin-bottom: 16px;
}

.shillinq-role-detail__fields {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 8px;
}

.shillinq-role-detail__fields dt {
	font-weight: bold;
}
</style>
