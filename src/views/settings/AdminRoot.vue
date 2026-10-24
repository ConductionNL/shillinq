<template>
	<CnAdminSettingsShell
		appId="shillinq"
		appName="Shillinq"
		look="board"
		versionCardPosition="end"
		title="Shillinq"
		:tabs="tabs"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="
			t('shillinq', 'only for administrators, each section saves on its own')
		"
		docUrl="https://shillinq.conduction.nl/docs/intro"
		@tabChange="activeTab = $event"
		@reimported="onReimported">
		<CnSettingsFieldsSection
			appId="shillinq"
			:name="t('shillinq', 'Configuration')"
			:description="t('shillinq', 'The register Shillinq keeps its data in, and where a payment sends a person back to.')"
			:url="settingsUrl"
			:fields="configurationFields"
			data-testid="admin-section-configuration" />

		<PipelinqIntegration v-if="storesReady" />

		<template
			v-for="section in pageSections"
			:key="section.id"
			#[`tab-${section.id}`]>
			<CnSettingsSection
				wide
				:name="section.name"
				:description="section.description"
				:data-testid="`admin-section-${section.id}`">
				<CnToggleTableWidget
					v-if="section.widget"
					:content="section.widget.content" />
				<NcButton :href="section.href">
					{{ section.linkLabel }}
				</NcButton>
			</CnSettingsSection>
		</template>
	</CnAdminSettingsShell>
</template>

<script>
import {
	CnAdminSettingsShell,
	CnSettingsFieldsSection,
	CnSettingsSection,
	CnToggleTableWidget,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import PipelinqIntegration from './PipelinqIntegration.vue'
import requiredFieldsFragment from '../../manifest.d/platform-required-fields.json'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		CnSettingsSection,
		CnToggleTableWidget,
		CnSettingsFieldsSection,
		NcButton,
		PipelinqIntegration,
	},

	/**
	 * Library widgets on this page translate their manifest labels through
	 * the app's catalogue.
	 *
	 * @return {{cnTranslate: Function}}
	 * @spec openspec/changes/required-field-switch/specs/app-administration/spec.md
	 */
	provide() {
		return {
			cnTranslate: (text) => this.t('shillinq', text),
		}
	},

	data() {
		return {
			storesReady: false,
			activeTab:
				new URLSearchParams(window.location.search).get('tab') || 'general',

			requiredFieldCount: null,
		}
	},

	computed: {
		/**
		 * Where the app's own settings are read and saved.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
		 */
		settingsUrl() {
			return generateUrl('/apps/shillinq/api/settings')
		},

		/**
		 * The app's own settings: the register (chosen from the registers
		 * OpenRegister has, not typed) and the portal return address.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
		 * @spec openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md (REQ-SPPI-010)
		 */
		configurationFields() {
			return [
				{
					key: 'register',
					label: 'Register',
					type: 'select',
					optionsUrl: generateUrl('/apps/openregister/api/registers'),
					optionsPath: 'results',
					optionLabel: 'title',
					optionValue: 'slug',
					required: true,
				},
				{
					key: 'portal_payment_redirect_url',
					label: 'Portal return address',
					type: 'text',
					pattern: '^https://.+',
					patternMessage: 'Use an address that starts with https://',
					hint: 'Where the checkout sends a person back after paying. Use the address of your portal, starting with https://. Leave it empty to send them to Nextcloud.',
				},
			]
		},

		/**
		 * Board ShInstellingen's sections whose settings live on their own
		 * app page: each is a tab that names what it holds and opens that page.
		 * A section whose manifest fragment declares an `adminTab` widget draws
		 * that widget above the link.
		 *
		 * @return {Array<{id: string, name: string, description: string, linkLabel: string, href: string, widget?: object}>}
		 * @spec openspec/changes/required-field-switch/specs/app-administration/spec.md
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
					widget: requiredFieldsFragment.adminTab.widget,
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
		 * Board ShInstellingen's tab strip: General holds the app's own
		 * settings and the version card, each other tab one section.
		 *
		 * @return {Array<{id: string, label: string, count?: number}>}
		 * @spec exclude board tab strip, display only
		 */
		tabs() {
			return [
				{ id: 'general', label: this.t('shillinq', 'General') },
				...this.pageSections.map((section) => ({
					id: section.id,
					label: section.name,
					...(section.id === 'required-fields'
					&& this.requiredFieldCount !== null
						? { count: this.requiredFieldCount }
						: {}),
				})),
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
				{
					label: (
						this.tabs.find((tab) => tab.id === this.activeTab)
						|| this.tabs[0]
					).label,
				},
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
		this.loadRequiredFieldCount()
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/**
		 * Count the FieldRequirement records for the Required fields tab. A
		 * failed read leaves the tab without a count.
		 *
		 * @spec exclude board tab count, display only
		 */
		async loadRequiredFieldCount() {
			try {
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/shillinq/FieldRequirement',
					),
					{ params: { _limit: 1 } },
				)
				const total = Number(response?.data?.total)
				this.requiredFieldCount = Number.isFinite(total) ? total : null
			} catch {
				this.requiredFieldCount = null
			}
		},

		/** Reload register-backed config after a successful re-import. */
		onReimported() {
			initializeStores()
		},
	},
}
</script>
