/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the SyncStatusBadge colour class and label for every
 * combination of sync state, pending count, last sync time and retry delay.
 * Each case runs the computed properties on a fake instance with a fixed
 * clock and compares them with tests/vitest/fixtures/syncStatusBadge.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => null,
}))

const { default: SyncStatusBadge } = await import('../../src/components/inventory/SyncStatusBadge.vue')

const GOLDEN = path.join(__dirname, 'fixtures/syncStatusBadge.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const NOW = Date.parse('2026-06-01T12:00:00Z')
const recorded = {}

/**
 * Translate to a visible string that carries the app, text and variables.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The fake translation.
 */
function fakeT(app, text, vars) {
	return vars === undefined ? `${app}:${text}` : `${app}:${text}|${JSON.stringify(vars)}`
}

/**
 * The badge's class and label for one store state.
 *
 * @param {object} store The scanner store fields.
 * @return {object} The colour class, the label and whether it is stale.
 */
function badge(store) {
	const vm = { ...SyncStatusBadge.data(), now: NOW, store, t: fakeT }
	for (const [name, fn] of Object.entries(SyncStatusBadge.methods)) {
		vm[name] = fn.bind(vm)
	}
	vm.formatTime = (iso) => `time(${iso})`
	const computed = (name) => SyncStatusBadge.computed[name].call(vm)
	Object.defineProperty(vm, 'isStale', { get: () => computed('isStale') })
	return { badgeClass: computed('badgeClass'), humanLabel: computed('humanLabel'), isStale: vm.isStale }
}

const STATES = ['idle', 'syncing', 'pending', 'failed', 'offline', undefined]
const PENDING = [0, 3]
const SYNCED = {
	never: null,
	recent: '2026-06-01T11:59:00Z',
	twoMinutes: '2026-06-01T11:58:00Z',
	stale: '2026-06-01T11:57:59Z',
	invalid: 'not a date',
}
const RETRY = [undefined, 1500]

const cases = {}
for (const syncState of STATES) {
	for (const pendingCount of PENDING) {
		for (const [synced, lastSyncedAt] of Object.entries(SYNCED)) {
			for (const retryInMs of RETRY) {
				cases[`${syncState} / pending ${pendingCount} / ${synced} / retry ${retryInMs}`] = {
					syncState,
					pendingCount,
					lastSyncedAt,
					retryInMs,
				}
			}
		}
	}
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('SyncStatusBadge', () => {
	it.each(Object.keys(cases))('%s', (key) => {
		const result = badge(cases[key])
		if (RECORD) {
			recorded[key] = result
			return
		}
		expect(result).toEqual(golden[key])
	})

	it('has no case the spec no longer runs', () => {
		if (!RECORD) {
			expect(Object.keys(golden).sort()).toEqual(Object.keys(cases).sort())
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
