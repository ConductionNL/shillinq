/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The small entry modules every shillinq bundle starts from: the shared Pinia
 * instance, the webpack runtime bootstrap that points lazy chunks at the real
 * webroot, and the every-page leaf bootstrap that puts the three finance
 * panels on OpenRegister's shared integration registry.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateFilePath: (app, type, file) => `/custom_apps/${app}/${file}`,
	generateUrl: (url) => '/index.php' + url,
}))

describe('pinia.js', () => {
	it('exports one Pinia instance that can be installed on an app', async () => {
		const { default: pinia } = await import('../../src/pinia.js')
		const again = (await import('../../src/pinia.js')).default

		expect(typeof pinia.install).toBe('function')
		expect(typeof pinia.use).toBe('function')
		expect(pinia.state.value).toEqual({})
		expect(again).toBe(pinia)
	})
})

describe('setPublicPath.js', () => {
	beforeEach(() => {
		vi.resetModules()
		globalThis.OC = { requestToken: 'csrf-token-1' }
		// The bundler provides these two as free variables; in a module they
		// can only be assigned once they exist.
		globalThis.__webpack_nonce__ = undefined
		globalThis.__webpack_public_path__ = undefined
	})

	afterEach(() => {
		delete globalThis.OC
		delete globalThis.__webpack_nonce__
		delete globalThis.__webpack_public_path__
	})

	it('sets the CSP nonce from the request token', async () => {
		await import('../../src/setPublicPath.js')

		expect(globalThis.__webpack_nonce__).toBe(btoa('csrf-token-1'))
	})

	it('points lazy chunks at the app js folder of the real webroot', async () => {
		await import('../../src/setPublicPath.js')

		expect(globalThis.__webpack_public_path__).toBe('/custom_apps/shillinq/js/')
	})
})

describe('integration-init.js', () => {
	let registered

	beforeEach(() => {
		vi.resetModules()
		registered = []
		globalThis.OC = { requestToken: 'csrf-token-2' }
		globalThis.__webpack_nonce__ = undefined
		globalThis.__webpack_public_path__ = undefined
		globalThis.window = {
			OCA: {
				OpenRegister: {
					integrations: { register: (descriptor) => registered.push(descriptor) },
				},
			},
		}
	})

	afterEach(() => {
		delete globalThis.window
		delete globalThis.OC
		delete globalThis.__webpack_nonce__
		delete globalThis.__webpack_public_path__
	})

	it('registers the payment requests, contract and financial documents panels, in that order', async () => {
		await import('../../src/integration-init.js')

		expect(registered.map((descriptor) => descriptor.id)).toEqual([
			'shillinq-payment-requests-panel',
			'shillinq-contracts-panel',
			'shillinq-financial-documents-panel',
		])
	})

	it('registers each panel as a mounted panel of shillinq', async () => {
		await import('../../src/integration-init.js')

		for (const descriptor of registered) {
			expect(descriptor.requiredApp).toBe('shillinq')
			expect(descriptor.renderMode).toBe('mount')
			expect(typeof descriptor.mount).toBe('function')
			expect(typeof descriptor.unmount).toBe('function')
		}
	})

	it('sets the chunk path for the panels it loads lazily', async () => {
		await import('../../src/integration-init.js')

		expect(globalThis.__webpack_public_path__).toBe('/custom_apps/shillinq/js/')
		expect(globalThis.__webpack_nonce__).toBe(btoa('csrf-token-2'))
	})

	it('queues the panels when OpenRegister has not loaded its registry yet', async () => {
		globalThis.window = {}

		await import('../../src/integration-init.js')

		const integrations = globalThis.window.OCA.OpenRegister.integrations
		expect(integrations).toBeDefined()
		expect(registered).toEqual([])
	})
})
