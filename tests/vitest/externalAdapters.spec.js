/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the Shillinq W8 External-Adapters admin SFCs
 * (src/views/external-adapters/ExternalAdaptersStatus.vue +
 *  src/views/external-adapters/ExternalAdapterDetail.vue).
 *
 * These pages drive the operator-facing roll-up over the 14 dormant
 * external-API adapter ports. They carry real app-local logic that is worth
 * pinning independently of a browser:
 *
 *   ExternalAdaptersStatus
 *     - loadStatus(): unwraps the { adapters, summary } envelope from
 *       /api/admin/external-adapters, tolerating a missing/partial body.
 *     - badgeClass(): maps dormant -> the dormant/live badge modifier.
 *     - routeIdForAdapter(): the stable slug -> manifest route-name map that
 *       the "View activation" button navigates through (a typo here silently
 *       dead-ends a nav, so every one of the 14 families is asserted).
 *     - openDetail(): only pushes a route for a known slug.
 *
 *   ExternalAdapterDetail
 *     - effectiveAdapterId: prefers the manifest `adapterId` prop, else falls
 *       back to the trailing path segment so a deep-link still resolves.
 *     - badgeClass: dormant/live modifier, null-safe before the adapter loads.
 *     - loadAdapter(): GETs /api/admin/external-adapters/{id}, surfaces a
 *       distinct "Unknown adapter" message on 404 vs a generic error, and
 *       guards the empty-id case.
 *
 * The `.vue` SFCs are compiled by @vitejs/plugin-vue2 (see vitest.config.js)
 * and their option `methods` / `computed` are invoked bound to a fake `this`
 * — no DOM mount. `@nextcloud/vue` / `@nextcloud/axios` are aliased to light
 * stubs; the global `t()` translator and `axios.get` are mocked per-test.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import axios from '@nextcloud/axios'
import StatusView from '../../src/views/external-adapters/ExternalAdaptersStatus.vue'
import DetailView from '../../src/views/external-adapters/ExternalAdapterDetail.vue'

/** Identity translator: returns the source string (or fills {placeholders}). */
function tIdentity(app, text, vars) {
	if (!vars) {
		return text
	}
	return text.replace(/\{(\w+)\}/g, (_, k) =>
		k in vars ? String(vars[k]) : `{${k}}`,
	)
}

/** All 14 adapter family slugs the W8 manifest ships. */
const ALL_SLUGS = [
	'digipoort-sbr',
	'salarisbureau',
	'rvo',
	'ib47',
	'cbs-bestanden',
	'cbs-iv3',
	'bzk-sisa',
	'mollie',
	'bunq',
	'kvk',
	'uwv',
	'treasury-rates',
	'ccm-rule-engine',
	'csrd-esrs-xbrl',
	'deposit-payment',
]

beforeEach(() => {
	globalThis.t = tIdentity
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.t
})

describe('ExternalAdaptersStatus.vue', () => {
	it('starts loading with an empty summary', () => {
		const d = StatusView.data()
		expect(d.loading).toBe(true)
		expect(d.adapters).toEqual([])
		expect(d.summary).toEqual({ total: 0, dormant: 0, live: 0 })
		expect(d.errorMessage).toBe('')
	})

	it('badgeClass maps dormant -> dormant modifier, live -> live modifier', () => {
		expect(StatusView.methods.badgeClass(true)).toBe(
			'external-adapters__badge--dormant',
		)
		expect(StatusView.methods.badgeClass(false)).toBe(
			'external-adapters__badge--live',
		)
	})

	it('routeIdForAdapter resolves every one of the 14 families to a distinct route name', () => {
		const routes = ALL_SLUGS.map((slug) =>
			StatusView.methods.routeIdForAdapter(slug),
		)
		// Every family maps to a non-null route name.
		expect(routes.every((r) => typeof r === 'string' && r.length > 0)).toBe(true)
		// And all 14 route names are distinct (no collision dead-ending a nav).
		expect(new Set(routes).size).toBe(ALL_SLUGS.length)
		// Spot-check a couple of the trickier dash-vs-camel mappings.
		expect(StatusView.methods.routeIdForAdapter('digipoort-sbr')).toBe(
			'ExternalAdapterDigipoort',
		)
		expect(StatusView.methods.routeIdForAdapter('bzk-sisa')).toBe(
			'ExternalAdapterSisa',
		)
		expect(StatusView.methods.routeIdForAdapter('treasury-rates')).toBe(
			'ExternalAdapterTreasuryRates',
		)
	})

	it('routeIdForAdapter returns null for an unknown slug', () => {
		expect(StatusView.methods.routeIdForAdapter('does-not-exist')).toBeNull()
	})

	it('openDetail pushes the resolved route for a known slug, no-ops for an unknown one', () => {
		const push = vi.fn()
		const ctx = {
			$router: { push },
			routeIdForAdapter: StatusView.methods.routeIdForAdapter,
		}

		StatusView.methods.openDetail.call(ctx, 'mollie')
		expect(push).toHaveBeenCalledWith({ name: 'ExternalAdapterMollie' })

		push.mockClear()
		StatusView.methods.openDetail.call(ctx, 'unknown-slug')
		expect(push).not.toHaveBeenCalled()
	})

	it('loadStatus unwraps the { adapters, summary } envelope from the admin endpoint', async () => {
		const payload = {
			adapters: [
				{ id: 'mollie', title: 'Mollie Payments', dormant: true },
				{ id: 'bunq', title: 'Bunq Bank Connector', dormant: false },
			],
			summary: { total: 2, dormant: 1, live: 1 },
		}
		const getSpy = vi
			.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: payload })

		const ctx = { loading: false, errorMessage: '', adapters: [], summary: {} }
		await StatusView.methods.loadStatus.call(ctx)

		expect(getSpy).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/admin/external-adapters',
		)
		expect(ctx.adapters).toHaveLength(2)
		expect(ctx.summary).toEqual({ total: 2, dormant: 1, live: 1 })
		expect(ctx.loading).toBe(false)
		expect(ctx.errorMessage).toBe('')
	})

	it('loadStatus tolerates a missing/partial response body', async () => {
		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: undefined })
		const ctx = {
			loading: true,
			errorMessage: 'stale',
			adapters: [{ id: 'x' }],
			summary: { total: 9 },
		}
		await StatusView.methods.loadStatus.call(ctx)
		expect(ctx.adapters).toEqual([])
		expect(ctx.summary).toEqual({ total: 0, dormant: 0, live: 0 })
		expect(ctx.loading).toBe(false)
	})

	it('loadStatus records an error message and clears loading on failure', async () => {
		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('boom'))
		const ctx = { loading: true, errorMessage: '', adapters: [], summary: {} }
		await StatusView.methods.loadStatus.call(ctx)
		expect(ctx.errorMessage).toContain('boom')
		expect(ctx.loading).toBe(false)
	})
})

