/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Inventory Mobile Scanner — Service Worker (REQ-SW-001, T4.1, T7.3).
 *
 * Cache strategy:
 *   - Static assets (HTML, JS, CSS, PNG, SVG, web fonts): cache-first
 *     with a versioned cache name so a deployment cycle automatically
 *     invalidates stale entries.
 *   - API calls under /apps/shillinq/api/v1/inventory/: network-first
 *     with cache fallback so the PWA continues to function offline but
 *     always sees fresh server data when the network is available.
 *   - Icons and images: cache-first with a 7-day expiry implemented via
 *     a metadata header (cached-at) inspected on each lookup.
 *
 * This file is served from /apps/shillinq/public/inventoryServiceWorker.js
 * and is registered explicitly by the MobileScannerHome page once the
 * inventory routes are available.
 */

const VERSION = 'inventory-v1'
const CACHE_STATIC = `${VERSION}-static`
const CACHE_API = `${VERSION}-api`
const CACHE_IMG = `${VERSION}-img`
const IMG_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000

const STATIC_ASSETS = [
	'/apps/shillinq/inventory/mobile',
	'/apps/shillinq/inventory/mobile/receive',
	'/apps/shillinq/inventory/mobile/transfer',
	'/apps/shillinq/inventory/mobile/pick',
	'/apps/shillinq/inventory/mobile/count',
	'/apps/shillinq/public/manifest.webmanifest',
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
						.filter((key) => !key.startsWith(VERSION))
						.map((key) => caches.delete(key)),
				),
			),
	)
	self.clients.claim()
})

const isApiRequest = (url) =>
	url.pathname.includes('/apps/shillinq/api/v1/inventory/')
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
			const stamped = new Response(body, {
				status: response.status,
				statusText: response.statusText,
				headers,
			})
			cache.put(req, stamped)
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

// Periodic background sync — fires every 30 seconds when the page is open.
self.addEventListener('message', (event) => {
	if (event.data && event.data.type === 'PING') {
		event.source.postMessage({ type: 'PONG', version: VERSION })
	}
})
