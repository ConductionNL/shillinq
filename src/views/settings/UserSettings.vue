<template>
	<NcAppSettingsDialog
		:open="open"
		:show-navigation="false"
		:name="t('app-template', 'App Template settings')"
		@update:open="$emit('update:open', $event)">
		<NcAppSettingsSection
			id="general"
			:name="t('app-template', 'General')">
			<template #icon>
				<CogIcon :size="20" />
			</template>
			<NcEmptyContent
				:name="t('app-template', 'No settings available yet')"
				:description="t('app-template', 'User settings will appear here in a future update.')">
				<template #icon>
					<CogIcon :size="64" />
				</template>
			</NcEmptyContent>
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script>
import { NcAppSettingsDialog, NcAppSettingsSection, NcEmptyContent } from '@nextcloud/vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'

export default {
	name: 'UserSettings',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		NcEmptyContent,
		CogIcon,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
}
</script>
