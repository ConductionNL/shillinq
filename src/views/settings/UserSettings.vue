<template>
	<NcAppSettingsDialog
		:open="open"
		:show-navigation="false"
		:name="t('shillinq', 'Shillinq settings')"
		@update:open="$emit('update:open', $event)">
		<NcAppSettingsSection
			id="general"
			:name="t('shillinq', 'General')">
			<template #icon>
				<CogIcon :size="20" />
			</template>
			<NcEmptyContent
				:name="t('shillinq', 'No settings available yet')"
				:description="t('shillinq', 'User settings will appear here in a future update.')">
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
