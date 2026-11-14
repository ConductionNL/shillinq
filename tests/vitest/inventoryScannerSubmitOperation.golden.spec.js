/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the inventory scanner store's `submitOperation` action.
 * The IndexedDB and sync composables are replaced by recorders, so each
 * scenario pins every local stock mutation, the queued pending operation,
 * the sync trigger, the returned outcome and any error, compared with
 * tests/vitest/fixtures/inventoryScannerSubmitOperation.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import fs from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const calls = vi.hoisted(() => ({
	log: [],
	stock: 7,
	pending: [],
	inserted: [],
	fail: null,
}))

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	applyDelta: async (db, sku, location, delta, timestamp) => {
		calls.log.push(['applyDelta', db.name, sku, location, delta, timestamp])
		if (calls.fail === 'applyDelta') {
			throw new Error('quota exceeded')
		}
		return 100 + calls.log.length
	},
	readStockQuantity: async (db, sku, location) => {
		calls.log.push(['readStockQuantity', db.name, sku, location])
		return calls.stock
	},
	insertPendingOp: async (db, op) => {
		await new Promise((resolve) => setTimeout(resolve, 0))
		calls.log.push(['insertPendingOp', db.name, op])
		if (calls.fail === 'insertPendingOp') {
			throw new Error('transaction aborted')
		}
		calls.inserted.push(op)
	},
	readUnsyncedOps: async (db) => {
		await new Promise((resolve) => setTimeout(resolve, 0))
		calls.log.push(['readUnsyncedOps', db.name])
		return [...calls.pending, ...calls.inserted]
	},
	initDB: async () => null,
	purgeOldPendingOps: async () => 0,
	replaceLocations: async () => undefined,
}))
vi.mock('../../src/composables/useInventorySync.js', () => ({
	newTransactionId: () => 'tx-1',
	createSyncScheduler: () => null,
	fetchLocations: async () => [],
}))

const { useInventoryMobileScannerStore } =
	await import('../../src/store/modules/inventoryMobileScanner.js')

const GOLDEN = path.join(
	__dirname,
	'fixtures/inventoryScannerSubmitOperation.golden.json',
)
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const SCHEDULED = 'scheduled'

const SCENARIOS = {
	'not ready': { ready: false, op: { type: 'receive', sku: 'A', quantity: 1 } },
	'no db': { db: false, op: { type: 'receive', sku: 'A', quantity: 1 } },
	receive: {
		op: { type: 'receive', sku: 'A', location: 'L1', quantity: '5' },
	},
	'receive a negative quantity': {
		op: { type: 'receive', sku: 'A', location: 'L1', quantity: -2 },
	},
	pick: {
		op: {
			type: 'pick',
			sku: 'B',
			location: 'L2',
			quantity: 3,
			orderLineId: 'OL-1',
		},
	},
	'pick a negative quantity': {
		op: { type: 'pick', sku: 'B', location: 'L2', quantity: -3 },
	},
	transfer: {
		op: {
			type: 'transfer',
			sku: 'C',
			location: 'L1',
			toLocation: 'L3',
			quantity: '-4',
		},
	},
	'count with reconcile': {
		op: {
			type: 'count',
			sku: 'D',
			location: 'L1',
			physicalQuantity: '10',
			reconcile: true,
		},
	},
	'count with a reconcile string': {
		op: {
			type: 'count',
			sku: 'D',
			location: 'L1',
			physicalQuantity: 10,
			reconcile: 'true',
		},
	},
	'count without reconcile': {
		op: { type: 'count', sku: 'D', location: 'L1', physicalQuantity: 2 },
	},
	'count with a null physical quantity': {
		op: {
			type: 'count',
			sku: 'D',
			location: 'L1',
			physicalQuantity: null,
			reconcile: true,
		},
	},
	'unknown type': { op: { type: 'adjust', sku: 'E', quantity: 1 } },
	'inherited property name as type': {
		op: { type: 'constructor', sku: 'E', quantity: 1 },
	},
	'missing quantity': { op: { type: 'receive', sku: 'F', location: 'L1' } },
	'null quantity': {
		op: { type: 'pick', sku: 'F', location: 'L1', quantity: null },
	},
	'no scheduler': {
		scheduler: 'none',
		op: { type: 'receive', sku: 'G', location: 'L1', quantity: 1 },
	},
	'scheduler without triggerNow': {
		scheduler: 'bare',
		op: { type: 'receive', sku: 'G', location: 'L1', quantity: 1 },
	},
	'triggerNow rejects': {
		scheduler: 'rejects',
		op: { type: 'receive', sku: 'G', location: 'L1', quantity: 1 },
	},
	'synced state with pending ops': {
		syncState: 'synced',
		pending: [{ id: 1 }, { id: 2 }],
		op: { type: 'receive', sku: 'H', location: 'L1', quantity: 1 },
	},
	'failed state with pending ops': {
		syncState: 'failed',
		pending: [{ id: 1 }],
		op: { type: 'receive', sku: 'H', location: 'L1', quantity: 1 },
	},
	'queue write fails': {
		fail: 'insertPendingOp',
		op: { type: 'receive', sku: 'J', location: 'L1', quantity: 1 },
	},
	'local mutation fails': {
		fail: 'applyDelta',
		op: {
			type: 'transfer',
			sku: 'I',
			location: 'L1',
			toLocation: 'L2',
			quantity: 1,
		},
	},
}

