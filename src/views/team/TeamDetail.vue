<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-team-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Teams')" :to="{ name: 'TeamIndex' }" />
			<NcBreadcrumb :name="team ? team.name : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="teamStore.loading" />

		<template v-else-if="team">
			<header class="shillinq-team-detail__header">
				<h2>{{ team.name }}</h2>
			</header>

			<div class="shillinq-team-detail__tabs">
				<NcButton :type="activeTab === 'details' ? 'primary' : 'secondary'"
					@click="activeTab = 'details'">
					{{ t('shillinq', 'Details') }}
				</NcButton>
				<NcButton :type="activeTab === 'members' ? 'primary' : 'secondary'"
					@click="activeTab = 'members'">
					{{ t('shillinq', 'Members') }}
				</NcButton>
			</div>

			<div v-if="activeTab === 'details'" class="shillinq-team-detail__section">
				<dl>
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ team.description || '—' }}</dd>
					<dt>{{ t('shillinq', 'Created') }}</dt>
					<dd>{{ team.createdAt }}</dd>
				</dl>
			</div>

			<div v-if="activeTab === 'members'" class="shillinq-team-detail__section">
				<NcButton type="primary" @click="showInviteDialog = true">
					{{ t('shillinq', 'Invite Member') }}
				</NcButton>
				<p>{{ t('shillinq', 'Team member list will appear here.') }}</p>
			</div>
		</template>

		<TeamInviteDialog
			v-if="showInviteDialog"
			:team-id="$route.params.id"
			@close="showInviteDialog = false" />
	</div>
</template>

<script>
import { NcBreadcrumb, NcBreadcrumbs, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useTeamStore } from '../../store/modules/team.js'
import TeamInviteDialog from './TeamInviteDialog.vue'

export default {
	name: 'TeamDetail',
	components: {
		NcBreadcrumb,
		NcBreadcrumbs,
		NcButton,
		NcLoadingIcon,
		TeamInviteDialog,
	},
	data() {
		return {
			teamStore: useTeamStore(),
			activeTab: 'details',
			showInviteDialog: false,
		}
	},
	computed: {
		team() {
			return this.teamStore.currentTeam
		},
	},
	created() {
		this.teamStore.fetchTeam(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-team-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-team-detail__header {
	margin-bottom: 16px;
}

.shillinq-team-detail__tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.shillinq-team-detail__section dl {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 8px;
}

.shillinq-team-detail__section dt {
	font-weight: 600;
}
</style>
