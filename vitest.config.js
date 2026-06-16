/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Vitest configuration for Shillinq frontend unit tests.
 *
 * Shillinq delegates object CRUD to OpenRegister via @conduction/nextcloud-vue
 * store factories, so the app-local OFFLINE logic worth pinning is the
 * app-administration settings Pinia store (src/store/modules/settings.js):
 * fetch envelope-unwrap, the hasOpenRegisters / isAdmin flag derivation, the
 * loading lifecycle and the save round-trip.
 *
 * The W8 External-Adapters admin UIs (src/views/external-adapters/*.vue) add a
 * second body of app-local logic worth pinning: status/summary mapping, the
 * slug→route map, the dormant/live badge derivation and the deep-link
 * adapterId fallback. Those `.vue` SFCs are compiled by @vitejs/plugin-vue2 so
 * their `methods` / `computed` can be exercised as pure functions bound to a
 * fake component instance — no DOM mount, so the environment stays `node`.
 * The heavy `@nextcloud/vue`, `@nextcloud/axios` and `@conduction/nextcloud-vue`
 * imports those SFCs pull in are aliased to lightweight stubs so a node-env
 * import never touches a browser-only barrel.
 *
 * global fetch + the `OC` global are mocked per-test; @nextcloud/router is
 * aliased to a stub. Vitest only collects tests/vitest/**; the PHPUnit suite
 * under tests/Unit is untouched.
 */

const path = require('path')
const vue = require('@vitejs/plugin-vue2').default

module.exports = {
	plugins: [vue()],
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'tests/Unit/**', 'src/**', 'node_modules/**'],
		// Inline pinia + vue-demi so vite's transform pipeline (and the
		// resolve.alias below that pins vue-demi to its Vue 2.7 entry) actually
		// applies to them. Externalised node_modules ESM is resolved by node's
		// native loader, which ignores resolve.alias and picks vue-demi's Vue 3
		// default entry — the one missing the `hasInjectionContext` export.
		server: {
			deps: {
				inline: ['pinia', 'vue-demi'],
			},
		},
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			// Pin vue-demi to its Vue 2.7 ESM entry. Pinia 2.3.x statically
			// imports `hasInjectionContext` from vue-demi; vue-demi only exports
			// it from its v2.7 build, and the install-time `vue-demi-switch`
			// postinstall that rewrites lib/index.mjs does not always run under
			// `npm ci` (or runs against the Vue 3 default), leaving the Vue 3
			// entry in place which lacks that named export. Aliasing directly to
			// the v2.7 entry makes resolution deterministic in CI and locally.
			{
				find: /^vue-demi$/,
				replacement: path.resolve(__dirname, 'node_modules/vue-demi/lib/v2.7/index.mjs'),
			},
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-router.js'),
			},
			{
				find: /^@nextcloud\/vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-vue.js'),
			},
			{
				find: /^@nextcloud\/axios$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-axios.js'),
			},
		],
	},
}
