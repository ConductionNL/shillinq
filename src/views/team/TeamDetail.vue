<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnDetailPage
		:title="team ? team.name : '...'"
		:description="team ? team.description : ''">
		<NcLoadingIcon v-if="teamStore.loading" />

		<template v-else-if="team">
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
				<NcEmptyContent :name="t('shillinq', 'Team members will appear here')" />
			</div>
		</template>

		<TeamInviteDialog v-if="showInviteDialog"
			:team-id="$route.params.id"
			@close="showInviteDialog = false" />
	</CnDetailPage>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { useTeamStore } from '../../store/modules/team.js'
import TeamInviteDialog from './TeamInviteDialog.vue'

export default {
	name: 'TeamDetail',
	components: { CnDetailPage, NcButton, NcLoadingIcon, NcEmptyContent, TeamInviteDialog },
	data() {
		return {
			teamStore: useTeamStore(),
			activeTab: 'details',
			showInviteDialog: false,
		}
	},
	computed: {
		team() { return this.teamStore.team },
	},
	created() {
		this.teamStore.fetchTeam(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-team-detail__tabs { display: flex; gap: 8px; margin-bottom: 16px; }
.shillinq-team-detail__section dl { display: grid; grid-template-columns: 180px 1fr; gap: 8px; }
.shillinq-team-detail__section dt { font-weight: bold; }
</style>
