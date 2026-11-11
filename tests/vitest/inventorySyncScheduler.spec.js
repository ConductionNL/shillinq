/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for the inventory sync scheduler's cycle
 * (createSyncScheduler().triggerNow()): the status it reports offline, after
 * a sync and after a failure, the backoff steps and the cursor it keeps.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSyncScheduler } from '../../src/composables/useInventorySync.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/composables/useInventoryDb.js', () => ({
	deletePendingOp: vi.fn(),
	markOpSynced: vi.fn(),
	mergeServerDelta: vi.fn(async () => 'applied-server'),
	readUnsyncedOps: vi.fn(async () => []),
}))

/**
 * A scheduler whose status reports and cursor writes are recorded.
 *
 * @return {{scheduler: object, statuses: Array, cursors: Array}}
 */
function recorded() {
	const statuses = []
	const cursors = []
	const scheduler = createSyncScheduler({}, {
		onStatus: (status) => statuses.push(status),
		getLastSyncedAt: () => 'cursor-0',
		setLastSyncedAt: (value) => cursors.push(value),
	})
	return { scheduler, statuses, cursors }
}

describe('inventory sync scheduler cycle', () => {
	beforeEach(() => {
		axios.get.mockReset()
		vi.stubGlobal('navigator', { onLine: true })
	})

	it('reports offline without syncing', async () => {
		vi.stubGlobal('navigator', { onLine: false })
		const { scheduler, statuses, cursors } = recorded()

		await scheduler.triggerNow()

		expect(axios.get).not.toHaveBeenCalled()
		expect([statuses, cursors]).toEqual([[{ state: 'offline', lastSyncedAt: 'cursor-0', error: null }], []])
	})

	it('reports syncing then synced with the summary, and keeps the server cursor', async () => {
		axios.get.mockResolvedValue({ data: { deltas: [{ id: 1 }, { id: 2 }], serverTimestamp: 'ts-1' } })
		const { scheduler, statuses, cursors } = recorded()

		await scheduler.triggerNow()

		expect(statuses).toEqual([
			{ state: 'syncing', lastSyncedAt: 'cursor-0', error: null },
			{ state: 'synced', lastSyncedAt: 'ts-1', summary: { applied: 2, kept: 0, accepted: 0, duplicates: 0, rejected: [] }, error: null },
		])
		expect(cursors).toEqual(['ts-1'])
	})

	it('backs off 1s, 2s, 4s, 8s, 8s on repeated failures and resets after a sync', async () => {
		const { scheduler, statuses } = recorded()
		axios.get.mockRejectedValue(new Error('down'))
		for (let i = 0; i < 5; i++) {
			await scheduler.triggerNow()
		}
		axios.get.mockResolvedValueOnce({ data: { deltas: [], serverTimestamp: 'ts-2' } })
		await scheduler.triggerNow()
		axios.get.mockRejectedValueOnce('plain')
		await scheduler.triggerNow()

		const failures = statuses.filter((status) => status.state === 'failed')
		expect(failures.map((status) => status.retryInMs)).toEqual([1000, 2000, 4000, 8000, 8000, 1000])
		expect(failures[0]).toEqual({ state: 'failed', lastSyncedAt: 'cursor-0', error: 'down', retryInMs: 1000 })
		expect(failures[5].error).toBe('plain')
	})
})
