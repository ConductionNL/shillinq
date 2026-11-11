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

// The pendingOp fields the upload sends, in wire order.
const UPLOAD_FIELDS = [
	'transactionId',
	'type',
	'sku',
	'location',
	'toLocation',
	'quantity',
	'physicalQuantity',
	'reconcile',
	'orderLineId',
	'timestamp',
]

// ACK status of a settled op to the tally counter it raises.
const SETTLED_COUNTERS = new Map([
	['accepted', 'accepted'],
	['duplicate', 'duplicates'],
])

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
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
export async function syncUpload(db) {
	const pending = await readUnsyncedOps(db)
	const tally = { accepted: 0, duplicates: 0, rejected: [] }
	if (pending.length === 0) {
		return tally
	}

	const data = await postOperations(pending)
	const byTx = acksByTransaction(data)
	for (const op of pending) {
		const ack = byTx.get(op.transactionId)
		if (ack) {
			await settleAck(db, {
				op,
				ack,
				tally,
				serverTimestamp: data.serverTimestamp,
			})
		}
	}

	return tally
}

/**
 * POST the upload batch and return the response body.
 *
 * @param {Array<object>} pending The unsynced pendingOps.
 * @return {Promise<object>} The response data, or an empty object.
 */
async function postOperations(pending) {
	const operations = pending.map((op) =>
		Object.fromEntries(UPLOAD_FIELDS.map((field) => [field, op[field]])),
	)
	const response = await axios.post(generateUrl(URL_SYNC), { operations })
	return response && response.data ? response.data : {}
}

/**
 * Index the upload ACK rows by transactionId (a later row wins).
 *
 * @param {object} data The upload response data.
 * @return {Map<string,object>} ACK row per transactionId.
 */
function acksByTransaction(data) {
	const results = Array.isArray(data.results) ? data.results : []
	const byTx = new Map()
	results.forEach((r) => byTx.set(r.transactionId, r))
	return byTx
}

/**
 * Apply one ACK: an accepted or duplicate op is marked synced and removed
 * from the queue, anything else is kept as rejected.
 *
 * @param {IDBDatabase} db Local DB.
 * @param {{op:object,ack:object,tally:object,serverTimestamp:string|undefined}} step
 *   The op, its ACK, the running counts and the batch's server time.
 * @return {Promise<void>}
 */
async function settleAck(db, step) {
	const { op, ack, tally } = step
	const counter = SETTLED_COUNTERS.get(ack.status)
	if (!counter) {
		tally.rejected.push({ ...op, ack })
		return
	}
	await markOpSynced(
		db,
		op.id,
		ack.serverAckedAt || step.serverTimestamp || new Date().toISOString(),
	)
	await deletePendingOp(db, op.id)
	tally[counter] += 1
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
 * The summary a synced cycle reports.
 *
 * @param {{applied: number, kept: number}} dl The download tally.
 * @param {{accepted: number, duplicates: number, rejected: Array}} up The upload tally.
 * @return {object} applied, kept, accepted, duplicates, rejected.
 */
function syncSummary(dl, up) {
	return {
		applied: dl.applied,
		kept: dl.kept,
		accepted: up.accepted,
		duplicates: up.duplicates,
		rejected: up.rejected,
	}
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
	 * Report the device offline; nothing is synced.
	 */
	function reportOffline() {
		onStatus({
			state: 'offline',
			lastSyncedAt: getCursor(),
			error: null,
		})
	}

	/**
	 * Download, then upload, keep the server cursor and report the summary.
	 */
	async function syncCycle() {
		onStatus({ state: 'syncing', lastSyncedAt: getCursor(), error: null })
		const dl = await syncDownload(db, getCursor())
		const up = await syncUpload(db)
		setCursor(dl.serverTimestamp)
		backoffIndex = 0
		onStatus({
			state: 'synced',
			lastSyncedAt: dl.serverTimestamp,
			summary: syncSummary(dl, up),
			error: null,
		})
	}

	/**
	 * Report a failed cycle with the next backoff delay, and step the backoff.
	 *
	 * @param {*} e The failure.
	 */
	function reportFailure(e) {
		const delay =
			BACKOFF_STEPS_MS[Math.min(backoffIndex, BACKOFF_STEPS_MS.length - 1)]
		backoffIndex = Math.min(backoffIndex + 1, BACKOFF_STEPS_MS.length - 1)
		onStatus({
			state: 'failed',
			lastSyncedAt: getCursor(),
			error: e && e.message ? e.message : String(e),
			retryInMs: delay,
		})
	}

	/**
	 * Run one sync cycle unless one is already in flight.
	 */
	async function runOnce() {
		if (inFlight) {
			return
		}
		inFlight = true
		try {
			if (!isOnline()) {
				reportOffline()
				return
			}
			await syncCycle()
		} catch (e) {
			reportFailure(e)
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
