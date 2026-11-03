/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the scanner store's status label and colour getters
 * over every sync state and generated badge fields.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { describe, it, vi } from 'vitest'
import { golden, outcome, seeded } from './goldenHarness.js'

vi.mock('../../src/composables/useInventoryDb.js', () => ({}))
vi.mock('../../src/composables/useInventorySync.js', () => ({}))

const check = golden('scannerStatusLabel')

describe('scanner status label golden', () => {
	it('matches the record', async () => {
		const { useInventoryMobileScannerStore } =
			await import('../../src/store/modules/inventoryMobileScanner.js')
		setActivePinia(createPinia())
		const store = useInventoryMobileScannerStore()
		const r = seeded(29)
		const states = [
			'offline',
			'pending',
			'syncing',
			'synced',
			'failed',
			'other',
			'',
			null,
			'toString',
			'constructor',
		]
		for (let i = 0; i < 300; i++) {
			store.$patch({
				syncState: r.pick(states),
				lastSyncedAt: r.pick([null, '', '2026-11-03 10:00']),
				pendingCount: r.pick([0, 3, -1, null]),
				retryInMs: r.pick([0, 1500, 1200, 999, null, undefined, 'x']),
			})
			check(`${i}`, {
				label: outcome(() => store.statusLabel),
				color: outcome(() => store.statusColor),
			})
		}
	})
})
