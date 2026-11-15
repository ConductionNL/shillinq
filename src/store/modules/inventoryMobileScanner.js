/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Inventory Mobile Scanner Pinia store (T3.6, T4.6, T5.4).
 *
 * Single source of truth for the warehouse PWA's UI state:
 *   - sync status (offline / pending / syncing / synced / failed) per REQ-UI-001
 *   - last-synced timestamp (REQ-UI-001)
 *   - rejected pendingOps (per-row reasons for the T5.2 permission UX)
 *   - last conflict notification (REQ-UI-002 — non-blocking LWW overwrite)
 *   - location catalogue
 *
 * Operations (receive / transfer / pick / count) are dispatched through
 * `submitOperation()` which writes the local IndexedDB row + pendingOp
 * (optimistic update) and triggers the scheduler. The view layer never
 * touches IndexedDB directly so the optimistic / sync semantics stay
 * deterministic.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { defineStore } from 'pinia'
import {
	applyDelta,
	initDB,
	insertPendingOp,
	purgeOldPendingOps,
	readStockQuantity,
	readUnsyncedOps,
	replaceLocations,
} from '../../composables/useInventoryDb.js'
import {
	createSyncScheduler,
	fetchLocations,
	newTransactionId,
} from '../../composables/useInventorySync.js'

const PURGE_WINDOW_MS = 7 * 24 * 60 * 60 * 1000

const STATE_OFFLINE = 'offline'
const STATE_PENDING = 'pending'
const STATE_SYNCING = 'syncing'
const STATE_SYNCED = 'synced'
const STATE_FAILED = 'failed'

/**
 * Apply one signed stock change for an operation.
 *
 * @param {object} run The db, op and timestamp of the submit.
 * @param {object} change The location and the signed delta.
 * @return {Promise<number>} The new local quantity.
 */
function applyChange(run, change) {
	return applyDelta(
		run.db,
		run.op.sku,
		change.location,
		change.delta,
		run.timestamp,
	)
}

// The optimistic local stock mutation per operation type. Each takes the
// submit's { db, op, timestamp } and resolves to the new local quantity.
const LOCAL_MUTATIONS = new Map([
	[
		'receive',
		(run) =>
			applyChange(run, {
				location: run.op.location,
				delta: Number(run.op.quantity),
			}),
	],
	[
		'pick',
		(run) =>
			applyChange(run, {
				location: run.op.location,
				delta: -Math.abs(Number(run.op.quantity)),
			}),
	],
	['transfer', (run) => applyTransfer(run)],
	['count', (run) => applyCount(run)],
])

/**
 * Move the quantity out of the source and into the destination location.
 *
 * @param {object} run The db, op and timestamp of the submit.
 * @return {Promise<number>} The new quantity at the destination.
 */
async function applyTransfer(run) {
	await applyChange(run, {
		location: run.op.location,
		delta: -Math.abs(Number(run.op.quantity)),
	})
	return applyChange(run, {
		location: run.op.toLocation,
		delta: Math.abs(Number(run.op.quantity)),
	})
}

/**
 * Read the counted location; when reconciling, adjust it to the physical
 * quantity.
 *
 * @param {object} run The db, op and timestamp of the submit.
 * @return {Promise<number>} The reconciled or the system quantity.
 */
async function applyCount(run) {
	const systemQty = await readStockQuantity(run.db, run.op.sku, run.op.location)
	if (run.op.reconcile !== true) {
		return systemQty
	}
	return applyChange(run, {
		location: run.op.location,
		delta: Number(run.op.physicalQuantity) - systemQty,
	})
}

/**
 * The number in a field, or null when the field is null or undefined.
 *
 * @param {number|string|null|undefined} value The raw field.
 * @return {number|null} The number, or null.
 */
function numberOrNull(value) {
	return value != null ? Number(value) : null
}

/**
 * The pendingOps row for a submitted operation.
 *
 * @param {object} run The op, transactionId and timestamp of the submit.
 * @return {object} The row to insert.
 */
function pendingOpRow(run) {
	const { op } = run
	return {
		id: `op-${run.transactionId}`,
		transactionId: run.transactionId,
		type: op.type,
		sku: op.sku,
		location: op.location,
		toLocation: op.toLocation,
		orderLineId: op.orderLineId,
		quantity: numberOrNull(op.quantity),
		physicalQuantity: numberOrNull(op.physicalQuantity),
		reconcile: op.reconcile === true,
		timestamp: run.timestamp,
		synced: false,
	}
}

/**
 * Start a sync cycle without waiting for it, when the scheduler can.
 *
 * @param {object|null} scheduler The store's sync scheduler.
 */
function triggerSyncInBackground(scheduler) {
	if (scheduler && typeof scheduler.triggerNow === 'function') {
		scheduler.triggerNow().catch(() => {
			// Failures are surfaced via applyStatus().
		})
	}
}

/**
 * Take the rejected operations of a sync summary, and surface its LWW
 * overwrites as a non-blocking conflict toast.
 *
 * @param {object} store The scanner store.
 * @param {object} status Status payload from createSyncScheduler.
 */
function applySummary(store, status) {
	const summary = status.summary
	if (!summary) {
		return
	}
	if (Array.isArray(summary.rejected)) {
		store.rejected = summary.rejected
	}
	if (summary.applied > 0) {
		store.conflicts.push({
			at: status.lastSyncedAt || new Date().toISOString(),
			applied: summary.applied,
			kept: summary.kept,
		})
	}
}

/** The badge text of each sync state that is not offline. */
const STATUS_LABELS = {
	[STATE_SYNCED]: (state) =>
		state.lastSyncedAt ? `Synced ${state.lastSyncedAt}` : 'Synced',
	[STATE_SYNCING]: () => 'Syncing…',
	[STATE_PENDING]: (state) =>
		state.pendingCount > 0 ? `Pending (${state.pendingCount})` : 'Pending',
	[STATE_FAILED]: (state) =>
		`Sync failed; retry in ${Math.ceil((state.retryInMs || 0) / 1000)}s`,
}

