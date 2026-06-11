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
 * This needs no DOM, so the environment is `node`. global fetch + the `OC`
 * global are mocked per-test; @nextcloud/router is aliased to a stub. Vitest
 * only collects tests/vitest/**; the PHPUnit suite under tests/Unit is
 * untouched.
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'tests/Unit/**', 'src/**', 'node_modules/**'],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-router.js'),
			},
		],
	},
}
