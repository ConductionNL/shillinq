<template>
	<CnAdminSettingsShell
		appId="shillinq"
		appName="Shillinq"
		look="board"
		versionCardPosition="end"
		title="Shillinq"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="
			t('shillinq', 'only for administrators, each section saves on its own')
		"
		docUrl="https://shillinq.conduction.nl/docs/intro"
		@reimported="onReimported">
		<Settings v-if="storesReady" />

		<PipelinqIntegration v-if="storesReady" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
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

	computed: {
		/**
		 * Board ShInstellingen: the path under the title, "Beheerinstellingen /
		 * Shillinq / Algemeen". The first crumb goes back to the server's
		 * administration settings.
		 *
		 * @return {Array<{label: string, href?: string}>}
		 * @spec exclude board header path, display only
		 */
		breadcrumbs() {
			return [
				{
					label: this.t('shillinq', 'Administration settings'),
					href: generateUrl('/settings/admin'),
				},
				{ label: 'Shillinq' },
				{ label: this.t('shillinq', 'General') },
			]
		},
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
