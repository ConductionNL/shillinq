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

			<NcAppNavigationCaption :name="t('shillinq', 'Security')" />

			<NcAppNavigationItem
				:name="t('shillinq', 'Roles')"
				:to="{ name: 'RoleIndex' }">
				<template #icon>
					<ShieldAccountOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Teams')"
				:to="{ name: 'TeamIndex' }">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Users')"
				:to="{ name: 'UserIndex' }">
				<template #icon>
					<AccountOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Access Log')"
				:to="{ name: 'AccessControlIndex' }">
				<template #icon>
					<ShieldLockOutline :size="20" />
				</template>
				<template v-if="deniedCount > 0" #counter>
					<NcCounterBubble type="highlighted">
						{{ deniedCount }}
					</NcCounterBubble>
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Recertification')"
				:to="{ name: 'RecertificationIndex' }">
				<template #icon>
					<CalendarCheckOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Reports')"
				:to="{ name: 'AccessRightsReport' }">
				<template #icon>
					<FileChartOutline :size="20" />
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
import { NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcCounterBubble } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import { useAccessControlStore } from '../store/modules/accessControl.js'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcCounterBubble,
		AccountGroupOutline,
		AccountOutline,
		BookOpenVariantOutline,
		CalendarCheckOutline,
		CogIcon,
		FileChartOutline,
		HomeIcon,
		ShieldAccountOutline,
		ShieldLockOutline,
	},
	data() {
		return {
			accessControlStore: useAccessControlStore(),
		}
	},
	computed: {
		deniedCount() {
			return this.accessControlStore.getDeniedCount
		},
	},
	created() {
		this.accessControlStore.fetchEvents()
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
