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
		<CnSettingsSection
			v-for="section in pageSections"
			:key="section.id"
			wide
			:name="section.name"
			:description="section.description"
			:data-testid="`admin-section-${section.id}`">
			<NcButton :href="section.href">
				{{ section.linkLabel }}
			</NcButton>
		</CnSettingsSection>

		<Settings v-if="storesReady" />

		<PipelinqIntegration v-if="storesReady" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell, CnSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import PipelinqIntegration from './PipelinqIntegration.vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		CnSettingsSection,
		NcButton,
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
		 * Board ShInstellingen's sections whose settings live on their own
		 * app page: each section names what it holds and opens that page.
		 *
		 * @return {Array<{id: string, name: string, description: string, linkLabel: string, href: string}>}
		 * @spec exclude board sections that link to existing app pages, display only
		 */
		pageSections() {
			return [
				{
					id: 'required-fields',
					name: this.t('shillinq', 'Required fields'),
					description: this.t(
						'shillinq',
						'Choose which fields must be filled in before a record is saved.',
					),

					linkLabel: this.t('shillinq', 'Manage required fields'),
					href: generateUrl('/apps/shillinq/settings/required-fields'),
				},
				{
					id: 'connections',
					name: this.t('shillinq', 'Connections'),
					description: this.t(
						'shillinq',
						'See which connections work before you rely on them.',
					),

					linkLabel: this.t('shillinq', 'Open the connections'),
					href: generateUrl('/apps/shillinq/external-adapters'),
				},
				{
					id: 'appointments',
					name: this.t('shillinq', 'Appointments'),
					description: this.t(
						'shillinq',
						'Online booking: the key the booking widget on your website uses.',
					),

					linkLabel: this.t('shillinq', 'Manage widget keys'),
					href: generateUrl('/apps/shillinq/settings/booking-widget-keys'),
				},
			]
		},

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
