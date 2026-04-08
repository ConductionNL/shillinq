<template>
	<div class="app-template-dashboard">
		<header class="app-template-dashboard__header">
			<h2>{{ t('app-template', 'Dashboard') }}</h2>
			<p class="app-template-dashboard__lead">
				{{ t('app-template', 'Starter overview with sample KPIs and activity placeholders. Replace this view with your own data.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('app-template', 'Open items')"
				:count="12"
				:count-label="t('app-template', 'sample')"
				:icon="FolderOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('app-template', 'Due this week')"
				:count="5"
				:count-label="t('app-template', 'sample')"
				:icon="CalendarClock"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('app-template', 'Completed')"
				:count="48"
				:count-label="t('app-template', 'sample')"
				:icon="CheckCircleOutline"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('app-template', 'Team members')"
				:count="7"
				:count-label="t('app-template', 'sample')"
				:icon="AccountGroupOutline"
				variant="default"
				horizontal />
		</CnKpiGrid>

		<div class="app-template-dashboard__columns">
			<CnConfigurationCard :title="t('app-template', 'Recent activity')">
				<ul class="app-template-dashboard__placeholder-list">
					<li>{{ t('app-template', 'Placeholder: user opened a record') }}</li>
					<li>{{ t('app-template', 'Placeholder: status changed to Review') }}</li>
					<li>{{ t('app-template', 'Placeholder: comment added') }}</li>
				</ul>
			</CnConfigurationCard>

			<CnConfigurationCard :title="t('app-template', 'Quick actions')">
				<p class="app-template-dashboard__hint">
					{{ t('app-template', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'

export default {
	name: 'Dashboard',
	components: {
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			FolderOutline,
			CalendarClock,
			CheckCircleOutline,
			AccountGroupOutline,
		}
	},
}
</script>

<style scoped>
.app-template-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.app-template-dashboard__header {
	margin-bottom: 20px;
}

.app-template-dashboard__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.app-template-dashboard__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.app-template-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
}

@media (max-width: 900px) {
	.app-template-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.app-template-dashboard__placeholder-list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}

.app-template-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
