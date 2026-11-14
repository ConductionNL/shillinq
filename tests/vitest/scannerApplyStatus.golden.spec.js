/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the scanner store's applyStatus over generated sync
 * status payloads, with and without a database: the badge state, the
 * rejected list and the conflict toasts afterwards. The clock is fixed.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterAll, describe, it, vi } from 'vitest'
import { asyncOutcome, golden, seeded } from './goldenHarness.js'

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	readUnsyncedOps: (db) => Promise.resolve(db.pending),
}))
vi.mock('../../src/composables/useInventorySync.js', () => ({}))

const check = golden('scannerApplyStatus')

afterAll(() => {
	vi.useRealTimers()
})

describe('scanner applyStatus golden', () => {
	it('matches the record', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(Date.UTC(2026, 10, 3, 11)))
		const { useInventoryMobileScannerStore } =
			await import('../../src/store/modules/inventoryMobileScanner.js')
		const r = seeded(31)
		for (let i = 0; i < 300; i++) {
			setActivePinia(createPinia())
			const store = useInventoryMobileScannerStore()
			store.$patch({
				db: r.pick([null, { pending: [] }, { pending: [1, 2] }]),
				lastSyncedAt: r.pick([null, 'before']),
				rejected: [],
			})
			const status = {
				state: r.pick(['synced', 'failed', 'syncing', '', undefined]),
				lastSyncedAt: r.pick(['2026-11-03T10:00:00Z', '', undefined]),
				error: r.pick(['offline', '', undefined]),
				retryInMs: r.pick([0, 2000, undefined, null]),
				summary:
					r.next() < 0.2
						? r.pick([undefined, null, 0])
						: {
								rejected: r.pick([['op-1'], [], 'x', undefined]),
								applied: r.pick([0, 2, '3', -1, undefined]),
								kept: r.pick([0, 1, undefined]),
							},
			}
			const result = await asyncOutcome(() => store.applyStatus(status))
			const {
				syncState,
				lastSyncedAt,
				lastError,
				retryInMs,
				rejected,
				conflicts,
				pendingCount,
			} = store.$state
			check(`${i}`, {
				result,
				syncState,
				lastSyncedAt,
				lastError,
				retryInMs,
				rejected,
				conflicts,
				pendingCount,
			})
		}
	})
})
