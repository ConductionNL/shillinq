<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-8.1
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
				:name="t('shillinq', 'Organizations')"
				:to="{ name: 'OrganizationList' }">
				<template #icon>
					<DomainIcon :size="20" />
				</template>
				<template v-if="organizationCount > 0" #counter>
					<NcCounterBubble>{{ organizationCount }}</NcCounterBubble>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Data Jobs')"
				:to="{ name: 'DataJobList' }">
				<template #icon>
					<DatabaseImportIcon :size="20" />
				</template>
				<template v-if="activeJobCount > 0" #counter>
					<NcCounterBubble :type="activeJobCount > 0 ? 'highlighted' : ''">
						{{ activeJobCount }}
					</NcCounterBubble>
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
import { useOrganizationStore } from '../store/modules/organization.js'
import { useDataJobStore } from '../store/modules/dataJob.js'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcCounterBubble,
		BookOpenVariantOutline,
		CogIcon,
		DatabaseImportIcon: DatabaseImportOutline,
		DomainIcon: Domain,
		HomeIcon,
	},
	computed: {
		organizationCount() {
			const store = useOrganizationStore()
			return store.pagination?.organization?.total
				|| store.collections?.organization?.length
				|| 0
		},
		activeJobCount() {
			const store = useDataJobStore()
			const jobs = store.collections?.dataJob || []
			return jobs.filter(j => j.status === 'pending' || j.status === 'processing').length
		},
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
