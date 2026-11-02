/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the inventory sync composable's `syncUpload`. The
 * IndexedDB composable, axios and the router are replaced by recorders, so
 * each scenario pins the posted batch, every synced mark and delete, the
 * returned counts and any error, compared with
 * tests/vitest/fixtures/inventorySyncUpload.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const calls = vi.hoisted(() => ({
	log: [],
	pending: [],
	response: null,
	fail: null,
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => `/index.php${url}`,
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async () => ({ data: {} }),
		post: async (url, body) => {
			calls.log.push(['post', url, structuredClone(body)])
			if (calls.fail === 'post') {
				throw new Error('network down')
			}
			return calls.response
		},
	},
}))
vi.mock('../../src/composables/useInventoryDb.js', () => ({
	readUnsyncedOps: async (db) => {
		calls.log.push(['readUnsyncedOps', db.name])
		return calls.pending
	},
	markOpSynced: async (db, id, at) => {
		calls.log.push(['markOpSynced', db.name, id, at])
		if (calls.fail === 'markOpSynced:' + id) {
			throw new Error('mark failed')
		}
	},
	deletePendingOp: async (db, id) => {
		calls.log.push(['deletePendingOp', db.name, id])
		if (calls.fail === 'deletePendingOp:' + id) {
			throw new Error('delete failed')
		}
	},
	mergeServerDelta: async () => 'applied-server',
}))

const { syncUpload } = await import('../../src/composables/useInventorySync.js')

const GOLDEN = path.join(__dirname, 'fixtures/inventorySyncUpload.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const FULL_OP = {
	id: 1,
	transactionId: 'tx-1',
	type: 'transfer',
	sku: 'A',
	location: 'L1',
	toLocation: 'L2',
	quantity: 3,
	physicalQuantity: 9,
	reconcile: true,
	orderLineId: 'OL-1',
	timestamp: '2026-11-01T10:00:00.000Z',
	synced: 0,
	extra: 'not posted',
}

/**
 * A pending receive op with the given id, plus overrides.
 *
 * @param {number} id The op id; the transactionId is tx-<id>.
 * @param {object} more Fields to override.
 * @return {object} The op.
 */
function op(id, more = {}) {
	return {
		id,
		transactionId: `tx-${id}`,
		type: 'receive',
		sku: 'S',
		quantity: id,
		...more,
	}
}

/**
 * An axios response with the given body.
 *
 * @param {object|null} data The body.
 * @return {object} The response.
 */
function ok(data) {
	return { data }
}

