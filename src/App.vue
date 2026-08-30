<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Shillinq app shell. Mounts CnAppRoot with the bundled manifest and the
 v2 kind-tagged registry (ADR-036). CnAppRoot handles the dependency check
 (manifest.dependencies → "OpenRegister required" empty state), the default
 CnAppNav (manifest.menu) and per-route page dispatch
 (manifest.pages[].type → Cn{Dashboard,Settings,Index,Detail,Logs}Page).

 @spec openspec/changes/shillinq-manifest-tier4/tasks.md#2.2
-->
<template>
	<CnAppRoot
		:aiCompanion="true"
		:manifest="manifest"
		:pageTypes="pageTypes"
		:customComponents="customComponents"
		:registry="registry"
		appId="shillinq"
		:translate="translateForApp"
		:permissions="permissions">
		<!--
		 Per-object integration sidebar (Files / Contacts / Audit trail / …).
		 CnDetailPage publishes the active object's coordinates into
		 `objectSidebarState` when a detail page enables `config.sidebar`; this
		 slot renders the tabs. Mirrors the decidesk / procest shell pattern.
		-->
		<template #sidebar="{ pageSidebarComponent }">
			<CnObjectSidebar
				v-if="objectSidebarState.active"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:objectType="objectSidebarState.objectType"
				:objectId="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:hiddenTabs="objectSidebarState.hiddenTabs"
				:tabs="objectSidebarState.tabs"
				:useRegistry="objectSidebarState.useRegistry"
				:excludeIntegrations="objectSidebarState.excludeIntegrations"
				:requestedTab="objectSidebarState.requestedTab"
				:registry="registry"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
			<!-- The manifest page's own sidebar (pages[].sidebarComponent). Passed in
			     as a slot prop because filling this slot suppresses CnAppRoot's
			     fallback, which is what hid the flow sidebar. -->
			<component :is="pageSidebarComponent" v-if="pageSidebarComponent" />
		</template>
	</CnAppRoot>
</template>

<script>
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { reactive } from 'vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
	},

	/**
	 * Provide the shared objectSidebarState to descendant pages.
	 *
	 * @spec exclude Shell glue — CnAppRoot provide/inject channel for the
	 * sidebar; no app-specific behavior.
	 */
	provide() {
		return {
			// Provide/inject channel for index/detail pages that auto-mount
			// sidebar content (matches the decidesk/procest pattern). Empty
			// while shillinq has no domain pages yet, kept so the first
			// schema-backed page works without touching the shell.
			objectSidebarState: this.objectSidebarState,
		}
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},

		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances.
		 */
		pageTypes: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * V2 kind-tagged component registry (ADR-036). Each entry is
		 * `{ kind: "page" | "widget" | "sidebarTab" | "modal" | "settingsSection",
		 *   component }`. CnPageRenderer resolves every `type:"custom"` page's
		 * `component` string against `kind: "page"` entries here. Replaces the
		 * deprecated `customComponents` prop.
		 * Empty for shillinq — see src/registry.js.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Flat `{ name: component }` map of every registry component (pages,
		 * widgets, …), derived from the kind-tagged `registry` in main.js. The
		 * published `@conduction/nextcloud-vue` beta resolves a page's
		 * `component` / `headerComponent` / `actionsComponent` and a custom
		 * dashboard widget against this `customComponents` prop (not the
		 * kind-tagged `registry`), so without it every custom page renders an
		 * empty `cn-page` shell and custom action/widget components disappear.
		 * Mirrors the procest / docudesk / opencatalogi wiring. See main.js.
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			objectSidebarState: reactive({
				active: false,
				open: true,
				schema: null,
				visibleColumns: null,
				searchValue: '',
				activeFilters: {},
				facetData: {},
				onSearch: null,
				onColumnsChange: null,
				onFilterChange: null,
			}),
		}
	},

	computed: {
		/**
		 * Current user's Nextcloud permissions, passed through to CnAppRoot.
		 *
		 * @return {Array} Permission list (empty when unavailable).
		 * @spec exclude Shell glue — reads window.OC permissions for CnAppRoot;
		 * no app-specific behavior.
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

	/**
	 * Bring up the Pinia stores for the admin-settings store / future custom
	 * components before CnAppRoot dispatches pages.
	 *
	 * @spec exclude Shell glue — bootstraps stores for CnAppRoot; the store
	 * behaviors are specced in REQ-Admin-001 / REQ-Admin-005.
	 */
	async created() {
		// Pinia stores still need to come up so the admin-settings store
		// (AdminRoot.vue) and any future custom components keep working.
		// CnAppRoot itself doesn't depend on them.
		await initializeStores()
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import so the
		 * library never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec exclude Shell glue — closes over @nextcloud/l10n translate so
		 * CnAppRoot need not know the app id; no app-specific behavior.
		 */
		translateForApp(key) {
			return ncT('shillinq', key)
		},
	},
}
</script>
