<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnIndexPage :title="t('shillinq', 'Access Rights Report')">
		<template #actions>
			<NcButton type="primary" @click="exportCsv">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('shillinq', 'Export CSV') }}
			</NcButton>
		</template>

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
	</CnIndexPage>
</template>

<script>
import { NcBadge, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AccessRightsReport',
	components: { CnIndexPage, NcButton, NcLoadingIcon, NcBadge, DownloadIcon },
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
.shillinq-report__table { width: 100%; border-collapse: collapse; }
.shillinq-report__table th, .shillinq-report__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
</style>
