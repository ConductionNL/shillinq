<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-8.5
-->
<template>
	<NcContent app-name="shillinq">
		<template v-if="storesReady && !hasOpenRegisters">
			<NcAppContent class="open-register-missing">
				<NcEmptyContent
					:name="t('shillinq', 'OpenRegister is required')"
					:description="t('shillinq', 'This app needs OpenRegister to store and manage data. Please install OpenRegister from the app store to get started.')">
					<template #icon>
						<img :src="appIcon"
							alt=""
							width="64"
							height="64">
					</template>
					<template #action>
						<NcButton
							v-if="isAdmin"
							type="primary"
							:href="appStoreUrl">
							{{ t('shillinq', 'Install OpenRegister') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</NcAppContent>
		</template>
		<template v-else-if="storesReady && hasOpenRegisters">
			<MainMenu />
			<NcAppContent>
				<div class="shillinq-app__search">
					<GlobalSearch />
				</div>
				<router-view />
			</NcAppContent>
		</template>
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcButton, NcContent, NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import { initializeStores } from './store/store.js'
import { useSettingsStore } from './store/modules/settings.js'
import MainMenu from './navigation/MainMenu.vue'
import GlobalSearch from './components/GlobalSearch.vue'

export default {
	name: 'App',
	components: {
		NcButton,
		NcContent,
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		MainMenu,
		GlobalSearch,
	},

	data() {
		return {
			storesReady: false,
		}
	},

	computed: {
		hasOpenRegisters() {
			const settingsStore = useSettingsStore()
			return settingsStore.hasOpenRegisters
		},
		isAdmin() {
			const settingsStore = useSettingsStore()
			return settingsStore.getIsAdmin
		},
		appIcon() {
			return imagePath('shillinq', 'app-dark.svg')
		},
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
	},

	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.shillinq-app__search {
	padding: 8px 16px;
	display: flex;
	justify-content: flex-end;
}
</style>
