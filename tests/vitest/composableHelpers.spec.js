/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The composable helpers the existing specs leave out: the klantbeeld
 * timeline formatting, paging and deep link, and the inventory sync's
 * cursor query, kept-local tally, location fetch, scheduler timer and
 * transaction ids.
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mergeServerDelta } from '../../src/composables/useInventoryDb.js'
import {
	createSyncScheduler,
	fetchLocations,
	newTransactionId,
	syncDownload,
} from '../../src/composables/useInventorySync.js'
import {
	buildPipelinqLink,
	formatTransactionAmount,
	formatTransactionDate,
	nextPageParams,
} from '../../src/composables/usePipelinqProfile.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/composables/useInventoryDb.js', () => ({
	deletePendingOp: vi.fn(),
	markOpSynced: vi.fn(),
	mergeServerDelta: vi.fn(async () => 'applied-server'),
	readUnsyncedOps: vi.fn(async () => []),
}))

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('formatTransactionAmount', () => {
	it('renders the currency and two decimals', () => {
		expect(formatTransactionAmount({ currency: 'USD', amount: '12.5' })).toBe('USD 12.50')
	})

	it('falls back to EUR and zero for a partial row', () => {
		expect(formatTransactionAmount({ currency: '  ', amount: 'abc' })).toBe('EUR 0.00')
		expect(formatTransactionAmount(null)).toBe('EUR 0.00')
	})
})

describe('formatTransactionDate', () => {
	it('renders a parseable date as YYYY-MM-DD', () => {
		expect(formatTransactionDate('2026-03-04T10:00:00Z')).toBe('2026-03-04')
	})

	it('keeps an unparseable string and is empty for nothing', () => {
		expect(formatTransactionDate('next week')).toBe('next week')
		expect(formatTransactionDate('  ')).toBe('')
		expect(formatTransactionDate(undefined)).toBe('')
	})
})

describe('nextPageParams', () => {
	it('advances the offset by the limit', () => {
		expect(nextPageParams({ limit: 10, offset: 20 })).toEqual({ limit: 10, offset: 30 })
	})

	it('uses the default limit and zero offset when the envelope lacks them', () => {
		expect(nextPageParams({ limit: 'x', offset: 'y' }, 7)).toEqual({ limit: 7, offset: 7 })
		expect(nextPageParams(undefined)).toEqual({ limit: 5, offset: 5 })
	})

	it('clamps the limit to at least one and the offset to at least zero', () => {
		expect(nextPageParams({ limit: 0, offset: -4 })).toEqual({ limit: 1, offset: 1 })
	})
})

describe('buildPipelinqLink', () => {
	it('joins the base without trailing slashes and the encoded id', () => {
		expect(buildPipelinqLink('https://crm.example/apps/pipelinq//', 'c 1/2')).toBe(
			'https://crm.example/apps/pipelinq/contacts/c%201%2F2',
		)
	})

	it('is null without a base or an id', () => {
		expect(buildPipelinqLink('', 'c1')).toBeNull()
		expect(buildPipelinqLink('https://crm.example', '  ')).toBeNull()
	})
})

describe('syncDownload', () => {
	it('sends the cursor and counts applied and kept rows', async () => {
		axios.get.mockResolvedValue({
			data: { deltas: [{ sku: 'A' }, { sku: 'B' }, { sku: 'C' }], serverTimestamp: 'T2' },
		})
		mergeServerDelta
			.mockResolvedValueOnce('applied-server')
			.mockResolvedValueOnce('kept-local')
			.mockResolvedValueOnce('applied-server')

		const result = await syncDownload('db', '2026-11-01T00:00:00+01:00')

		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/inventory/sync?since=2026-11-01T00%3A00%3A00%2B01%3A00',
		)
		expect(result).toEqual({ applied: 2, kept: 1, serverTimestamp: 'T2' })
	})
})

describe('fetchLocations', () => {
	it('answers the locations of the response', async () => {
		axios.get.mockResolvedValue({ data: { locations: [{ code: 'A1' }] } })

		expect(await fetchLocations()).toEqual([{ code: 'A1' }])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/inventory/locations')
	})

	it('answers no locations for an odd response', async () => {
		axios.get.mockResolvedValue({ data: { locations: 'none' } })
		expect(await fetchLocations()).toEqual([])
		axios.get.mockResolvedValue(null)
		expect(await fetchLocations()).toEqual([])
	})
})

describe('createSyncScheduler timer', () => {
	it('runs a cycle every interval after start, and none after stop', async () => {
		vi.useFakeTimers()
		axios.get.mockResolvedValue({ data: { deltas: [], serverTimestamp: 'T' } })
		const states = []
		const scheduler = createSyncScheduler('db', {
			intervalMs: 1000,
			onStatus: (s) => states.push(s.state),
		})

		scheduler.start()
		await vi.advanceTimersByTimeAsync(999)
		expect(axios.get).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(axios.get).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(1000)
		expect(axios.get).toHaveBeenCalledTimes(2)

		scheduler.stop()
		await vi.advanceTimersByTimeAsync(5000)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(states).toEqual(['syncing', 'synced', 'syncing', 'synced'])
	})

	it('can be stopped before it ever started', () => {
		expect(() => createSyncScheduler('db').stop()).not.toThrow()
	})
})

describe('newTransactionId', () => {
	it('uses the Web Crypto UUID when available', () => {
		vi.stubGlobal('crypto', { randomUUID: () => 'uuid-from-crypto' })
		expect(newTransactionId()).toBe('uuid-from-crypto')
	})

	it('falls back to a v4-shaped id without Web Crypto', () => {
		vi.stubGlobal('crypto', undefined)
		const id = newTransactionId()
		expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
	})
})
