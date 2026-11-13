/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Registering the Shillinq Mobile service worker on its own scope, a browser
 * without service workers or IndexedDB, and a failed registration; and the
 * web app manifest the worker's shell serves.
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-shillinq-mobile-is-installable-on-a-phone-req-pmp-001
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	MOBILE_SERVICE_WORKER_META,
	registerMobileServiceWorker,
	SERVICE_WORKER_META,
	supportsOfflineMobile,
} from '../../src/composables/useServiceWorker.js'

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('registerMobileServiceWorker', () => {
	it('registers the mobile worker under the mobile scope, apart from the inventory one', async () => {
		const register = vi.fn(async () => 'registration')
		vi.stubGlobal('navigator', { serviceWorker: { register } })

		expect(await registerMobileServiceWorker()).toBe('registration')
		expect(register).toHaveBeenCalledWith('/apps/shillinq/public/mobileServiceWorker.js', { scope: '/apps/shillinq/mobile/' })
		expect(MOBILE_SERVICE_WORKER_META.SCOPE).not.toBe(SERVICE_WORKER_META.SCOPE)
	})

	it('is null without service worker support, and when registration fails', async () => {
		vi.stubGlobal('navigator', {})
		expect(await registerMobileServiceWorker()).toBeNull()

		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.stubGlobal('navigator', { serviceWorker: { register: vi.fn(async () => { throw new Error('insecure') }) } })
		expect(await registerMobileServiceWorker()).toBeNull()
		expect(warn).toHaveBeenCalledWith('[shillinq] mobile service worker registration failed', expect.any(Error))
	})
})

describe('supportsOfflineMobile', () => {
	it('needs both a service worker and IndexedDB', () => {
		vi.stubGlobal('navigator', { serviceWorker: {} })
		vi.stubGlobal('indexedDB', {})
		expect(supportsOfflineMobile()).toBe(true)

		vi.stubGlobal('indexedDB', undefined)
		expect(supportsOfflineMobile()).toBe(false)

		vi.stubGlobal('indexedDB', {})
		vi.stubGlobal('navigator', {})
		expect(supportsOfflineMobile()).toBe(false)
	})
})

describe('mobile.webmanifest', () => {
	const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../public/mobile.webmanifest'), 'utf8'))

	it('installs "Shillinq Mobile" standalone on the mobile scope', () => {
		expect(manifest.name).toBe('Shillinq Mobile')
		expect(manifest.display).toBe('standalone')
		expect(manifest.start_url).toBe('/apps/shillinq/mobile')
		expect(manifest.scope).toBe(MOBILE_SERVICE_WORKER_META.SCOPE)
		expect(manifest.icons.map((icon) => icon.sizes)).toEqual(['192x192', '512x512'])
		for (const shortcut of manifest.shortcuts) {
			expect(shortcut.url.startsWith(manifest.scope)).toBe(true)
		}
	})
})
