<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnDetailPage
		:title="role ? role.name : '...'"
		:description="role ? role.description : ''">
		<NcLoadingIcon v-if="roleStore.loading" />

		<template v-else-if="role">
			<div class="shillinq-role-detail__status">
				<NcBadge v-if="role.isActive" type="success">
					{{ t('shillinq', 'Active') }}
				</NcBadge>
				<NcBadge v-else type="warning">
					{{ t('shillinq', 'Inactive') }}
				</NcBadge>
			</div>

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
	</CnDetailPage>
</template>

<script>
import { NcBadge, NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { useRoleStore } from '../../store/modules/role.js'

export default {
	name: 'RoleDetail',
	components: {
		CnDetailPage,
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
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
.shillinq-role-detail__status {
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
