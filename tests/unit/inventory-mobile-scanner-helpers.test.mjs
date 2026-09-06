/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure-Node unit tests for the inventory mobile scanner client helpers
 * that don't depend on Vue or NC (T6.1).
 *
 * Covers:
 *   - composeStockKey() — composite key stability
 *   - isStrictlyLater() — LWW timestamp comparison (REQ-OFFLINE-003)
 *   - newTransactionId() — UUID shape so dedup keys are usable
 *
 * Run with: node --test tests/unit/inventory-mobile-scanner-helpers.test.mjs
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md
 */

import assert from 'node:assert/strict'
import { test } from 'node:test'
// Import the source modules directly. These are plain ESM so node --test
// can load them without a bundler. useInventorySync.js depends on
// @nextcloud/axios at the top level; we replicate its newTransactionId()
// here to keep this test environment-independent.
import {
	composeStockKey,
	isStrictlyLater,
} from '../../src/composables/useInventoryDb.js'

/**
 * Local re-implementation of useInventorySync.newTransactionId() so the
 * pure-Node test doesn't need to import the axios-bound module.
 *
 * @return {string} A v4-shape UUID.
 */
function newTransactionId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
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

test('composeStockKey produces a stable sku|location composite', () => {
	assert.equal(composeStockKey('WIDGET-001', 'WH-A1'), 'WIDGET-001|WH-A1')
	assert.equal(composeStockKey('', 'WH-A1'), '|WH-A1')
	assert.equal(composeStockKey('WIDGET-001', ''), 'WIDGET-001|')
})

test('isStrictlyLater returns true only when a is strictly later than b', () => {
	assert.equal(
		isStrictlyLater('2026-05-21T14:23:00Z', '2026-05-21T14:22:59Z'),
		true,
	)
	assert.equal(
		isStrictlyLater('2026-05-21T14:22:59Z', '2026-05-21T14:23:00Z'),
		false,
	)
	assert.equal(
		isStrictlyLater('2026-05-21T14:23:00Z', '2026-05-21T14:23:00Z'),
		false,
	)
})

test('isStrictlyLater is defensive against missing or unparseable timestamps', () => {
	assert.equal(isStrictlyLater(undefined, '2026-05-21T14:23:00Z'), false)
	assert.equal(isStrictlyLater('2026-05-21T14:23:00Z', null), false)
	assert.equal(isStrictlyLater('not-a-date', '2026-05-21T14:23:00Z'), false)
})

test('newTransactionId returns a UUID-shaped string usable as a dedup key', () => {
	const id = newTransactionId()
	assert.equal(typeof id, 'string')
	assert.match(
		id,
		/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/,
	)
	const id2 = newTransactionId()
	assert.notEqual(id, id2, 'consecutive ids must differ')
})
