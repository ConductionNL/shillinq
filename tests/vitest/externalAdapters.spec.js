/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the integration-config-to-openconnector External
 * Connections roster (src/views/external-adapters/ExternalAdaptersStatus.vue).
 *
 * The roster page replaced the former index + 15 per-adapter detail pages
 * (openspec/changes/integration-config-to-openconnector, REQ-ICO-002): one
 * row per family, expandable in place for the activation recipe, with a
 * live openconnector-provisioning verdict per row (REQ-ICO-003). It carries
 * real app-local logic worth pinning independently of a browser:
 *
 *   - loadStatus(): unwraps the { adapters, summary } envelope from
 *     /api/admin/external-adapters, tolerating a missing/partial body.
 *   - badgeClass(): maps dormant -> the dormant/live badge modifier.
 *   - provisioningBadgeClass()/provisioningLabel(): map the three
 *     REQ-ICO-003 provisioning states (provisioned / declared-not-provisioned
 *     / unknown) to a badge modifier + operator-facing label — the fail-soft
 *     "unknown" state must never be mistaken for "provisioned".
 *   - isExpanded()/toggleExpanded(): the in-row activation-recipe disclosure
 *     that replaced the old per-family route.
 *
 * The `.vue` SFC is compiled by @vitejs/plugin-vue2 (see vitest.config.js)
 * and its option `methods` are invoked bound to a fake `this` — no DOM
 * mount. `@nextcloud/vue` / `@nextcloud/axios` are aliased to light stubs;
 * the global `t()` translator and `axios.get` are mocked per-test.
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import StatusView from '../../src/views/external-adapters/ExternalAdaptersStatus.vue'

/** Identity translator: returns the source string (or fills {placeholders}). */
function tIdentity(app, text, vars) {
	if (!vars) {
		return text
	}
	return text.replace(/\{(\w+)\}/g, (_, k) =>
		k in vars ? String(vars[k]) : `{${k}}`,
	)
}

beforeEach(() => {
	globalThis.t = tIdentity
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.t
})

describe('ExternalAdaptersStatus.vue', () => {
	it('starts loading with an empty summary and no expanded rows', () => {
		const d = StatusView.data()
		expect(d.loading).toBe(true)
		expect(d.adapters).toEqual([])
		expect(d.summary).toEqual({ total: 0, dormant: 0, live: 0 })
		expect(d.errorMessage).toBe('')
		expect(d.expandedIds).toEqual([])
	})

	it('badgeClass maps dormant -> dormant modifier, live -> live modifier', () => {
		expect(StatusView.methods.badgeClass(true)).toBe(
			'external-adapters__badge--dormant',
		)
		expect(StatusView.methods.badgeClass(false)).toBe(
			'external-adapters__badge--live',
		)
	})

	it('provisioningBadgeClass maps every REQ-ICO-003 status to a distinct modifier', () => {
		expect(StatusView.methods.provisioningBadgeClass('provisioned')).toBe(
			'external-adapters__badge--live',
		)
		expect(
			StatusView.methods.provisioningBadgeClass('declared-not-provisioned'),
		).toBe('external-adapters__badge--dormant')
		expect(StatusView.methods.provisioningBadgeClass('unknown')).toBe(
			'external-adapters__badge--unknown',
		)
		// A missing/unrecognised status fails soft to the same visual as
		// "declared, not provisioned" rather than silently reading as live.
		expect(StatusView.methods.provisioningBadgeClass(undefined)).toBe(
			'external-adapters__badge--dormant',
		)
	})

	it('provisioningLabel never reads "unknown" as "provisioned"', () => {
		expect(StatusView.methods.provisioningLabel('provisioned')).toBe(
			'Provisioned in OpenConnector',
		)
		expect(StatusView.methods.provisioningLabel('unknown')).toBe(
			'Provisioning status unknown',
		)
		expect(
			StatusView.methods.provisioningLabel('declared-not-provisioned'),
		).toBe('Declared, provision in OpenConnector')
	})

	it('toggleExpanded / isExpanded track per-row disclosure state independently', () => {
		const ctx = {
			expandedIds: [],
			isExpanded: StatusView.methods.isExpanded,
		}
		expect(StatusView.methods.isExpanded.call(ctx, 'mollie')).toBe(false)

		StatusView.methods.toggleExpanded.call(ctx, 'mollie')
		expect(ctx.expandedIds).toEqual(['mollie'])
		expect(StatusView.methods.isExpanded.call(ctx, 'mollie')).toBe(true)
		expect(StatusView.methods.isExpanded.call(ctx, 'bunq')).toBe(false)

		StatusView.methods.toggleExpanded.call(ctx, 'bunq')
		expect(ctx.expandedIds).toEqual(['mollie', 'bunq'])

		StatusView.methods.toggleExpanded.call(ctx, 'mollie')
		expect(ctx.expandedIds).toEqual(['bunq'])
	})

	it('loadStatus unwraps the { adapters, summary } envelope from the admin endpoint', async () => {
		const payload = {
			adapters: [
				{
					id: 'mollie',
					title: 'Mollie Payments',
					dormant: true,
					provisioning: {
						status: 'declared-not-provisioned',
						deepLink: '/apps/openconnector/sources',
					},
				},
				{
					id: 'bunq',
					title: 'Bunq Bank Connector',
					dormant: false,
					provisioning: {
						status: 'provisioned',
						openconnectorObjectId: 'abc-123',
						deepLink: '/apps/openconnector/sources',
					},
				},
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
		expect(ctx.adapters[1].provisioning.status).toBe('provisioned')
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

	it('loadStatus records an error message and clears loading on failure — REQ-ICO-003 whole-response failure, distinct from a per-row "unknown"', async () => {
		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('boom'))
		const ctx = { loading: true, errorMessage: '', adapters: [], summary: {} }
		await StatusView.methods.loadStatus.call(ctx)
		expect(ctx.errorMessage).toContain('boom')
		expect(ctx.loading).toBe(false)
	})
})
