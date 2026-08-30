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
		statusLabel: (state) => {
			if (state.syncState === STATE_SYNCED) {
				return state.lastSyncedAt ? `Synced ${state.lastSyncedAt}` : 'Synced'
			}
			if (state.syncState === STATE_SYNCING) {
				return 'Syncing…'
			}
			if (state.syncState === STATE_PENDING) {
				return state.pendingCount > 0
					? `Pending (${state.pendingCount})`
					: 'Pending'
			}
			if (state.syncState === STATE_FAILED) {
				return `Sync failed; retry in ${Math.ceil((state.retryInMs || 0) / 1000)}s`
			}
			return 'Offline'
		},
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
		 */
		async applyStatus(status) {
			this.syncState = status.state || STATE_PENDING
			if (status.lastSyncedAt) {
				this.lastSyncedAt = status.lastSyncedAt
			}
			this.lastError = status.error || null
			this.retryInMs = status.retryInMs || 0
			if (status.summary && Array.isArray(status.summary.rejected)) {
				this.rejected = status.summary.rejected
			}
			if (status.summary && (status.summary.applied || status.summary.kept)) {
				// LWW overwrites are surfaced as non-blocking conflict toasts.
				if (status.summary.applied > 0) {
					this.conflicts.push({
						at: status.lastSyncedAt || new Date().toISOString(),
						applied: status.summary.applied,
						kept: status.summary.kept,
					})
				}
			}
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
		 */
		async submitOperation(op) {
			if (!this.ready || !this.db) {
				throw new Error('Inventory scanner store not ready')
			}
			const transactionId = newTransactionId()
			const timestamp = new Date().toISOString()
			const id = `op-${transactionId}`

			let localQuantity = null
			if (op.type === 'receive') {
				localQuantity = await applyDelta(
					this.db,
					op.sku,
					op.location,
					Number(op.quantity),
					timestamp,
				)
			} else if (op.type === 'pick') {
				localQuantity = await applyDelta(
					this.db,
					op.sku,
					op.location,
					-Math.abs(Number(op.quantity)),
					timestamp,
				)
			} else if (op.type === 'transfer') {
				await applyDelta(
					this.db,
					op.sku,
					op.location,
					-Math.abs(Number(op.quantity)),
					timestamp,
				)
				localQuantity = await applyDelta(
					this.db,
					op.sku,
					op.toLocation,
					Math.abs(Number(op.quantity)),
					timestamp,
				)
			} else if (op.type === 'count') {
				const systemQty = await readStockQuantity(
					this.db,
					op.sku,
					op.location,
				)
				if (op.reconcile === true) {
					localQuantity = await applyDelta(
						this.db,
						op.sku,
						op.location,
						Number(op.physicalQuantity) - systemQty,
						timestamp,
					)
				} else {
					localQuantity = systemQty
				}
			}

			await insertPendingOp(this.db, {
				id,
				transactionId,
				type: op.type,
				sku: op.sku,
				location: op.location,
				toLocation: op.toLocation,
				orderLineId: op.orderLineId,
				quantity: op.quantity != null ? Number(op.quantity) : null,
				physicalQuantity:
					op.physicalQuantity != null ? Number(op.physicalQuantity) : null,
				reconcile: op.reconcile === true,
				timestamp,
				synced: false,
			})

			await this.refreshPendingCount()

			if (this.scheduler && typeof this.scheduler.triggerNow === 'function') {
				this.scheduler.triggerNow().catch(() => {
					// Failures are surfaced via applyStatus().
				})
			}

			return { transactionId, localQuantity }
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
