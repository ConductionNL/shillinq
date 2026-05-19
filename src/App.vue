<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Shillinq app shell. Mounts CnAppRoot with the bundled manifest and the
 (currently empty) customComponents registry. CnAppRoot handles the
 dependency check (manifest.dependencies → "OpenRegister required" empty
 state), the default CnAppNav (manifest.menu) and per-route page dispatch
 (manifest.pages[].type → Cn{Dashboard,Settings,Index,Detail,Logs}Page).

 @spec openspec/changes/shillinq-manifest-tier4/tasks.md#2.2
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:page-types="pageTypes"
		:registry="registry"
		app-id="shillinq"
		:translate="translateForApp"
		:permissions="permissions" />
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
	},

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
		 * Consumer-injected components used by `type: "custom"` pages and
		 * `headerComponent` / sidebar-tab / settings-section overrides.
		 * Empty for shillinq — see src/customComponents.js.
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
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
		 * 5-kind component registry for v2 manifests (hydra ADR-036).
		 * Empty for shillinq — see src/registry.js.
		 */
		registry: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			objectSidebarState: Vue.observable({
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
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

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
		 */
		translateForApp(key) {
			return ncT('shillinq', key)
		},
	},
}
</script>
