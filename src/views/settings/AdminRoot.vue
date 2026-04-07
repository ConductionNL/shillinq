<template>
	<div class="app-template-admin">
		<CnVersionInfoCard
			:app-name="'App Template'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('app-template', 'Version Information')"
			:description="t('app-template', 'Information about the current App Template installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('app-template', 'Support') }}</h4>
					<p>{{ t('app-template', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
				</div>
			</template>
		</CnVersionInfoCard>

		<Settings v-if="storesReady" />
	</div>
</template>

<script>
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		Settings,
	},
	data() {
		return {
			storesReady: false,
			appVersion: document.getElementById('app-template-settings')?.dataset?.version || 'Unknown',
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.app-template-admin {
	max-width: 900px;
}
</style>
