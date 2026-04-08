<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="datajob-detail">
		<Breadcrumb :items="breadcrumbItems" />

		<CnDetailPage
			:object="currentObject"
			:store="dataJobStore"
			:tabs="tabs" />

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
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcProgressBar } from '@nextcloud/vue'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'DataJobDetail',
	components: {
		CnDetailPage,
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
		tabs() {
			return [
				{ id: 'details', label: t('shillinq', 'Details') },
			]
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
