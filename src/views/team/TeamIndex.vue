<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-team-index">
		<header class="shillinq-team-index__header">
			<h2>{{ t('shillinq', 'Teams') }}</h2>
			<NcButton type="primary" @click="showAddDialog = true">
				{{ t('shillinq', 'Add Team') }}
			</NcButton>
		</header>

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

		<NcDialog v-if="showAddDialog"
			:name="t('shillinq', 'Add Team')"
			@closing="showAddDialog = false">
			<form @submit.prevent="saveNewTeam">
				<div class="form-group">
					<label>{{ t('shillinq', 'Name') }}</label>
					<input v-model="newTeam.name" type="text" required>
				</div>
				<div class="form-group">
					<label>{{ t('shillinq', 'Description') }}</label>
					<input v-model="newTeam.description" type="text">
				</div>
				<NcButton type="primary" native-type="submit">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</form>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { useTeamStore } from '../../store/modules/team.js'

export default {
	name: 'TeamIndex',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	data() {
		return {
			teamStore: useTeamStore(),
			showAddDialog: false,
			newTeam: { name: '', description: '' },
		}
	},
	created() {
		this.teamStore.fetchTeams()
	},
	methods: {
		async saveNewTeam() {
			await this.teamStore.saveTeam(this.newTeam)
			this.showAddDialog = false
			this.newTeam = { name: '', description: '' }
		},
	},
}
</script>

<style scoped>
.shillinq-team-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-team-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.shillinq-team-index__table {
	width: 100%;
	border-collapse: collapse;
}

.shillinq-team-index__table th,
.shillinq-team-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.form-group input {
	width: 100%;
}
</style>
