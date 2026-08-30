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
 * adapterId fallback. Those `.vue` SFCs are compiled by @vitejs/plugin-vue so
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
const vue = require('@vitejs/plugin-vue').default

module.exports = {
	plugins: [vue()],
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: [
			'tests/e2e/**',
			'tests/integration/**',
			'tests/Unit/**',
			'src/**',
			'node_modules/**',
		],
		// pinia 3 is Vue-3 native and no longer routes through `vue-demi`, so
		// the Vue-2.7 shim pin that used to live here (and its `inline` entry)
		// is gone with it — vue-demi is no longer in the dependency graph at all.
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-router.js',
				),
			},
			{
				find: /^@nextcloud\/vue$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-vue.js',
				),
			},
			{
				find: /^@nextcloud\/axios$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-axios.js',
				),
			},
			{
				find: /^@conduction\/nextcloud-vue$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/conduction-nextcloud-vue.js',
				),
			},
		],
	},
}
