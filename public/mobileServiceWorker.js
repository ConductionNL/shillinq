/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Shillinq Mobile — Service Worker (REQ-PMP-001).
 *
 * The same cache rules as inventoryServiceWorker.js, on the mobile scope:
 *   - The app shell (the three mobile pages and the manifest): cache-first
 *     under a versioned cache name, so a new deployment drops stale entries.
 *   - Object reads under /apps/openregister/api/objects/: network-first with
 *     a cache fallback, so the drafts list still shows offline.
 *   - Images: cache-first with a 7-day expiry stamped on each entry.
 *
 * Writes are never cached or replayed here: work made offline waits in the
 * page's IndexedDB queue (src/composables/useOfflineQueue.js) and is sent by
 * the page when the connection returns.
 *
 * Served from /apps/shillinq/public/mobileServiceWorker.js and registered by
 * the MobileHome page through registerMobileServiceWorker().
 *
 * @spec openspec/changes/platform-mobile-pwa/specs/shillinq-mobile/spec.md#requirement-shillinq-mobile-is-installable-on-a-phone-req-pmp-001
 */

const VERSION = 'mobile-v1'
const CACHE_STATIC = `${VERSION}-static`
const CACHE_API = `${VERSION}-api`
const CACHE_IMG = `${VERSION}-img`
const IMG_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000

const STATIC_ASSETS = [
	'/apps/shillinq/mobile',
	'/apps/shillinq/mobile/receipt',
	'/apps/shillinq/mobile/invoice',
	'/apps/shillinq/public/mobile.webmanifest',
]

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches
			.open(CACHE_STATIC)
			.then((cache) => cache.addAll(STATIC_ASSETS).catch(() => null)),
	)
	self.skipWaiting()
})

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((keys) =>
				Promise.all(
					keys
						.filter((key) => key.startsWith('mobile-') && !key.startsWith(VERSION))
						.map((key) => caches.delete(key)),
				),
			),
	)
	self.clients.claim()
})

const isApiRequest = (url) =>
	url.pathname.includes('/apps/openregister/api/objects/')
const isImageRequest = (req) =>
	req.destination === 'image' || /\.(png|jpg|jpeg|svg|webp)$/i.test(req.url)

self.addEventListener('fetch', (event) => {
	const req = event.request
	if (req.method !== 'GET') {
		return
	}
	const url = new URL(req.url)

	if (isApiRequest(url)) {
		event.respondWith(networkFirst(req))
		return
	}

	if (isImageRequest(req)) {
		event.respondWith(cacheFirstWithExpiry(req, CACHE_IMG, IMG_MAX_AGE_MS))
		return
	}

	event.respondWith(cacheFirst(req, CACHE_STATIC))
})

async function cacheFirst(req, cacheName) {
	const cache = await caches.open(cacheName)
	const cached = await cache.match(req)
	if (cached) {
		return cached
	}
	try {
		const response = await fetch(req)
		if (response && response.ok) {
			cache.put(req, response.clone())
		}
		return response
	} catch (e) {
		return cached || Response.error()
	}
}

async function cacheFirstWithExpiry(req, cacheName, maxAgeMs) {
	const cache = await caches.open(cacheName)
	const cached = await cache.match(req)
	if (cached) {
		const stored = cached.headers.get('x-sw-cached-at')
		const age = stored ? Date.now() - Number(stored) : Infinity
		if (Number.isFinite(age) && age < maxAgeMs) {
			return cached
		}
	}
	try {
		const response = await fetch(req)
		if (response && response.ok) {
			const headers = new Headers(response.headers)
			headers.set('x-sw-cached-at', String(Date.now()))
			const body = await response.clone().blob()
			cache.put(req, new Response(body, { status: response.status, statusText: response.statusText, headers }))
		}
		return response
	} catch (e) {
		return cached || Response.error()
	}
}

async function networkFirst(req) {
	const cache = await caches.open(CACHE_API)
	try {
		const response = await fetch(req)
		if (response && response.ok) {
			cache.put(req, response.clone())
		}
		return response
	} catch (e) {
		const cached = await cache.match(req)
		if (cached) {
			return cached
		}
		return new Response(JSON.stringify({ message: 'offline' }), {
			status: 503,
			headers: { 'Content-Type': 'application/json' },
		})
	}
}
