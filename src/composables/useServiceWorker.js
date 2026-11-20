/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Service worker registration helper (T7.3).
 *
 * Registers /apps/shillinq/public/inventoryServiceWorker.js on the
 * /apps/shillinq/inventory/ scope so the warehouse PWA can be installed as
 * a standalone app and continue to serve its shell offline.
 *
 * Safe to call multiple times — the navigator-level registration is
 * idempotent. No-ops cleanly when service workers are unsupported
 * (older Safari, SSR, test runners).
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T4.1
 */

const SW_PATH = '/apps/shillinq/public/inventoryServiceWorker.js'
const SW_SCOPE = '/apps/shillinq/inventory/'

/**
 * Register the inventory mobile scanner service worker.
 *
 * @return {Promise<ServiceWorkerRegistration|null>} The registration or null.
 */
export async function registerInventoryServiceWorker() {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return null
	}
	try {
		return await navigator.serviceWorker.register(SW_PATH, { scope: SW_SCOPE })
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn('[shillinq] inventory service worker registration failed', e)
		return null
	}
}

/**
 * Unregister the service worker (used by rollback / dev tooling).
 *
 * @return {Promise<boolean>} True when unregistration succeeded.
 */
export async function unregisterInventoryServiceWorker() {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return false
	}
	const registrations = await navigator.serviceWorker.getRegistrations()
	let unregistered = false
	for (const reg of registrations) {
		if (reg.scope && reg.scope.endsWith(SW_SCOPE)) {
			unregistered = (await reg.unregister()) || unregistered
		}
	}
	return unregistered
}

export const SERVICE_WORKER_META = Object.freeze({ PATH: SW_PATH, SCOPE: SW_SCOPE })

const MOBILE_SW_PATH = '/apps/shillinq/public/mobileServiceWorker.js'
const MOBILE_SW_SCOPE = '/apps/shillinq/mobile/'

/**
 * Register the Shillinq Mobile service worker on its own scope, beside the
 * warehouse one: an entrepreneur and a warehouse employee install different
 * apps. No-ops where service workers are unsupported, so the app then works
 * online only.
 *
 * @return {Promise<ServiceWorkerRegistration|null>} The registration or null.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-shillinq-mobile-is-installable-on-a-phone-req-pmp-001
 */
export async function registerMobileServiceWorker() {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return null
	}
	try {
		return await navigator.serviceWorker.register(MOBILE_SW_PATH, { scope: MOBILE_SW_SCOPE })
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn('[shillinq] mobile service worker registration failed', e)
		return null
	}
}

/**
 * Whether this browser can run Shillinq Mobile offline: it needs both a
 * service worker for the shell and IndexedDB for the queue.
 *
 * @return {boolean} True when both are available.
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-work-done-offline-is-queued-and-synced-once-req-pmp-003
 */
export function supportsOfflineMobile() {
	return typeof navigator !== 'undefined'
		&& 'serviceWorker' in navigator
		&& typeof indexedDB !== 'undefined'
}

export const MOBILE_SERVICE_WORKER_META = Object.freeze({ PATH: MOBILE_SW_PATH, SCOPE: MOBILE_SW_SCOPE })