const SCENARIOS = {
	'nothing pending': { pending: [] },
	'every field of an operation': {
		pending: [FULL_OP],
		response: ok({
			results: [
				{
					transactionId: 'tx-1',
					status: 'accepted',
					serverAckedAt: 'ack-1',
				},
			],
		}),
	},
	'accepted with the ack time': {
		pending: [op(1)],
		response: ok({
			serverTimestamp: 'srv',
			results: [
				{
					transactionId: 'tx-1',
					status: 'accepted',
					serverAckedAt: 'ack-1',
				},
			],
		}),
	},
	'accepted with the server time': {
		pending: [op(1)],
		response: ok({
			serverTimestamp: 'srv',
			results: [{ transactionId: 'tx-1', status: 'accepted' }],
		}),
	},
	'accepted with an empty ack time': {
		pending: [op(1)],
		response: ok({
			serverTimestamp: 'srv',
			results: [
				{ transactionId: 'tx-1', status: 'accepted', serverAckedAt: '' },
			],
		}),
	},
	'accepted with no time at all': {
		pending: [op(1)],
		response: ok({ results: [{ transactionId: 'tx-1', status: 'accepted' }] }),
	},
	'duplicate with the ack time': {
		pending: [op(1)],
		response: ok({
			serverTimestamp: 'srv',
			results: [
				{
					transactionId: 'tx-1',
					status: 'duplicate',
					serverAckedAt: 'ack-1',
				},
			],
		}),
	},
	'duplicate with the server time': {
		pending: [op(1)],
		response: ok({
			serverTimestamp: 'srv',
			results: [{ transactionId: 'tx-1', status: 'duplicate' }],
		}),
	},
	'duplicate with no time at all': {
		pending: [op(1)],
		response: ok({ results: [{ transactionId: 'tx-1', status: 'duplicate' }] }),
	},
	rejected: {
		pending: [op(1)],
		response: ok({
			results: [
				{ transactionId: 'tx-1', status: 'rejected', reason: 'permission' },
			],
		}),
	},
	'unknown status': {
		pending: [op(1)],
		response: ok({ results: [{ transactionId: 'tx-1', status: 'pending' }] }),
	},
	'inherited property name as status': {
		pending: [op(1)],
		response: ok({
			results: [{ transactionId: 'tx-1', status: 'constructor' }],
		}),
	},
	'status in another case': {
		pending: [op(1)],
		response: ok({ results: [{ transactionId: 'tx-1', status: 'Accepted' }] }),
	},
	'no status': {
		pending: [op(1)],
		response: ok({ results: [{ transactionId: 'tx-1' }] }),
	},
	'no ack for the operation': {
		pending: [op(1), op(2)],
		response: ok({
			results: [
				{ transactionId: 'tx-2', status: 'accepted' },
				{ transactionId: 'tx-9', status: 'accepted' },
			],
		}),
	},
	'two acks for one transaction': {
		pending: [op(1)],
		response: ok({
			results: [
				{ transactionId: 'tx-1', status: 'rejected' },
				{ transactionId: 'tx-1', status: 'duplicate' },
			],
		}),
	},
	'two operations with one transaction id': {
		pending: [op(1), op(2, { transactionId: 'tx-1' })],
		response: ok({ results: [{ transactionId: 'tx-1', status: 'accepted' }] }),
	},
	'a mixed batch': {
		pending: [op(1), op(2), op(3), op(4), op(5), op(6)],
		response: ok({
			serverTimestamp: 'srv',
			results: [
				{ transactionId: 'tx-6', status: 'duplicate' },
				{
					transactionId: 'tx-1',
					status: 'accepted',
					serverAckedAt: 'ack-1',
				},
				{ transactionId: 'tx-2', status: 'rejected' },
				{
					transactionId: 'tx-3',
					status: 'duplicate',
					serverAckedAt: 'ack-3',
				},
				{ transactionId: 'tx-5', status: 'accepted' },
			],
		}),
	},
	'results not a list': {
		pending: [op(1)],
		response: ok({ results: { 'tx-1': 'accepted' } }),
	},
	'no results': { pending: [op(1)], response: ok({ serverTimestamp: 'srv' }) },
	'empty data': { pending: [op(1)], response: ok(null) },
	'no response': { pending: [op(1)], response: null },
	'an ack without a transaction id': {
		pending: [op(1, { transactionId: undefined })],
		response: ok({ results: [{ status: 'accepted' }] }),
	},
	'the post fails': { pending: [op(1)], fail: 'post' },
	'marking the second operation fails': {
		pending: [op(1), op(2), op(3)],
		fail: 'markOpSynced:2',
		response: ok({
			results: [
				{ transactionId: 'tx-1', status: 'accepted' },
				{ transactionId: 'tx-2', status: 'duplicate' },
				{ transactionId: 'tx-3', status: 'accepted' },
			],
		}),
	},
	'deleting the first operation fails': {
		pending: [op(1), op(2)],
		fail: 'deletePendingOp:1',
		response: ok({
			results: [
				{ transactionId: 'tx-1', status: 'accepted' },
				{ transactionId: 'tx-2', status: 'accepted' },
			],
		}),
	},
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
 * Run syncUpload for one scenario and return what it did.
 *
 * @param {object} scenario The pending operations, the response and a failure.
 * @return {Promise<object>} The calls, the counts and any error.
 */
async function run(scenario) {
	calls.log = []
	calls.pending = scenario.pending
	calls.response = scenario.response ?? null
	calls.fail = scenario.fail ?? null
	let returned = null
	let thrown = null
	try {
		returned = await syncUpload({ name: 'db' })
	} catch (e) {
		thrown = e.message
	}
	return faithful({
		calls: calls.log,
		returned,
		keys: returned ? Object.keys(returned) : null,
		thrown,
	})
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('inventory sync syncUpload', () => {
	beforeEach(() => {
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
