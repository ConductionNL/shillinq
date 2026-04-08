<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('shillinq', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Analytics')"
				:to="{ name: 'AnalyticsDashboard' }">
				<template #icon>
					<ChartBoxOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Portal')"
				:to="{ name: 'PortalTokenList' }">
				<template #icon>
					<KeyOutlineIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Automation')"
				:to="{ name: 'AutomationRuleList' }">
				<template #icon>
					<RobotOutlineIcon :size="20" />
				</template>
				<template v-if="activeRuleCount > 0" #counter>
					<NcCounterBubble>{{ activeRuleCount }}</NcCounterBubble>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Expenses')"
				:to="{ name: 'ExpenseClaimList' }">
				<template #icon>
					<CashMultipleIcon :size="20" />
				</template>
				<template v-if="pendingClaimCount > 0" #counter>
					<NcCounterBubble type="highlighted">{{ pendingClaimCount }}</NcCounterBubble>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('shillinq', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem, NcCounterBubble } from '@nextcloud/vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CashMultipleIcon from 'vue-material-design-icons/CashMultiple.vue'
import ChartBoxOutlineIcon from 'vue-material-design-icons/ChartBoxOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import RobotOutlineIcon from 'vue-material-design-icons/RobotOutline.vue'
import { useAutomationRuleStore } from '../store/modules/automationRule.js'
import { useExpenseClaimStore } from '../store/modules/expenseClaim.js'

export default {
	name: 'MainMenu',
	components: {
		BookOpenVariantOutline,
		CashMultipleIcon,
		ChartBoxOutlineIcon,
		CogIcon,
		HomeIcon,
		KeyOutlineIcon,
		NcAppNavigation,
		NcAppNavigationItem,
		NcCounterBubble,
		RobotOutlineIcon,
	},
	computed: {
		activeRuleCount() {
			const store = useAutomationRuleStore()
			return store.getActiveRules.length
		},
		pendingClaimCount() {
			const store = useExpenseClaimStore()
			return store.getPendingCount
		},
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
