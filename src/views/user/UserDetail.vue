<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-user-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Users')" :to="{ name: 'Users' }" />
			<NcBreadcrumb :name="user ? user.displayName : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="userStore.loading" />

		<template v-else-if="user">
			<h2>{{ user.displayName }}</h2>

			<div class="shillinq-user-detail__tabs">
				<NcButton v-for="tab in tabs" :key="tab.id"
					:type="activeTab === tab.id ? 'primary' : 'secondary'"
					@click="activeTab = tab.id">
					{{ tab.label }}
				</NcButton>
			</div>

			<div v-if="activeTab === 'profile'" class="shillinq-user-detail__section">
				<dl>
					<dt>{{ t('shillinq', 'Username') }}</dt>
					<dd>{{ user.username }}</dd>
					<dt>{{ t('shillinq', 'Email') }}</dt>
					<dd>{{ user.email }}</dd>
					<dt>{{ t('shillinq', 'Branch') }}</dt>
					<dd>{{ user.branch || '—' }}</dd>
					<dt>{{ t('shillinq', 'Last Login') }}</dt>
					<dd>{{ user.lastLogin || '—' }}</dd>
					<dt>{{ t('shillinq', 'Created') }}</dt>
					<dd>{{ user.createdAt }}</dd>
				</dl>
			</div>

			<div v-if="activeTab === 'roles'" class="shillinq-user-detail__section">
				<NcEmptyContent :name="t('shillinq', 'Roles & Permissions')">
					{{ t('shillinq', 'Assigned roles and field-level permissions will appear here.') }}
				</NcEmptyContent>
			</div>

			<div v-if="activeTab === 'delegations'" class="shillinq-user-detail__section">
				<NcButton type="primary" @click="showDelegationDialog = true">
					{{ t('shillinq', 'Grant Delegation') }}
				</NcButton>
				<NcEmptyContent :name="t('shillinq', 'Active delegations')">
					{{ t('shillinq', 'Delegated roles will appear here.') }}
				</NcEmptyContent>
			</div>

			<div v-if="activeTab === 'history'" class="shillinq-user-detail__section">
				<NcEmptyContent :name="t('shillinq', 'Access History')">
					{{ t('shillinq', 'The last 50 access control events for this user.') }}
				</NcEmptyContent>
			</div>
		</template>

		<DelegationDialog v-if="showDelegationDialog"
			@close="showDelegationDialog = false" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcBreadcrumbs, NcBreadcrumb } from '@nextcloud/vue'
import { useUserStore } from '../../store/modules/user.js'
import DelegationDialog from '../delegation/DelegationDialog.vue'

export default {
	name: 'UserDetail',
	components: { NcButton, NcLoadingIcon, NcEmptyContent, NcBreadcrumbs, NcBreadcrumb, DelegationDialog },
	data() {
		return {
			userStore: useUserStore(),
			activeTab: 'profile',
			showDelegationDialog: false,
		}
	},
	computed: {
		user() { return this.userStore.user },
		tabs() {
			return [
				{ id: 'profile', label: this.t('shillinq', 'Profile') },
				{ id: 'roles', label: this.t('shillinq', 'Roles & Permissions') },
				{ id: 'delegations', label: this.t('shillinq', 'Delegations') },
				{ id: 'history', label: this.t('shillinq', 'Access History') },
			]
		},
	},
	created() {
		this.userStore.fetchUser(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-user-detail { padding: 8px 16px 24px; max-width: 1200px; }
.shillinq-user-detail__tabs { display: flex; gap: 8px; margin-bottom: 16px; }
.shillinq-user-detail__section dl { display: grid; grid-template-columns: 180px 1fr; gap: 8px; }
.shillinq-user-detail__section dt { font-weight: bold; }
</style>
