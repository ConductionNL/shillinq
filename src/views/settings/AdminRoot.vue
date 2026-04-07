<template>
	<div class="shillinq-admin">
		<CnVersionInfoCard
			:app-name="'Shillinq'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('shillinq', 'Version Information')"
			:description="t('shillinq', 'Information about the current Shillinq installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('shillinq', 'Support') }}</h4>
					<p>{{ t('shillinq', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
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
			appVersion: document.getElementById('shillinq-settings')?.dataset?.version || 'Unknown',
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.shillinq-admin {
	max-width: 900px;
}
</style>
