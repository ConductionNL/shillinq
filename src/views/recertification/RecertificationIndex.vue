<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-recert-index">
		<header class="shillinq-recert-index__header">
			<h2>{{ t('shillinq', 'Recertification') }}</h2>
			<NcButton type="primary" @click="showAddDialog = true">
				{{ t('shillinq', 'Add Campaign') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="recertificationStore.loading" />

		<table v-else class="shillinq-recert-index__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Name') }}</th>
					<th>{{ t('shillinq', 'Schedule') }}</th>
					<th>{{ t('shillinq', 'Last Run') }}</th>
					<th>{{ t('shillinq', 'Next Run') }}</th>
					<th>{{ t('shillinq', 'Status') }}</th>
					<th>{{ t('shillinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="campaign in recertificationStore.campaigns" :key="campaign.id">
					<td>{{ campaign.name }}</td>
					<td><code>{{ campaign.cronExpression }}</code></td>
					<td>{{ campaign.lastRunAt || '—' }}</td>
					<td>{{ campaign.nextRunAt || '—' }}</td>
					<td>
						<NcBadge :type="campaign.isActive ? 'success' : 'error'">
							{{ campaign.isActive ? t('shillinq', 'Active') : t('shillinq', 'Inactive') }}
						</NcBadge>
					</td>
					<td>
						<NcButton @click="$router.push({ name: 'RecertificationReview', params: { id: campaign.id } })">
							{{ t('shillinq', 'Review') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcDialog v-if="showAddDialog"
			:name="t('shillinq', 'Add Campaign')"
			@closing="showAddDialog = false">
			<form @submit.prevent="saveCampaign">
				<div class="form-group">
					<label>{{ t('shillinq', 'Name') }}</label>
					<input v-model="newCampaign.name" type="text" required>
				</div>
				<div class="form-group">
					<label>{{ t('shillinq', 'Cron Expression') }}</label>
					<input v-model="newCampaign.cronExpression" type="text" placeholder="0 9 1 * *" required>
				</div>
				<NcButton type="primary" native-type="submit">
					{{ t('shillinq', 'Save') }}
				</NcButton>
			</form>
		</NcDialog>
	</div>
</template>

<script>
import { NcBadge, NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { useRecertificationStore } from '../../store/modules/recertification.js'

export default {
	name: 'RecertificationIndex',
	components: {
		NcBadge,
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	data() {
		return {
			recertificationStore: useRecertificationStore(),
			showAddDialog: false,
			newCampaign: { name: '', cronExpression: '', isActive: true },
		}
	},
	created() {
		this.recertificationStore.fetchCampaigns()
	},
	methods: {
		async saveCampaign() {
			await this.recertificationStore.createCampaign(this.newCampaign)
			this.showAddDialog = false
			this.newCampaign = { name: '', cronExpression: '', isActive: true }
		},
	},
}
</script>

<style scoped>
.shillinq-recert-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-recert-index__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.shillinq-recert-index__table {
	width: 100%;
	border-collapse: collapse;
}

.shillinq-recert-index__table th,
.shillinq-recert-index__table td {
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
