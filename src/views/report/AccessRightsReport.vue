<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-report">
		<header class="shillinq-report__header">
			<h2>{{ t('shillinq', 'Access Rights Report') }}</h2>
			<NcButton type="primary" @click="exportCsv">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('shillinq', 'Export CSV') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" />

		<table v-else class="shillinq-report__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Username') }}</th>
					<th>{{ t('shillinq', 'Display Name') }}</th>
					<th>{{ t('shillinq', 'Roles') }}</th>
					<th>{{ t('shillinq', 'Teams') }}</th>
					<th>{{ t('shillinq', 'Last Login') }}</th>
					<th>{{ t('shillinq', 'Branch') }}</th>
					<th>{{ t('shillinq', 'Delegations') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.username">
					<td>{{ row.username }}</td>
					<td>{{ row.displayName }}</td>
					<td>{{ row.roles }}</td>
					<td>{{ row.teams }}</td>
					<td>
						{{ row.lastLogin || '—' }}
						<NcBadge v-if="isInactive(row.lastLogin)" type="warning">
							{{ t('shillinq', 'Inactive account') }}
						</NcBadge>
					</td>
					<td>{{ row.branch }}</td>
					<td>{{ row.delegationsActive }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import NcBadge from '@nextcloud/vue/dist/Components/NcBadge.js'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AccessRightsReport',
	components: { NcButton, NcLoadingIcon, NcBadge, DownloadIcon },
	data() {
		return {
			loading: false,
			rows: [],
		}
	},
	created() {
		this.fetchReport()
	},
	methods: {
		async fetchReport() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/reports/access-rights?format=html'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				const data = await response.json()
				this.rows = data.results || []
			} catch (error) {
				console.error('Failed to fetch report:', error)
			} finally {
				this.loading = false
			}
		},
		exportCsv() {
			const url = generateUrl('/apps/shillinq/api/v1/reports/access-rights?format=csv')
			window.open(url, '_blank')
		},
		isInactive(lastLogin) {
			if (!lastLogin) return false
			const loginDate = new Date(lastLogin)
			const ninetyDaysAgo = new Date()
			ninetyDaysAgo.setDate(ninetyDaysAgo.getDate() - 90)
			return loginDate < ninetyDaysAgo
		},
	},
}
</script>

<style scoped>
.shillinq-report { padding: 8px 16px 24px; max-width: 1200px; }
.shillinq-report__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.shillinq-report__table { width: 100%; border-collapse: collapse; }
.shillinq-report__table th, .shillinq-report__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
</style>