describe('ExternalAdapterDetail.vue', () => {
	it('effectiveAdapterId prefers the manifest adapterId prop', () => {
		const ctx = {
			adapterId: 'mollie',
			$route: { path: '/external-adapters/mollie' },
		}
		expect(DetailView.computed.effectiveAdapterId.call(ctx)).toBe('mollie')
	})

	it('effectiveAdapterId falls back to the trailing path segment for a deep-link', () => {
		const ctx = {
			adapterId: '',
			$route: { path: '/external-adapters/csrd-esrs-xbrl' },
		}
		expect(DetailView.computed.effectiveAdapterId.call(ctx)).toBe(
			'csrd-esrs-xbrl',
		)
	})

	it('effectiveAdapterId is empty-string-safe with no prop and no route', () => {
		const ctx = { adapterId: '', $route: undefined }
		expect(DetailView.computed.effectiveAdapterId.call(ctx)).toBe('')
	})

	it('badgeClass is null-safe before the adapter loads, then maps dormant/live', () => {
		expect(DetailView.computed.badgeClass.call({ adapter: null })).toBe('')
		expect(
			DetailView.computed.badgeClass.call({ adapter: { dormant: true } }),
		).toBe('adapter-detail__badge--dormant')
		expect(
			DetailView.computed.badgeClass.call({ adapter: { dormant: false } }),
		).toBe('adapter-detail__badge--live')
	})

	it('loadAdapter GETs the per-id endpoint and stores the adapter', async () => {
		const adapter = {
			id: 'bunq',
			title: 'Bunq Bank Connector',
			dormant: true,
			steps: ['a', 'b'],
		}
		const getSpy = vi
			.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: adapter })

		const ctx = {
			effectiveAdapterId: 'bunq',
			loading: false,
			errorMessage: 'x',
			adapter: null,
		}
		await DetailView.methods.loadAdapter.call(ctx)

		expect(getSpy).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/admin/external-adapters/bunq',
		)
		expect(ctx.adapter).toEqual(adapter)
		expect(ctx.loading).toBe(false)
		expect(ctx.errorMessage).toBe('')
	})

	it('loadAdapter surfaces a distinct Unknown-adapter message on a 404', async () => {
		vi.spyOn(axios, 'get').mockRejectedValueOnce({ response: { status: 404 } })
		const ctx = {
			effectiveAdapterId: 'nope',
			loading: true,
			errorMessage: '',
			adapter: { stale: true },
		}
		await DetailView.methods.loadAdapter.call(ctx)
		expect(ctx.errorMessage).toBe('Unknown adapter: nope')
		expect(ctx.adapter).toBeNull()
		expect(ctx.loading).toBe(false)
	})

	it('loadAdapter surfaces a generic message on a non-404 failure', async () => {
		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('network down'))
		const ctx = {
			effectiveAdapterId: 'mollie',
			loading: true,
			errorMessage: '',
			adapter: null,
		}
		await DetailView.methods.loadAdapter.call(ctx)
		expect(ctx.errorMessage).toContain('network down')
		expect(ctx.loading).toBe(false)
	})

	it('loadAdapter guards the empty-id case without calling the API', async () => {
		const getSpy = vi.spyOn(axios, 'get')
		const ctx = {
			effectiveAdapterId: '',
			loading: true,
			errorMessage: '',
			adapter: null,
		}
		await DetailView.methods.loadAdapter.call(ctx)
		expect(getSpy).not.toHaveBeenCalled()
		expect(ctx.errorMessage).toBe('No adapter id provided.')
		expect(ctx.loading).toBe(false)
	})

	it('backToIndex navigates to the status index route', () => {
		const push = vi.fn()
		DetailView.methods.backToIndex.call({ $router: { push } })
		expect(push).toHaveBeenCalledWith({ name: 'ExternalAdaptersStatus' })
	})
})
