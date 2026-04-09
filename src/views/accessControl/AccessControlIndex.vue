<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="shillinq-access-log-index">
		<header class="shillinq-access-log-index__header">
			<h2>{{ t('shillinq', 'Access Log') }}</h2>
		</header>

		<div class="shillinq-access-log-index__filters">
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

		<NcLoadingIcon v-if="accessControlStore.loading" />

		<table v-else class="shillinq-access-log-index__table">
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
				<tr v-for="event in accessControlStore.events"
					:key="event.id"
					@click="$router.push({ name: 'AccessControlDetail', params: { id: event.id } })">
					<td>{{ event.timestamp }}</td>
					<td>{{ event.action }}</td>
					<td>{{ event.resourceType }}</td>
					<td>{{ event.resourceId || '—' }}</td>
					<td>
						<NcBadge :type="resultBadgeType(event.result)">
							{{ event.result }}
						</NcBadge>
					</td>
					<td>{{ event.ipAddress || '—' }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcBadge, NcLoadingIcon } from '@nextcloud/vue'
import { useAccessControlStore } from '../../store/modules/accessControl.js'

export default {
	name: 'AccessControlIndex',
	components: {
		NcBadge,
		NcLoadingIcon,
	},
	data() {
		return {
			accessControlStore: useAccessControlStore(),
			filterAction: '',
			filterResult: '',
			actions: [
				'create', 'read', 'update', 'delete',
				'login', 'logout', 'permission-denied',
				'delegation-created', 'delegation-revoked',
			],
		}
	},
	created() {
		this.accessControlStore.fetchEvents()
	},
	methods: {
		fetchFiltered() {
			this.accessControlStore.fetchEvents({
				action: this.filterAction,
				result: this.filterResult,
			})
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
.shillinq-access-log-index {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.shillinq-access-log-index__header {
	margin-bottom: 16px;
}

.shillinq-access-log-index__filters {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.shillinq-access-log-index__table {
	width: 100%;
	border-collapse: collapse;
}

.shillinq-access-log-index__table th,
.shillinq-access-log-index__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.shillinq-access-log-index__table tbody tr {
	cursor: pointer;
}

.shillinq-access-log-index__table tbody tr:hover {
	background: var(--color-background-hover);
}
</style>
