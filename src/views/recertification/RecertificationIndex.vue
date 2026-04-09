<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-recert-index">
		<header class="shillinq-recert-index__header">
			<h2>{{ t('shillinq', 'Recertification') }}</h2>
		</header>

		<NcLoadingIcon v-if="recertStore.loading" />

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
				<tr v-for="campaign in recertStore.campaigns" :key="campaign.id">
					<td>{{ campaign.name }}</td>
					<td>{{ campaign.cronExpression }}</td>
					<td>{{ campaign.lastRunAt || '—' }}</td>
					<td>{{ campaign.nextRunAt || '—' }}</td>
					<td>
						<NcBadge v-if="campaign.isActive" type="success">
							{{ t('shillinq', 'Active') }}
						</NcBadge>
						<NcBadge v-else type="warning">
							{{ t('shillinq', 'Inactive') }}
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
	</div>
</template>

<script>
import { NcBadge, NcButton, NcLoadingIcon } from '@nextcloud/vue'

import { useRecertificationStore } from '../../store/modules/recertification.js'

export default {
	name: 'RecertificationIndex',
	components: { NcButton, NcLoadingIcon, NcBadge },
	data() {
		return { recertStore: useRecertificationStore() }
	},
	created() {
		this.recertStore.fetchCampaigns()
	},
}
</script>

<style scoped>
.shillinq-recert-index { padding: 8px 16px 24px; max-width: 1200px; }
.shillinq-recert-index__header { margin-bottom: 16px; }
.shillinq-recert-index__table { width: 100%; border-collapse: collapse; }
.shillinq-recert-index__table th, .shillinq-recert-index__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
</style>
