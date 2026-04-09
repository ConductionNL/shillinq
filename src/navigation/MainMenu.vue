<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/general/tasks.md#task-10.1
-->
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
					<ChartBoxOutline :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Portal')"
				:to="{ name: 'PortalTokenList' }">
				<template #icon>
					<KeyOutline :size="20" />
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Automation')"
				:to="{ name: 'AutomationRuleList' }">
				<template #icon>
					<RobotOutline :size="20" />
				</template>
				<template
					v-if="activeRuleCount > 0"
					#counter>
					<NcCounterBubble>{{ activeRuleCount }}</NcCounterBubble>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Expenses')"
				:to="{ name: 'ExpenseClaimList' }">
				<template #icon>
					<CashMultiple :size="20" />
				</template>
				<template
					v-if="pendingClaimCount > 0"
					#counter>
					<NcCounterBubble>{{ pendingClaimCount }}</NcCounterBubble>
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
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import KeyOutline from 'vue-material-design-icons/KeyOutline.vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import { useAutomationRuleStore } from '../store/modules/automationRule.js'
import { useExpenseClaimStore } from '../store/modules/expenseClaim.js'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcCounterBubble,
		BookOpenVariantOutline,
		CashMultiple,
		ChartBoxOutline,
		CogIcon,
		HomeIcon,
		KeyOutline,
		RobotOutline,
	},
	data() {
		return {
			ruleStore: useAutomationRuleStore(),
			claimStore: useExpenseClaimStore(),
		}
	},
	computed: {
		activeRuleCount() {
			return this.ruleStore.activeRuleCount
		},
		pendingClaimCount() {
			return this.claimStore.pendingClaimCount
		},
	},
	mounted() {
		this.ruleStore.fetchRules()
		this.claimStore.fetchClaims()
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
