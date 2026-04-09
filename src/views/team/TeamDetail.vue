<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-team-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Teams')" :to="{ name: 'Teams' }" />
			<NcBreadcrumb :name="team ? team.name : '...'" />
		</NcBreadcrumbs>

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

			<CnConfigurationCard v-if="activeTab === 'details'" :title="team.name">
				<dl class="shillinq-team-detail__fields">
					<dt>{{ t('shillinq', 'Description') }}</dt>
					<dd>{{ team.description || '—' }}</dd>
					<dt>{{ t('shillinq', 'Created') }}</dt>
					<dd>{{ team.createdAt }}</dd>
				</dl>
			</CnConfigurationCard>

			<CnConfigurationCard v-if="activeTab === 'members'" :title="t('shillinq', 'Members')">
				<NcButton type="primary" @click="showInviteDialog = true">
					{{ t('shillinq', 'Invite Member') }}
				</NcButton>
				<NcEmptyContent :name="t('shillinq', 'No members yet')" />
			</CnConfigurationCard>
		</template>

		<TeamInviteDialog v-if="showInviteDialog"
			:team-id="$route.params.id"
			@close="showInviteDialog = false" />
	</div>
</template>

<script>
import { NcBreadcrumb, NcBreadcrumbs, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnConfigurationCard } from '@conduction/nextcloud-vue'
import { useTeamStore } from '../../store/modules/team.js'
import TeamInviteDialog from './TeamInviteDialog.vue'

export default {
	name: 'TeamDetail',
	components: { CnConfigurationCard, NcBreadcrumb, NcBreadcrumbs, NcButton, NcEmptyContent, NcLoadingIcon, TeamInviteDialog },
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
.shillinq-team-detail { padding: 8px 16px 24px; max-width: 1200px; }
.shillinq-team-detail__tabs { display: flex; gap: 8px; margin-bottom: 16px; }
.shillinq-team-detail__fields { display: grid; grid-template-columns: 180px 1fr; gap: 8px; }
.shillinq-team-detail__fields dt { font-weight: bold; }
</style>
