<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-report">
		<header class="shillinq-report__header">
			<h2>{{ t('shillinq', 'Access Rights Report') }}</h2>
			<NcButton type="primary" @click="exportCsv">
				{{ t('shillinq', 'Export CSV') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" />

		<template v-else-if="!hasAccess">
			<NcEmptyContent :name="t('shillinq', 'Access Denied')">
				<template #icon>
					<ShieldLockOutline :size="64" />
				</template>
				<template #description>
					{{ t('shillinq', 'Only administrators can view this report.') }}
				</template>
			</NcEmptyContent>
		</template>

		<table v-else class="shillinq-report__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Username') }}</th>
					<th>{{ t('shillinq', 'Display Name') }}</th>
					<th>{{ t('shillinq', 'Roles') }}</th>
					<th>{{ t('shillinq', 'Teams') }}</th>
					<th>{{ t('shillinq', 'Last Login') }}</th>
					<th>{{ t('shillinq', 'Branch') }}</th>
					<th>{{ t('shillinq', 'Active Delegations') }}</th>
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
					<td>{{ row.branch || '—' }}</td>
					<td>{{ row.delegationsActive }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcBadge, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'

export default {
	name: 'AccessRightsReport',
	components: {
		NcBadge,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		ShieldLockOutline,
	},
	data() {
		return {
			rows: [],
			loading: true,
			hasAccess: true,
		}
	},
	async created() {
		try {
			const response = await fetch(
				generateUrl('/apps/shillinq/api/v1/reports/access-rights?format=html'),
				{ headers: { requesttoken: OC.requestToken } },
			)
			if (response.status === 403) {
				this.hasAccess = false
			} else if (response.ok) {
				this.rows = await response.json()
			}
		} catch (error) {
			console.error('Failed to load report:', error)
		} finally {
			this.loading = false
		}
	},
	methods: {
		exportCsv() {
			window.open(
				generateUrl('/apps/shillinq/api/v1/reports/access-rights?format=csv'),
				'_blank',
			)
		},
		isInactive(lastLogin) {
			if (!lastLogin) return false
			const ninetyDaysAgo = new Date(Date.now() - 90 * 24 * 60 * 60 * 1000)
			return new Date(lastLogin) < ninetyDaysAgo
		},
	},
}
</script>

<style scoped>
.shillinq-report {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-report__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.shillinq-report__table {
	width: 100%;
	border-collapse: collapse;
}

.shillinq-report__table th,
.shillinq-report__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}
</style>
