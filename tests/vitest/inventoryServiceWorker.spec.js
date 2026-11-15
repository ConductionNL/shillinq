/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Registering and unregistering the inventory service worker: the path and
 * scope used, a browser without service workers, a failed registration, and
 * unregistering only the inventory scope.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	registerInventoryServiceWorker,
	SERVICE_WORKER_META,
	unregisterInventoryServiceWorker,
} from '../../src/composables/useServiceWorker.js'

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('registerInventoryServiceWorker', () => {
	it('registers the worker under the inventory scope', async () => {
		const register = vi.fn(async () => 'registration')
		vi.stubGlobal('navigator', { serviceWorker: { register } })

		expect(await registerInventoryServiceWorker()).toBe('registration')
		expect(register).toHaveBeenCalledWith(
			'/apps/shillinq/public/inventoryServiceWorker.js',
			{ scope: '/apps/shillinq/inventory/' },
		)
	})

	it('is null without service worker support', async () => {
		vi.stubGlobal('navigator', {})
		expect(await registerInventoryServiceWorker()).toBeNull()
	})

	it('is null and warns when registration fails', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.stubGlobal('navigator', { serviceWorker: { register: async () => { throw new Error('denied') } } })

		expect(await registerInventoryServiceWorker()).toBeNull()
		expect(warn).toHaveBeenCalledOnce()
	})
})

describe('unregisterInventoryServiceWorker', () => {
	it('is false without service worker support', async () => {
		vi.stubGlobal('navigator', {})
		expect(await unregisterInventoryServiceWorker()).toBe(false)
	})

	it('unregisters only the inventory scope', async () => {
		const inventory = { scope: 'https://cloud.example/apps/shillinq/inventory/', unregister: vi.fn(async () => true) }
		const other = { scope: 'https://cloud.example/apps/other/', unregister: vi.fn(async () => true) }
		vi.stubGlobal('navigator', { serviceWorker: { getRegistrations: async () => [other, inventory] } })

		expect(await unregisterInventoryServiceWorker()).toBe(true)
		expect(inventory.unregister).toHaveBeenCalledOnce()
		expect(other.unregister).not.toHaveBeenCalled()
	})

	it('is false when nothing matching was unregistered', async () => {
		const inventory = { scope: '/apps/shillinq/inventory/', unregister: async () => false }
		vi.stubGlobal('navigator', { serviceWorker: { getRegistrations: async () => [inventory, {}] } })

		expect(await unregisterInventoryServiceWorker()).toBe(false)
	})
})

describe('SERVICE_WORKER_META', () => {
	it('exposes the path and scope', () => {
		expect(SERVICE_WORKER_META).toEqual({
			PATH: '/apps/shillinq/public/inventoryServiceWorker.js',
			SCOPE: '/apps/shillinq/inventory/',
		})
	})
})
