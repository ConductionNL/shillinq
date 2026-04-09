<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-user-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Users')" :to="{ name: 'UserIndex' }" />
			<NcBreadcrumb :name="user ? user.displayName : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="userStore.loading" />

		<template v-else-if="user">
			<header class="shillinq-user-detail__header">
				<h2>{{ user.displayName }}</h2>
				<NcBadge :type="user.isActive ? 'success' : 'error'">
					{{ user.isActive ? t('shillinq', 'Active') : t('shillinq', 'Inactive') }}
				</NcBadge>
			</header>

			<div class="shillinq-user-detail__tabs">
				<NcButton :type="activeTab === 'profile' ? 'primary' : 'secondary'"
					@click="activeTab = 'profile'">
					{{ t('shillinq', 'Profile') }}
				</NcButton>
				<NcButton :type="activeTab === 'roles' ? 'primary' : 'secondary'"
					@click="activeTab = 'roles'">
					{{ t('shillinq', 'Roles & Permissions') }}
				</NcButton>
				<NcButton :type="activeTab === 'delegations' ? 'primary' : 'secondary'"
					@click="activeTab = 'delegations'">
					{{ t('shillinq', 'Delegations') }}
				</NcButton>
				<NcButton :type="activeTab === 'history' ? 'primary' : 'secondary'"
					@click="activeTab = 'history'">
					{{ t('shillinq', 'Access History') }}
				</NcButton>
			</div>

			<div v-if="activeTab === 'profile'" class="shillinq-user-detail__section">
				<dl>
					<dt>{{ t('shillinq', 'Email') }}</dt>
					<dd>{{ user.email }}</dd>
					<dt>{{ t('shillinq', 'Username') }}</dt>
					<dd>{{ user.username }}</dd>
					<dt>{{ t('shillinq', 'Branch') }}</dt>
					<dd>{{ user.branch || '—' }}</dd>
					<dt>{{ t('shillinq', 'Last Login') }}</dt>
					<dd>{{ user.lastLogin || '—' }}</dd>
					<dt>{{ t('shillinq', 'Created') }}</dt>
					<dd>{{ user.createdAt }}</dd>
				</dl>
			</div>

			<div v-if="activeTab === 'roles'" class="shillinq-user-detail__section">
				<p>{{ t('shillinq', 'Assigned roles and permissions for this user.') }}</p>
			</div>

			<div v-if="activeTab === 'delegations'" class="shillinq-user-detail__section">
				<NcButton type="primary" @click="showDelegationDialog = true">
					{{ t('shillinq', 'Grant Delegation') }}
				</NcButton>
				<p>{{ t('shillinq', 'Active delegations for this user.') }}</p>
			</div>

			<div v-if="activeTab === 'history'" class="shillinq-user-detail__section">
				<p>{{ t('shillinq', 'Recent access history events for this user.') }}</p>
			</div>
		</template>

		<DelegationDialog
			v-if="showDelegationDialog"
			@close="showDelegationDialog = false" />
	</div>
</template>

<script>
import { NcBadge, NcBreadcrumb, NcBreadcrumbs, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useUserStore } from '../../store/modules/user.js'
import DelegationDialog from '../delegation/DelegationDialog.vue'

export default {
	name: 'UserDetail',
	components: {
		NcBadge,
		NcBreadcrumb,
		NcBreadcrumbs,
		NcButton,
		NcLoadingIcon,
		DelegationDialog,
	},
	data() {
		return {
			userStore: useUserStore(),
			activeTab: 'profile',
			showDelegationDialog: false,
		}
	},
	computed: {
		user() {
			return this.userStore.currentUser
		},
	},
	created() {
		this.userStore.fetchUser(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-user-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-user-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.shillinq-user-detail__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.shillinq-user-detail__section dl {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 8px;
}

.shillinq-user-detail__section dt {
	font-weight: 600;
}
</style>
