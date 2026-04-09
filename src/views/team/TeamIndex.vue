<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnIndexPage :title="t('shillinq', 'Teams')">
		<template #actions>
			<NcButton type="primary" @click="showCreateDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('shillinq', 'Add Team') }}
			</NcButton>
		</template>

		<NcLoadingIcon v-if="teamStore.loading" />

		<table v-else class="shillinq-team-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Description') }}</th>
					<th>{{ t('shillinq', 'Created') }}</th>
					<th>{{ t('shillinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="team in teamStore.teams" :key="team.id">
					<td>{{ team.name }}</td>
					<td>{{ team.description }}</td>
					<td>{{ team.createdAt }}</td>
					<td>
						<NcButton @click="$router.push({ name: 'TeamDetail', params: { id: team.id } })">
							{{ t('shillinq', 'Manage Members') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcDialog v-if="showCreateDialog"
			:name="t('shillinq', 'Add Team')"
			@close="showCreateDialog = false">
			<div class="shillinq-team-index__form">
				<label>{{ t('shillinq', 'Name') }}
					<input v-model="newTeam.name" type="text">
				</label>
				<label>{{ t('shillinq', 'Description') }}
					<input v-model="newTeam.description" type="text">
				</label>
				<NcButton type="primary" @click="createTeam">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</div>
		</NcDialog>
	</CnIndexPage>
</template>

<script>
import { NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import { useTeamStore } from '../../store/modules/team.js'

export default {
	name: 'TeamIndex',
	components: { CnIndexPage, NcButton, NcLoadingIcon, NcDialog, PlusIcon },
	data() {
		return {
			teamStore: useTeamStore(),
			showCreateDialog: false,
			newTeam: { name: '', description: '' },
		}
	},
	created() {
		this.teamStore.fetchTeams()
	},
	methods: {
		async createTeam() {
			await this.teamStore.saveTeam(this.newTeam)
			this.showCreateDialog = false
			this.newTeam = { name: '', description: '' }
			this.teamStore.fetchTeams()
		},
	},
}
</script>

<style scoped>
.shillinq-team-index__table { width: 100%; border-collapse: collapse; }
.shillinq-team-index__table th, .shillinq-team-index__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.shillinq-team-index__form label { display: block; margin-bottom: 12px; }
.shillinq-team-index__form input { display: block; width: 100%; margin-top: 4px; }
</style>
