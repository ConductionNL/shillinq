<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-7.2
-->
<template>
	<div class="data-job-detail">
		<Breadcrumb :items="breadcrumbs" />
		<NcLoadingIcon v-if="loading" :size="64" />
		<template v-else-if="objectData && objectData.id">
			<div class="data-job-detail__header">
				<h2>{{ objectData.fileName }}</h2>
				<span :class="statusClass" class="data-job-detail__status">
					{{ objectData.status }}
				</span>
			</div>

			<dl class="data-job-detail__fields">
				<dt>{{ t('shillinq', 'Entity Type') }}</dt>
				<dd>{{ objectData.entityType }}</dd>

				<dt>{{ t('shillinq', 'Status') }}</dt>
				<dd>{{ objectData.status }}</dd>

				<dt>{{ t('shillinq', 'Total Records') }}</dt>
				<dd>{{ objectData.totalRecords || 0 }}</dd>

				<dt>{{ t('shillinq', 'Processed') }}</dt>
				<dd>{{ objectData.processedRecords || 0 }}</dd>

				<dt>{{ t('shillinq', 'Failed') }}</dt>
				<dd>{{ objectData.failedRecords || 0 }}</dd>

				<dt>{{ t('shillinq', 'Started At') }}</dt>
				<dd>{{ objectData.startedAt || '—' }}</dd>

				<dt>{{ t('shillinq', 'Completed At') }}</dt>
				<dd>{{ objectData.completedAt || '—' }}</dd>
			</dl>

			<div v-if="objectData.errorLog" class="data-job-detail__errors">
				<h3>{{ t('shillinq', 'Error Log') }}</h3>
				<pre class="data-job-detail__error-log">{{ objectData.errorLog }}</pre>
			</div>

			<div v-if="objectData.status === 'processing'" class="data-job-detail__progress">
				<progress
					:value="objectData.processedRecords || 0"
					:max="objectData.totalRecords || 1" />
				<span>
					{{ objectData.processedRecords || 0 }} / {{ objectData.totalRecords || 0 }}
				</span>
			</div>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useDetailView } from '@conduction/nextcloud-vue'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'DataJobDetail',
	components: {
		NcLoadingIcon,
		Breadcrumb,
	},
	setup() {
		const store = useDataJobStore()
		const detail = useDetailView({
			objectType: 'dataJob',
			fetchFn: (type, id) => store.fetchObject(type, id),
			saveFn: (type, data) => store.saveObject(type, data),
			deleteFn: (type, id) => store.deleteObject(type, id),
		})
		return { ...detail }
	},
	data() {
		return {
			refreshInterval: null,
		}
	},
	computed: {
		breadcrumbs() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Data Jobs'), route: '/data-jobs' },
				{ label: this.objectData?.fileName || '...' },
			]
		},
		statusClass() {
			const status = this.objectData?.status
			return {
				'data-job-detail__status--completed': status === 'completed',
				'data-job-detail__status--pending': status === 'pending' || status === 'processing',
				'data-job-detail__status--failed': status === 'failed',
			}
		},
	},
	async mounted() {
		await this.load(this.$route.params.id)
		if (this.objectData?.status === 'processing') {
			this.refreshInterval = setInterval(() => this.load(this.$route.params.id), 5000)
		}
	},
	beforeDestroy() {
		if (this.refreshInterval) {
			clearInterval(this.refreshInterval)
		}
	},
}
</script>

<style scoped>
.data-job-detail {
	padding: 8px 4px 24px;
	max-width: 1000px;
}

.data-job-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.data-job-detail__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.data-job-detail__status--completed {
	color: var(--color-success);
	font-weight: 600;
}

.data-job-detail__status--pending {
	color: var(--color-warning);
	font-weight: 600;
}

.data-job-detail__status--failed {
	color: var(--color-error);
	font-weight: 600;
}

.data-job-detail__fields {
	display: grid;
	grid-template-columns: 180px 1fr;
	gap: 8px 16px;
	margin-bottom: 24px;
}

.data-job-detail__fields dt {
	font-weight: 600;
}

.data-job-detail__fields dd {
	margin: 0;
}

.data-job-detail__errors h3 {
	font-size: 16px;
	margin-bottom: 8px;
}

.data-job-detail__error-log {
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	max-height: 400px;
	overflow: auto;
	white-space: pre-wrap;
	font-family: monospace;
	font-size: 13px;
}

.data-job-detail__progress {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
}

.data-job-detail__progress progress {
	flex: 1;
	height: 20px;
}
</style>
