<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
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
				:to="{ name: 'OrganizationList' }"
				:exact="false">
				<template #icon>
					<DomainIcon :size="20" />
				</template>
				<template v-if="organizationCount > 0" #counter>
					<NcCounterBubble>{{ organizationCount }}</NcCounterBubble>
				</template>
			</NcAppNavigationItem>

			<NcAppNavigationItem
				:name="t('shillinq', 'Data Jobs')"
				:to="{ name: 'DataJobList' }"
				:exact="false">
				<template #icon>
					<DatabaseImportIcon :size="20" />
				</template>
				<template v-if="openJobCount > 0" #counter>
					<NcCounterBubble type="highlighted">{{ openJobCount }}</NcCounterBubble>
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
				:to="{ name: 'AppSettingsPage' }">
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
import CogIcon from 'vue-material-design-icons/Cog.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import DatabaseImportIcon from 'vue-material-design-icons/DatabaseImport.vue'

import { useOrganizationStore } from '../store/modules/organization.js'
import { useDataJobStore } from '../store/modules/dataJob.js'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcCounterBubble,
		BookOpenVariantOutline,
		CogIcon,
		HomeIcon,
		DomainIcon,
		DatabaseImportIcon,
	},

	computed: {
		organizationCount() {
			const store = useOrganizationStore()
			return store.objectList?.length ?? 0
		},
		openJobCount() {
			const store = useDataJobStore()
			return (store.objectList ?? []).filter(
				j => j.status === 'pending' || j.status === 'processing',
			).length
		},
	},

	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
