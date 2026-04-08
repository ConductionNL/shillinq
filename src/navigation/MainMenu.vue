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
				:to="{ name: 'Roles' }">
				<template #icon>
					<ShieldAccountOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Teams')"
				:to="{ name: 'Teams' }">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Users')"
				:to="{ name: 'Users' }">
				<template #icon>
					<AccountOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Access Log')"
				:to="{ name: 'AccessLog' }">
				<template #icon>
					<ShieldLockOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Recertification')"
				:to="{ name: 'Recertifications' }">
				<template #icon>
					<CalendarCheckOutline :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('shillinq', 'Reports')"
				:to="{ name: 'AccessRightsReport' }">
				<template #icon>
					<ChartBoxOutline :size="20" />
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
import { NcAppNavigation, NcAppNavigationItem, NcAppNavigationCaption } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CalendarCheckOutline from 'vue-material-design-icons/CalendarCheckOutline.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationCaption,
		AccountGroupOutline,
		AccountOutline,
		BookOpenVariantOutline,
		CalendarCheckOutline,
		ChartBoxOutline,
		CogIcon,
		HomeIcon,
		ShieldAccountOutline,
		ShieldLockOutline,
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
