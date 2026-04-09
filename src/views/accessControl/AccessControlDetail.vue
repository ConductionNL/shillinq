<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-access-log-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Access Log')" :to="{ name: 'AccessControlIndex' }" />
			<NcBreadcrumb :name="event ? event.action : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="accessControlStore.loading" />

		<template v-else-if="event">
			<header class="shillinq-access-log-detail__header">
				<h2>{{ event.action }}</h2>
				<NcBadge :type="resultBadgeType(event.result)">
					{{ event.result }}
				</NcBadge>
			</header>

			<dl class="shillinq-access-log-detail__fields">
				<dt>{{ t('shillinq', 'Timestamp') }}</dt>
				<dd>{{ event.timestamp }}</dd>
				<dt>{{ t('shillinq', 'Action') }}</dt>
				<dd>{{ event.action }}</dd>
				<dt>{{ t('shillinq', 'Resource Type') }}</dt>
				<dd>{{ event.resourceType }}</dd>
				<dt>{{ t('shillinq', 'Resource ID') }}</dt>
				<dd>{{ event.resourceId || '—' }}</dd>
				<dt>{{ t('shillinq', 'Result') }}</dt>
				<dd>{{ event.result }}</dd>
				<dt>{{ t('shillinq', 'IP Address') }}</dt>
				<dd>{{ event.ipAddress || '—' }}</dd>
				<dt>{{ t('shillinq', 'User Agent') }}</dt>
				<dd>{{ event.userAgent || '—' }}</dd>
				<dt>{{ t('shillinq', 'Details') }}</dt>
				<dd>
					<pre v-if="event.details">{{ JSON.stringify(event.details, null, 2) }}</pre>
					<span v-else>—</span>
				</dd>
			</dl>
		</template>
	</div>
</template>

<script>
import { NcBadge, NcBreadcrumb, NcBreadcrumbs, NcLoadingIcon } from '@nextcloud/vue'
import { useAccessControlStore } from '../../store/modules/accessControl.js'

export default {
	name: 'AccessControlDetail',
	components: {
		NcBadge,
		NcBreadcrumb,
		NcBreadcrumbs,
		NcLoadingIcon,
	},
	data() {
		return {
			accessControlStore: useAccessControlStore(),
		}
	},
	computed: {
		event() {
			return this.accessControlStore.currentEvent
		},
	},
	created() {
		this.accessControlStore.fetchEvent(this.$route.params.id)
	},
	methods: {
		resultBadgeType(result) {
			if (result === 'success') return 'success'
			if (result === 'denied') return 'error'
			return 'warning'
		},
	},
}
</script>

<style scoped>
.shillinq-access-log-detail {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-access-log-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.shillinq-access-log-detail__fields {
	display: grid;
	grid-template-columns: 200px 1fr;
	gap: 8px;
}

.shillinq-access-log-detail__fields dt {
	font-weight: 600;
}

.shillinq-access-log-detail__fields pre {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 4px;
	overflow-x: auto;
	font-size: 13px;
}
</style>
