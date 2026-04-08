<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-access-log">
		<header class="shillinq-access-log__header">
			<h2>{{ t('shillinq', 'Access Log') }}</h2>
			<div class="shillinq-access-log__filters">
				<select v-model="filterAction" @change="fetchFiltered">
					<option value="">
						{{ t('shillinq', 'All actions') }}
					</option>
					<option v-for="action in actions" :key="action" :value="action">
						{{ action }}
					</option>
				</select>
				<select v-model="filterResult" @change="fetchFiltered">
					<option value="">
						{{ t('shillinq', 'All results') }}
					</option>
					<option value="success">
						{{ t('shillinq', 'Success') }}
					</option>
					<option value="denied">
						{{ t('shillinq', 'Denied') }}
					</option>
					<option value="error">
						{{ t('shillinq', 'Error') }}
					</option>
				</select>
			</div>
		</header>

		<NcLoadingIcon v-if="accessControlStore.loading" />

		<table v-else class="shillinq-access-log__table">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Timestamp') }}</th>
					<th>{{ t('shillinq', 'Action') }}</th>
					<th>{{ t('shillinq', 'Resource Type') }}</th>
					<th>{{ t('shillinq', 'Resource ID') }}</th>
					<th>{{ t('shillinq', 'Result') }}</th>
					<th>{{ t('shillinq', 'IP Address') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="entry in accessControlStore.entries"
					:key="entry.id"
					@click="$router.push({ name: 'AccessControlDetail', params: { id: entry.id } })">
					<td>{{ entry.timestamp }}</td>
					<td>{{ entry.action }}</td>
					<td>{{ entry.resourceType }}</td>
					<td>{{ entry.resourceId }}</td>
					<td>
						<NcBadge :type="resultBadgeType(entry.result)">
							{{ entry.result }}
						</NcBadge>
					</td>
					<td>{{ entry.ipAddress }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import NcBadge from '@nextcloud/vue/dist/Components/NcBadge.js'
import { useAccessControlStore } from '../../store/modules/accessControl.js'

export default {
	name: 'AccessControlIndex',
	components: { NcLoadingIcon, NcBadge },
	data() {
		return {
			accessControlStore: useAccessControlStore(),
			filterAction: '',
			filterResult: '',
			actions: ['create', 'read', 'update', 'delete', 'login', 'logout', 'permission-denied', 'delegation-created', 'delegation-revoked'],
		}
	},
	created() {
		this.accessControlStore.fetchEntries()
	},
	methods: {
		fetchFiltered() {
			const filters = {}
			if (this.filterAction) filters.action = this.filterAction
			if (this.filterResult) filters.result = this.filterResult
			this.accessControlStore.fetchEntries(filters)
		},
		resultBadgeType(result) {
			if (result === 'success') return 'success'
			if (result === 'denied') return 'error'
			return 'warning'
		},
	},
}
</script>

<style scoped>
.shillinq-access-log { padding: 8px 16px 24px; max-width: 1200px; }
.shillinq-access-log__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.shillinq-access-log__filters { display: flex; gap: 8px; }
.shillinq-access-log__table { width: 100%; border-collapse: collapse; cursor: pointer; }
.shillinq-access-log__table th, .shillinq-access-log__table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.shillinq-access-log__table tbody tr:hover { background: var(--color-background-hover); }
</style>
