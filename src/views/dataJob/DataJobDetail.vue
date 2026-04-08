<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="datajob-detail">
		<Breadcrumb :items="breadcrumbItems" />

		<div v-if="currentObject" class="datajob-detail__content">
			<div class="datajob-detail__header">
				<h2>{{ currentObject.fileName }}</h2>
				<span :class="'datajob-detail__status datajob-detail__status--' + currentObject.status">
					{{ currentObject.status }}
				</span>
			</div>

			<div class="datajob-detail__fields">
				<div v-for="(value, key) in displayFields"
					:key="key"
					class="datajob-detail__field">
					<span class="datajob-detail__label">
						{{ key }}
					</span>
					<span class="datajob-detail__value">
						{{ value ?? '—' }}
					</span>
				</div>
			</div>
		</div>

		<div v-if="currentObject && currentObject.errorLog" class="datajob-detail__error-log">
			<h3>{{ t('shillinq', 'Error Log') }}</h3>
			<pre class="datajob-detail__error-pre">{{ currentObject.errorLog }}</pre>
		</div>

		<div v-if="isProcessing" class="datajob-detail__progress">
			<NcProgressBar :value="progressPercent" />
			<p>{{ processedLabel }}</p>
		</div>
	</div>
</template>

<script>
import { NcProgressBar } from '@nextcloud/vue'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'DataJobDetail',
	components: {
		NcProgressBar,
		Breadcrumb,
	},

	data() {
		return {
			refreshInterval: null,
		}
	},

	computed: {
		dataJobStore() {
			return useDataJobStore()
		},
		currentObject() {
			return this.dataJobStore.currentObject
		},
		displayFields() {
			if (!this.currentObject) return {}
			const skip = ['id', 'uuid', 'errorLog', 'fileName', 'status']
			const fields = {}
			Object.entries(this.currentObject).forEach(([key, value]) => {
				if (!skip.includes(key) && !key.startsWith('_')) {
					fields[key] = value
				}
			})
			return fields
		},
		breadcrumbItems() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Data Jobs'), route: '/data-jobs' },
				{ label: this.currentObject?.fileName ?? '' },
			]
		},
		isProcessing() {
			return this.currentObject?.status === 'processing'
		},
		progressPercent() {
			const obj = this.currentObject
			if (obj === null || obj === undefined || obj.totalRecords === 0) {
				return 0
			}
			return Math.round(((obj.processedRecords ?? 0) / obj.totalRecords) * 100)
		},
		processedLabel() {
			const obj = this.currentObject
			if (obj === null || obj === undefined) {
				return ''
			}
			return `${obj.processedRecords ?? 0} / ${obj.totalRecords ?? 0}`
		},
	},

	async mounted() {
		await this.dataJobStore.fetchObject(this.$route.params.id)

		if (this.isProcessing) {
			this.refreshInterval = setInterval(async () => {
				await this.dataJobStore.fetchObject(this.$route.params.id)
				if (this.currentObject?.status !== 'processing') {
					clearInterval(this.refreshInterval)
				}
			}, 5000)
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
.datajob-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.datajob-detail__status {
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 13px;
	font-weight: 600;
	text-transform: capitalize;
}

.datajob-detail__status--completed {
	background-color: var(--color-success);
	color: white;
}

.datajob-detail__status--processing,
.datajob-detail__status--pending {
	background-color: var(--color-warning);
	color: white;
}

.datajob-detail__status--failed {
	background-color: var(--color-error);
	color: white;
}

.datajob-detail__fields {
	display: grid;
	grid-template-columns: 1fr 2fr;
	gap: 8px 16px;
}

.datajob-detail__label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: capitalize;
}

.datajob-detail__error-log {
	margin-top: 16px;
}

.datajob-detail__error-pre {
	background-color: var(--color-background-dark);
	padding: 12px;
	border-radius: 4px;
	max-height: 300px;
	overflow-y: auto;
	white-space: pre-wrap;
	font-size: 13px;
}

.datajob-detail__progress {
	margin-top: 16px;
}
</style>
