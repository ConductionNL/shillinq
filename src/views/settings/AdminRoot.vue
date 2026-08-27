<template>
	<CnAdminSettingsShell
		appId="shillinq"
		appName="Shillinq"
		docUrl="https://shillinq.conduction.nl/docs/intro"
		@reimported="onReimported">
		<Settings v-if="storesReady" />

		<PipelinqIntegration v-if="storesReady" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import PipelinqIntegration from './PipelinqIntegration.vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		Settings,
		PipelinqIntegration,
	},

	data() {
		return {
			storesReady: false,
		}
	},

	/**
	 * Bring up the Pinia stores (settings) so the embedded Settings form can
	 * read register data, then reveal it.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-5
	 */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/** Reload register-backed config after a successful re-import. */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