export const useInventoryMobileScannerStore = defineStore('inventoryMobileScanner', {
	state: () => ({
		db: null,
		syncState: STATE_OFFLINE,
		lastSyncedAt: null,
		lastError: null,
		retryInMs: 0,
		pendingCount: 0,
		rejected: [],
		conflicts: [],
		locations: [],
		scheduler: null,
		initializing: false,
		ready: false,
	}),

	getters: {
		isOnline: () =>
			typeof navigator === 'undefined' || navigator.onLine !== false,
		statusLabel: (state) =>
			Object.hasOwn(STATUS_LABELS, state.syncState)
				? STATUS_LABELS[state.syncState](state)
				: 'Offline',

		statusColor: (state) => {
			if (state.syncState === STATE_SYNCED) {
				return 'green'
			}
			if (state.syncState === STATE_OFFLINE) {
				return 'red'
			}
			return 'yellow'
		},
	},

	actions: {
		/**
		 * Initialise IndexedDB + start the sync scheduler. Idempotent — safe
		 * to call multiple times; the second call is a no-op.
		 *
		 * @return {Promise<void>} Resolves once the store is ready.
		 */
		async bootstrap() {
			if (this.ready || this.initializing) {
				return
			}
			this.initializing = true
			try {
				this.db = await initDB()
				try {
					const locations = await fetchLocations()
					await replaceLocations(this.db, locations)
					this.locations = locations
				} catch (e) {
					this.lastError = e && e.message ? e.message : String(e)
				}
				this.scheduler = createSyncScheduler(this.db, {
					onStatus: (status) => this.applyStatus(status),
					getLastSyncedAt: () => this.lastSyncedAt,
					setLastSyncedAt: (ts) => {
						this.lastSyncedAt = ts
					},
				})
				this.scheduler.start()
				this.ready = true
				await this.refreshPendingCount()
			} finally {
				this.initializing = false
			}
		},

		/**
		 * Tear the scheduler down. Used by the view's beforeUnmount hook.
		 *
		 * @return {void}
		 */
		teardown() {
			if (this.scheduler && typeof this.scheduler.stop === 'function') {
				this.scheduler.stop()
			}
			this.scheduler = null
			this.ready = false
		},

		/**
		 * Apply a sync-status update from the scheduler callback.
		 *
		 * @param {object} status Status payload from createSyncScheduler.
		 * @return {Promise<void>} Resolves once derived state is refreshed.
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async applyStatus(status) {
			this.syncState = status.state || STATE_PENDING
			if (status.lastSyncedAt) {
				this.lastSyncedAt = status.lastSyncedAt
			}
			this.lastError = status.error || null
			this.retryInMs = status.retryInMs || 0
			applySummary(this, status)
			await this.refreshPendingCount()
		},

		/**
		 * Recount pendingOps from IndexedDB and update the badge.
		 *
		 * @return {Promise<void>} Resolves once the count is refreshed.
		 */
		async refreshPendingCount() {
			if (!this.db) {
				this.pendingCount = 0
				return
			}
			const pending = await readUnsyncedOps(this.db)
			this.pendingCount = pending.length
			if (this.syncState === STATE_SYNCED && pending.length > 0) {
				this.syncState = STATE_PENDING
			}
		},

		/**
		 * Submit a warehouse operation. Performs the optimistic local
		 * mutation, queues the pendingOp, then triggers an immediate sync
		 * cycle so the operator sees the ACK as quickly as possible.
		 *
		 * @param {object} op Operation payload.
		 * @return {Promise<{transactionId:string, localQuantity:number|null}>}
		 *   Outcome metadata.
		 *
		 * @spec openspec/specs/inventory-mobile-scanner/spec.md
		 */
		async submitOperation(op) {
			if (!this.ready || !this.db) {
				throw new Error('Inventory scanner store not ready')
			}
			const run = {
				db: this.db,
				op,
				transactionId: newTransactionId(),
				timestamp: new Date().toISOString(),
			}
			const mutate = LOCAL_MUTATIONS.get(op.type)
			const localQuantity = mutate ? await mutate(run) : null

			await insertPendingOp(this.db, pendingOpRow(run))
			await this.refreshPendingCount()
			triggerSyncInBackground(this.scheduler)

			return { transactionId: run.transactionId, localQuantity }
		},

		/**
		 * Trigger an immediate sync cycle from the manual "Sync now" button.
		 *
		 * @return {Promise<void>} Resolves once the cycle completes.
		 */
		async triggerSyncNow() {
			if (!this.scheduler || typeof this.scheduler.triggerNow !== 'function') {
				return
			}
			await this.scheduler.triggerNow()
		},

		/**
		 * Dismiss a conflict toast.
		 *
		 * @param {number} index Index in `conflicts`.
		 * @return {void}
		 */
		dismissConflict(index) {
			this.conflicts.splice(index, 1)
		},

		/**
		 * Purge synced pendingOps older than the configured window
		 * (REQ-DATA-002 quota management).
		 *
		 * @return {Promise<number>} Number of rows cleared.
		 */
		async purgeOld() {
			if (!this.db) {
				return 0
			}
			return purgeOldPendingOps(this.db, PURGE_WINDOW_MS)
		},
	},
})

export const SYNC_STATES = Object.freeze({
	OFFLINE: STATE_OFFLINE,
	PENDING: STATE_PENDING,
	SYNCING: STATE_SYNCING,
	SYNCED: STATE_SYNCED,
	FAILED: STATE_FAILED,
})