/**
 * A fake sync scheduler of the given kind.
 *
 * @param {string|undefined} kind none, bare, rejects, or a resolving one.
 * @return {object|null} The scheduler.
 */
function scheduler(kind) {
	if (kind === 'none') {
		return null
	}
	if (kind === 'bare') {
		return {}
	}
	return {
		triggerNow: () => {
			calls.log.push(['triggerNow'])
			return kind === 'rejects'
				? Promise.reject(new Error('offline'))
				: Promise.resolve(SCHEDULED)
		},
	}
}

/**
 * Make a value JSON-faithful: NaN, -0 and undefined become marker strings,
 * so the record keeps the difference between them and null or 0.
 *
 * @param {*} value Any recorded value.
 * @return {*} The same value with the markers in place.
 */
function faithful(value) {
	if (value === undefined) {
		return '(undefined)'
	}
	if (Number.isNaN(value)) {
		return '(NaN)'
	}
	if (Object.is(value, -0)) {
		return '(-0)'
	}
	if (Array.isArray(value)) {
		return value.map((item) => faithful(item))
	}
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(
			Object.entries(value).map(([key, item]) => [key, faithful(item)]),
		)
	}
	return value
}

/**
 * Run submitOperation for one scenario and return what it did.
 *
 * @param {object} scenario Store state, fakes and the operation.
 * @return {Promise<object>} The calls, outcome and store state.
 */
async function run(scenario) {
	calls.log = []
	calls.stock = 7
	calls.pending = scenario.pending ?? []
	calls.inserted = []
	calls.fail = scenario.fail ?? null
	const store = useInventoryMobileScannerStore()
	store.ready = scenario.ready ?? true
	store.db = scenario.db === false ? null : { name: 'db' }
	store.scheduler = scheduler(scenario.scheduler)
	store.syncState = scenario.syncState ?? 'pending'
	let returned = null
	let thrown = null
	try {
		returned = await store.submitOperation(scenario.op)
	} catch (e) {
		thrown = e.message
	}
	await new Promise((resolve) => setTimeout(resolve, 0))
	return faithful({
		calls: calls.log,
		returned,
		thrown,
		pendingCount: store.pendingCount,
		syncState: store.syncState,
	})
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('inventory scanner submitOperation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-11-01T12:00:00.000Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it.each(Object.keys(SCENARIOS))('%s', async (title) => {
		const result = await run(SCENARIOS[title])
		if (RECORD) {
			recorded[title] = result
			return
		}
		expect(result).toEqual(golden[title])
	})

	it('has no recorded scenario the spec no longer runs', () => {
		if (!RECORD) {
			expect(Object.keys(golden).sort()).toEqual(Object.keys(SCENARIOS).sort())
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
