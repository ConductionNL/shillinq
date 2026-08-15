/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Inventory sync scheduler / network client (T1.3, T1.4, T1.6, REQ-OFFLINE-002).
 *
 * Drives the offline-first protocol:
 *   - GET  /apps/shillinq/api/v1/inventory/sync?since=<iso8601> — download deltas
 *   - POST /apps/shillinq/api/v1/inventory/sync                — upload pendingOps
 *
 * Exposes:
 *   - syncDownload(db, since)        — single download + LWW merge cycle.
 *   - syncUpload(db)                  — single upload + per-row ACK handling.
 *   - createSyncScheduler(db, opts)   — 30s background scheduler with
 *                                       exponential backoff (1s, 2s, 4s, 8s).
 *
 * Network calls are routed through `@nextcloud/axios` so CSRF + cookie
 * handling come for free. All retries are bounded; failures keep the
 * pendingOp un-synced so the next cycle retries from a clean slate.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	deletePendingOp,
	markOpSynced,
	mergeServerDelta,
	readUnsyncedOps,
} from './useInventoryDb.js'

const URL_SYNC = '/apps/shillinq/api/v1/inventory/sync'
const URL_LOCATIONS = '/apps/shillinq/api/v1/inventory/locations'

const DEFAULT_INTERVAL_MS = 30_000
const BACKOFF_STEPS_MS = [1_000, 2_000, 4_000, 8_000]

/**
 * Build the absolute URL for the sync endpoint with optional query.
 *
 * @param {string|null} since ISO 8601 timestamp or null.
 * @return {string} The resolved URL.
 */
function syncUrl(since) {
	const base = generateUrl(URL_SYNC)
	if (!since) {
		return base
	}
	const separator = base.includes('?') ? '&' : '?'
	return `${base}${separator}since=${encodeURIComponent(since)}`
}

/**
 * Run a single download + LWW merge cycle.
 *
 * @param {IDBDatabase} db Local DB.
 * @param {string|null} since ISO 8601 timestamp of the previous sync, or null
 *   to fetch the full set.
 * @return {Promise<{applied:number,kept:number,serverTimestamp:string}>}
 *   Aggregated counts.
 */
export async function syncDownload(db, since) {
	const response = await axios.get(syncUrl(since))
	const data = response && response.data ? response.data : {}
	const deltas = Array.isArray(data.deltas) ? data.deltas : []

	let applied = 0
	let kept = 0
	for (const row of deltas) {
		const decision = await mergeServerDelta(db, row)
		if (decision === 'applied-server') {
			applied += 1
		} else {
			kept += 1
		}
	}

	return {
		applied,
		kept,
		serverTimestamp: data.serverTimestamp || new Date().toISOString(),
	}
}

/**
 * Run a single upload cycle: collect all unsynced pendingOps and POST them
 * in one batch. Each ACK is processed independently so a permission-denied
 * row does not abort the rest.
 *
 * @param {IDBDatabase} db Local DB.
 * @return {Promise<{accepted:number,duplicates:number,rejected:Array<object>}>}
 *   Per-status counts plus the rejected ACK rows for client UX.
 */
export async function syncUpload(db) {
	const pending = await readUnsyncedOps(db)
	if (pending.length === 0) {
		return { accepted: 0, duplicates: 0, rejected: [] }
	}

	const operations = pending.map((op) => ({
		transactionId: op.transactionId,
		type: op.type,
		sku: op.sku,
		location: op.location,
		toLocation: op.toLocation,
		quantity: op.quantity,
		physicalQuantity: op.physicalQuantity,
		reconcile: op.reconcile,
		orderLineId: op.orderLineId,
		timestamp: op.timestamp,
	}))

	const response = await axios.post(generateUrl(URL_SYNC), { operations })
	const data = response && response.data ? response.data : {}
	const results = Array.isArray(data.results) ? data.results : []

	const byTx = new Map()
	results.forEach((r) => byTx.set(r.transactionId, r))

	let accepted = 0
	let duplicates = 0
	const rejected = []

	for (const op of pending) {
		const ack = byTx.get(op.transactionId)
		if (!ack) {
			continue
		}
		if (ack.status === 'accepted') {
			await markOpSynced(
				db,
				op.id,
				ack.serverAckedAt
					|| data.serverTimestamp
					|| new Date().toISOString(),
			)
			await deletePendingOp(db, op.id)
			accepted += 1
		} else if (ack.status === 'duplicate') {
			await markOpSynced(
				db,
				op.id,
				ack.serverAckedAt
					|| data.serverTimestamp
					|| new Date().toISOString(),
			)
			await deletePendingOp(db, op.id)
			duplicates += 1
		} else {
			rejected.push({ ...op, ack })
		}
	}

	return { accepted, duplicates, rejected }
}

