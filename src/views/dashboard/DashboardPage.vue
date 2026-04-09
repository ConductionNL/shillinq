<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-4.1
-->
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
				@click.native="$router.push('/organizations')" />
			<CnStatsBlock
				:title="t('shillinq', 'Open Data Jobs')"
				:count="openDataJobCount"
				:count-label="t('shillinq', 'pending')"
				:icon="DatabaseImportIcon"
				variant="warning"
				horizontal
				style="cursor: pointer;"
				@click.native="$router.push('/data-jobs')" />
			<CnStatsBlock
				:title="t('shillinq', 'App Settings')"
				:count="appSettingsCount"
				:count-label="t('shillinq', 'configured')"
				:icon="CogIcon"
				variant="default"
				horizontal
				style="cursor: pointer;"
				@click.native="$router.push('/settings')" />
			<CnStatsBlock
				:title="t('shillinq', 'Completed Jobs')"
				:count="completedJobCount"
				:count-label="t('shillinq', 'done')"
				:icon="CheckCircleIcon"
				variant="success"
				horizontal />
		</CnKpiGrid>

		<div class="shillinq-dashboard__columns">
			<CnConfigurationCard :title="t('shillinq', 'Quick Actions')">
				<div class="shillinq-dashboard__actions">
					<NcButton type="primary" @click="$router.push('/organizations')">
						<template #icon>
							<PlusIcon :size="20" />
						</template>
						{{ t('shillinq', 'Add Organization') }}
					</NcButton>
					<NcButton @click="$router.push('/data-jobs')">
						<template #icon>
							<UploadIcon :size="20" />
						</template>
						{{ t('shillinq', 'Import CSV') }}
					</NcButton>
					<NcButton @click="$router.push('/organizations')">
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
						@click="$router.push(`/data-jobs/${job.id}`)">
						<span class="shillinq-dashboard__job-name">{{ job.fileName }}</span>
						<span :class="statusClass(job.status)" class="shillinq-dashboard__job-status">
							{{ job.status }}
						</span>
					</li>
				</ul>
				<p v-else class="shillinq-dashboard__hint">
					{{ t('shillinq', 'No data jobs found yet.') }}
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
import { useDataJobStore } from '../../store/modules/dataJob.js'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Upload from 'vue-material-design-icons/Upload.vue'

export default {
	name: 'DashboardPage',
	components: {
		NcButton,
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
		PlusIcon: Plus,
		UploadIcon: Upload,
		DownloadIcon: Download,
	},
	data() {
		return {
			DomainIcon: Domain,
			DatabaseImportIcon: DatabaseImportOutline,
			CogIcon: CogOutline,
			CheckCircleIcon: CheckCircleOutline,
		}
	},
	computed: {
		organizationStore() {
			return useOrganizationStore()
		},
		appSettingsStore() {
			return useAppSettingsStore()
		},
		dataJobStore() {
			return useDataJobStore()
		},
		organizationCount() {
			return this.organizationStore.pagination?.organization?.total
				|| this.organizationStore.collections?.organization?.length
				|| 0
		},
		appSettingsCount() {
			return this.appSettingsStore.pagination?.appSettings?.total
				|| this.appSettingsStore.collections?.appSettings?.length
				|| 0
		},
		openDataJobCount() {
			const jobs = this.dataJobStore.collections?.dataJob || []
			return jobs.filter(j => j.status === 'pending' || j.status === 'processing').length
		},
		completedJobCount() {
			const jobs = this.dataJobStore.collections?.dataJob || []
			return jobs.filter(j => j.status === 'completed').length
		},
		recentDataJobs() {
			const jobs = this.dataJobStore.collections?.dataJob || []
			return jobs.slice(0, 5)
		},
	},
	async mounted() {
		await Promise.all([
			this.organizationStore.fetchCollection('organization'),
			this.appSettingsStore.fetchCollection('appSettings'),
			this.dataJobStore.fetchCollection('dataJob'),
		])
	},
	methods: {
		statusClass(status) {
			return {
				'shillinq-dashboard__status--completed': status === 'completed',
				'shillinq-dashboard__status--pending': status === 'pending' || status === 'processing',
				'shillinq-dashboard__status--failed': status === 'failed',
			}
		},
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
}

@media (max-width: 900px) {
	.shillinq-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.shillinq-dashboard__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
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
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
}

.shillinq-dashboard__job-item:last-child {
	border-bottom: none;
}

.shillinq-dashboard__job-item:hover {
	background-color: var(--color-background-hover);
}

.shillinq-dashboard__job-name {
	font-weight: 500;
}

.shillinq-dashboard__status--completed {
	color: var(--color-success);
}

.shillinq-dashboard__status--pending {
	color: var(--color-warning);
}

.shillinq-dashboard__status--failed {
	color: var(--color-error);
}

.shillinq-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
