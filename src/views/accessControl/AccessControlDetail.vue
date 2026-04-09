<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-access-detail">
		<NcBreadcrumbs>
			<NcBreadcrumb :name="t('shillinq', 'Shillinq')" :to="{ name: 'Dashboard' }" />
			<NcBreadcrumb :name="t('shillinq', 'Access Log')" :to="{ name: 'AccessControl' }" />
			<NcBreadcrumb :name="entry ? entry.action : '...'" />
		</NcBreadcrumbs>

		<NcLoadingIcon v-if="accessControlStore.loading" />

		<CnConfigurationCard v-else-if="entry"
			:title="entry.action + ' — ' + entry.resourceType">
			<dl class="shillinq-access-detail__fields">
				<dt>{{ t('shillinq', 'Timestamp') }}</dt>
				<dd>{{ entry.timestamp }}</dd>
				<dt>{{ t('shillinq', 'Action') }}</dt>
				<dd>{{ entry.action }}</dd>
				<dt>{{ t('shillinq', 'Resource Type') }}</dt>
				<dd>{{ entry.resourceType }}</dd>
				<dt>{{ t('shillinq', 'Resource ID') }}</dt>
				<dd>{{ entry.resourceId || '—' }}</dd>
				<dt>{{ t('shillinq', 'Result') }}</dt>
				<dd>
					<NcBadge :type="entry.result === 'success' ? 'success' : 'error'">
						{{ entry.result }}
					</NcBadge>
				</dd>
				<dt>{{ t('shillinq', 'IP Address') }}</dt>
				<dd>{{ entry.ipAddress || '—' }}</dd>
				<dt>{{ t('shillinq', 'User Agent') }}</dt>
				<dd>{{ entry.userAgent || '—' }}</dd>
				<dt>{{ t('shillinq', 'Details') }}</dt>
				<dd>
					<pre v-if="entry.details">{{ JSON.stringify(entry.details, null, 2) }}</pre>
					<span v-else>—</span>
				</dd>
			</dl>
		</CnConfigurationCard>
	</div>
</template>

<script>
import { NcBadge, NcBreadcrumb, NcBreadcrumbs, NcLoadingIcon } from '@nextcloud/vue'
import { CnConfigurationCard } from '@conduction/nextcloud-vue'
import { useAccessControlStore } from '../../store/modules/accessControl.js'

export default {
	name: 'AccessControlDetail',
	components: { CnConfigurationCard, NcBadge, NcBreadcrumb, NcBreadcrumbs, NcLoadingIcon },
	data() {
		return { accessControlStore: useAccessControlStore() }
	},
	computed: {
		entry() { return this.accessControlStore.entry },
	},
	created() {
		this.accessControlStore.fetchEntry(this.$route.params.id)
	},
}
</script>

<style scoped>
.shillinq-access-detail { padding: 8px 16px 24px; max-width: 1200px; }
.shillinq-access-detail__fields { display: grid; grid-template-columns: 180px 1fr; gap: 8px; }
.shillinq-access-detail__fields dt { font-weight: bold; }
.shillinq-access-detail__fields pre { background: var(--color-background-dark); padding: 8px; border-radius: 4px; overflow-x: auto; }
</style>
