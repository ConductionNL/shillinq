/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the Shillinq app-administration settings Pinia store
 * (src/store/modules/settings.js): fetch envelope handling, the
 * hasOpenRegisters / isAdmin flag derivation, the loading lifecycle and the
 * save round-trip. global fetch + the OC global are mocked; @nextcloud/router
 * is aliased to a stub.
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

function mockFetchOnce({ ok = true, json = {} }) {
	globalThis.fetch = vi.fn().mockResolvedValueOnce({
		ok,
		json: async () => json,
	})
}

describe('shillinq settings store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		globalThis.OC = { requestToken: 'test-token' }
	})

	afterEach(() => {
		vi.restoreAllMocks()
		delete globalThis.fetch
		delete globalThis.OC
	})

	it('has sensible defaults and getters', () => {
		const store = useSettingsStore()
		expect(store.settings).toEqual({})
		expect(store.loading).toBe(false)
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.isAdmin).toBe(false)
		expect(store.getSettings).toEqual({})
		expect(store.getIsAdmin).toBe(false)
	})

	it('fetchSettings stores data and derives openregisters/admin flags', async () => {
		mockFetchOnce({
			json: { openregisters: true, isAdmin: true, invoiceSchema: 'invoice' },
		})
		const store = useSettingsStore()
		const data = await store.fetchSettings()
		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/settings',
			expect.objectContaining({ headers: { requesttoken: 'test-token' } }),
		)
		expect(data).toMatchObject({ invoiceSchema: 'invoice' })
		expect(store.settings).toMatchObject({ invoiceSchema: 'invoice' })
		expect(store.hasOpenRegisters).toBe(true)
		expect(store.isAdmin).toBe(true)
		expect(store.loading).toBe(false)
	})

	it('fetchSettings coerces falsy/absent flags to false', async () => {
		mockFetchOnce({ json: { somethingElse: 1 } })
		const store = useSettingsStore()
		await store.fetchSettings()
		expect(store.hasOpenRegisters).toBe(false)
		expect(store.isAdmin).toBe(false)
	})

	it('fetchSettings returns null and clears loading on a non-ok response', async () => {
		mockFetchOnce({ ok: false, json: {} })
		const store = useSettingsStore()
		const result = await store.fetchSettings()
		expect(result).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('fetchSettings swallows a network error and clears loading', async () => {
		globalThis.fetch = vi.fn().mockRejectedValueOnce(new Error('offline'))
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const store = useSettingsStore()
		const result = await store.fetchSettings()
		expect(result).toBeNull()
		expect(store.loading).toBe(false)
	})

	it('saveSettings POSTs the body and stores the returned settings', async () => {
		globalThis.fetch = vi.fn().mockResolvedValueOnce({
			ok: true,
			json: async () => ({ saved: true }),
		})
		const store = useSettingsStore()
		const result = await store.saveSettings({ invoiceSchema: 'inv' })
		const [, opts] = globalThis.fetch.mock.calls[0]
		expect(opts.method).toBe('POST')
		expect(JSON.parse(opts.body)).toEqual({ invoiceSchema: 'inv' })
		expect(result).toEqual({ saved: true })
		expect(store.settings).toEqual({ saved: true })
		expect(store.loading).toBe(false)
	})

	it('saveSettings returns null and clears loading on a non-ok response', async () => {
		mockFetchOnce({ ok: false, json: {} })
		const store = useSettingsStore()
		const result = await store.saveSettings({ a: 1 })
		expect(result).toBeNull()
		expect(store.loading).toBe(false)
	})
})
