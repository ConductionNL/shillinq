<template>
	<div class="shillinq-dashboard">
		<header class="shillinq-dashboard__header">
			<h2>{{ t('shillinq', 'Dashboard') }}</h2>
			<p class="shillinq-dashboard__lead">
				{{ t('shillinq', 'Starter overview with sample KPIs and activity placeholders. Replace this view with your own data.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('shillinq', 'Open items')"
				:count="12"
				:count-label="t('shillinq', 'sample')"
				:icon="FolderOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('shillinq', 'Due this week')"
				:count="5"
				:count-label="t('shillinq', 'sample')"
				:icon="CalendarClock"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('shillinq', 'Completed')"
				:count="48"
				:count-label="t('shillinq', 'sample')"
				:icon="CheckCircleOutline"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('shillinq', 'Team members')"
				:count="7"
				:count-label="t('shillinq', 'sample')"
				:icon="AccountGroupOutline"
				variant="default"
				horizontal />
		</CnKpiGrid>

		<div class="shillinq-dashboard__columns">
			<CnConfigurationCard :title="t('shillinq', 'Recent activity')">
				<ul class="shillinq-dashboard__placeholder-list">
					<li>{{ t('shillinq', 'Placeholder: user opened a record') }}</li>
					<li>{{ t('shillinq', 'Placeholder: status changed to Review') }}</li>
					<li>{{ t('shillinq', 'Placeholder: comment added') }}</li>
				</ul>
			</CnConfigurationCard>

			<CnConfigurationCard :title="t('shillinq', 'Quick actions')">
				<p class="shillinq-dashboard__hint">
					{{ t('shillinq', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
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

.shillinq-dashboard__placeholder-list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}

.shillinq-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
