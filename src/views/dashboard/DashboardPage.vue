<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-dashboard">
		<header class="shillinq-dashboard__header">
			<h2>{{ t('shillinq', 'Dashboard') }}</h2>
			<p class="shillinq-dashboard__lead">
				{{ t('shillinq', 'Overview of your business administration.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('shillinq', 'Organizations')"
				:count="organizationCount"
				:count-label="t('shillinq', 'total')"
				:icon="DomainIcon"
				variant="primary"
				horizontal
				style="cursor: pointer;"
				@click.native="$router.push({ name: 'OrganizationList' })" />
			<CnStatsBlock
				:title="t('shillinq', 'Open Data Jobs')"
				:count="openDataJobCount"
				:count-label="t('shillinq', 'pending')"
				:icon="DatabaseImportIcon"
				variant="warning"
				horizontal
				style="cursor: pointer;"
				@click.native="$router.push({ name: 'DataJobList' })" />
			<CnStatsBlock
				:title="t('shillinq', 'Settings')"
				:count="appSettingsCount"
				:count-label="t('shillinq', 'configured')"
				:icon="CogIcon"
				variant="default"
				horizontal
				style="cursor: pointer;"
				@click.native="$router.push({ name: 'AppSettingsPage' })" />
			<CnStatsBlock
				:title="t('shillinq', 'Dashboards')"
				:count="dashboardCount"
				:count-label="t('shillinq', 'total')"
				:icon="ViewDashboardIcon"
				variant="success"
				horizontal />
		</CnKpiGrid>

		<div class="shillinq-dashboard__columns">
			<CnConfigurationCard :title="t('shillinq', 'Quick actions')">
				<div class="shillinq-dashboard__actions">
					<NcButton type="primary"
						@click="$router.push({ name: 'OrganizationList', query: { create: '1' } })">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('shillinq', 'Add Organization') }}
					</NcButton>
					<NcButton @click="$router.push({ name: 'DataJobList', query: { import: '1' } })">
						<template #icon>
							<UploadIcon :size="20" />
						</template>
						{{ t('shillinq', 'Import CSV') }}
					</NcButton>
					<NcButton @click="$router.push({ name: 'OrganizationList', query: { export: '1' } })">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('shillinq', 'Export Data') }}
					</NcButton>
				</div>
			</CnConfigurationCard>

			<CnConfigurationCard :title="t('shillinq', 'Recent Data Jobs')">
				<ul v-if="recentDataJobs.length > 0" class="shillinq-dashboard__job-list">
					<li v-for="job in recentDataJobs"
						:key="job.id"
						class="shillinq-dashboard__job-item"
						@click="$router.push({ name: 'DataJobDetail', params: { id: job.id } })">
						<span class="shillinq-dashboard__job-name">{{ job.fileName }}</span>
						<span :class="'shillinq-dashboard__job-status shillinq-dashboard__job-status--' + job.status">
							{{ job.status }}
						</span>
					</li>
				</ul>
				<p v-else class="shillinq-dashboard__hint">
					{{ t('shillinq', 'No data jobs yet. Import a CSV to get started.') }}
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import { useOrganizationStore } from '../../store/modules/organization.js'
import { useAppSettingsStore } from '../../store/modules/appSettings.js'
import { useDashboardStore } from '../../store/modules/dashboard.js'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import DatabaseImportIcon from 'vue-material-design-icons/DatabaseImport.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ViewDashboardIcon from 'vue-material-design-icons/ViewDashboard.vue'

export default {
	name: 'DashboardPage',
	components: {
		NcButton,
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},

	data() {
		return {
			DomainIcon,
			DatabaseImportIcon,
			CogIcon,
			ViewDashboardIcon,
		}
	},

	computed: {
		organizationCount() {
			const store = useOrganizationStore()
			return store.objectList?.length ?? 0
		},
		openDataJobCount() {
			const store = useDataJobStore()
			return (store.objectList ?? []).filter(
				j => j.status === 'pending' || j.status === 'processing',
			).length
		},
		appSettingsCount() {
			const store = useAppSettingsStore()
			return store.objectList?.length ?? 0
		},
		dashboardCount() {
			const store = useDashboardStore()
			return store.objectList?.length ?? 0
		},
		recentDataJobs() {
			const store = useDataJobStore()
			return (store.objectList ?? []).slice(0, 5)
		},
	},

	async mounted() {
		const organizationStore = useOrganizationStore()
		const appSettingsStore = useAppSettingsStore()
		const dashboardStore = useDashboardStore()
		const dataJobStore = useDataJobStore()

		await Promise.all([
			organizationStore.fetchObjects(),
			appSettingsStore.fetchObjects(),
			dashboardStore.fetchObjects(),
			dataJobStore.fetchObjects(),
		])
	},
}
</script>

<style scoped>
.shillinq-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-dashboard__header {
	margin-bottom: 20px;
}

.shillinq-dashboard__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.shillinq-dashboard__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.shillinq-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-top: 16px;
}

@media (max-width: 900px) {
	.shillinq-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.shillinq-dashboard__actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.shillinq-dashboard__job-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.shillinq-dashboard__job-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 4px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.shillinq-dashboard__job-item:hover {
	background-color: var(--color-background-hover);
}

.shillinq-dashboard__job-name {
	font-weight: 500;
}

.shillinq-dashboard__job-status {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 10px;
}

.shillinq-dashboard__job-status--completed {
	background-color: var(--color-success);
	color: white;
}

.shillinq-dashboard__job-status--processing,
.shillinq-dashboard__job-status--pending {
	background-color: var(--color-warning);
	color: white;
}

.shillinq-dashboard__job-status--failed {
	background-color: var(--color-error);
	color: white;
}

.shillinq-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