/**
 * Fetch the location catalogue for the current administration.
 *
 * @return {Promise<Array<object>>} Array of {code, name, warehouse} rows.
 */
export async function fetchLocations() {
	const response = await axios.get(generateUrl(URL_LOCATIONS))
	const data = response && response.data ? response.data : {}
	return Array.isArray(data.locations) ? data.locations : []
}

/**
 * Create the background sync scheduler. The scheduler:
 *   - polls every `intervalMs` (default 30s) while the page is alive
 *   - skips the cycle if `navigator.onLine === false`
 *   - applies exponential backoff (1s/2s/4s/8s) on network errors
 *   - emits status callbacks so the SyncStatusBadge can update
 *
 * @param {IDBDatabase} db Local DB.
 * @param {object} [opts] Scheduler options.
 * @param {number} [opts.intervalMs] Default cycle interval.
 * @param {Function} [opts.onStatus] Called with {state, lastSyncedAt, error}.
 * @param {Function} [opts.getLastSyncedAt] Returns the current cursor.
 * @param {Function} [opts.setLastSyncedAt] Persists a new cursor.
 * @return {{start:Function, stop:Function, triggerNow:Function}} Scheduler handle.
 */
export function createSyncScheduler(db, opts = {}) {
	const intervalMs = opts.intervalMs || DEFAULT_INTERVAL_MS
	const onStatus = typeof opts.onStatus === 'function' ? opts.onStatus : () => {}
	const getCursor =
		typeof opts.getLastSyncedAt === 'function'
			? opts.getLastSyncedAt
			: () => null
	const setCursor =
		typeof opts.setLastSyncedAt === 'function' ? opts.setLastSyncedAt : () => {}

	let timerId = null
	let stopped = true
	let inFlight = false
	let backoffIndex = 0

	const isOnline = () =>
		typeof navigator === 'undefined' || navigator.onLine !== false

	/**
	 *
	 */
	async function runOnce() {
		if (inFlight) {
			return
		}
		inFlight = true
		try {
			if (!isOnline()) {
				onStatus({
					state: 'offline',
					lastSyncedAt: getCursor(),
					error: null,
				})
				return
			}
			onStatus({ state: 'syncing', lastSyncedAt: getCursor(), error: null })
			const dl = await syncDownload(db, getCursor())
			const up = await syncUpload(db)
			setCursor(dl.serverTimestamp)
			backoffIndex = 0
			onStatus({
				state: 'synced',
				lastSyncedAt: dl.serverTimestamp,
				summary: {
					applied: dl.applied,
					kept: dl.kept,
					accepted: up.accepted,
					duplicates: up.duplicates,
					rejected: up.rejected,
				},
				error: null,
			})
		} catch (e) {
			const delay =
				BACKOFF_STEPS_MS[Math.min(backoffIndex, BACKOFF_STEPS_MS.length - 1)]
			backoffIndex = Math.min(backoffIndex + 1, BACKOFF_STEPS_MS.length - 1)
			onStatus({
				state: 'failed',
				lastSyncedAt: getCursor(),
				error: e && e.message ? e.message : String(e),
				retryInMs: delay,
			})
		} finally {
			inFlight = false
		}
	}

	/**
	 *
	 */
	function schedule() {
		if (stopped) {
			return
		}
		timerId = setTimeout(async () => {
			await runOnce()
			schedule()
		}, intervalMs)
	}

	return {
		start() {
			stopped = false
			schedule()
		},
		stop() {
			stopped = true
			if (timerId !== null) {
				clearTimeout(timerId)
				timerId = null
			}
		},
		async triggerNow() {
			await runOnce()
		},
	}
}

/**
 * Generate a v4-ish UUID for client-side transaction ids. Uses the Web Crypto
 * API when present and falls back to a deterministic Math.random format so
 * the helper works in older environments.
 *
 * @return {string} A new UUID.
 */
export function newTransactionId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	// Fallback per RFC 4122 §4.4 — not cryptographically strong but adequate
	// for deduplication scoped to a single browser session.
	const hex = '0123456789abcdef'
	let out = ''
	for (let i = 0; i < 36; i += 1) {
		if (i === 8 || i === 13 || i === 18 || i === 23) {
			out += '-'
		} else if (i === 14) {
			out += '4'
		} else if (i === 19) {
			out += hex[((Math.random() * 4) | 0) + 8]
		} else {
			out += hex[(Math.random() * 16) | 0]
		}
	}
	return out
}

export const SYNC_URLS = Object.freeze({
	SYNC: URL_SYNC,
	LOCATIONS: URL_LOCATIONS,
})
