/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for SpendAnalyticsPanel.vue — the first frontend consumer of
 * `GET /apps/shillinq/api/analytics/spend`.
 *
 * What is worth pinning here is NOT that four sections appear. It is the
 * three places this panel can look finished while lying about money:
 *
 *   - a view that RAISED (glline-administration-scope REQ-GLS-003 turns the
 *     shut backfill gate into HTTP 500) must not reach any figure. The
 *     regression shape is a `total` of `0` rendered as `€0,00` — the silent
 *     zero the gate exists to prevent, arriving through the UI instead of
 *     through the filter;
 *   - "the aggregation matched no rows" and "the aggregation did not run"
 *     must stay two different states. Collapsing them destroys the same
 *     distinction from the other side;
 *   - a caller with no administration must issue NO spend request at all,
 *     because `administration_id` is required and the controller masks a
 *     non-member as 404 — firing anyway would train the reader to read a 404
 *     as "no data".
 *
 * Each is asserted with its negative half. The SFC is compiled by
 * @vitejs/plugin-vue (see vitest.config.js) and its `methods` / `computed`
 * are invoked bound to a fake `this` — no DOM mount, so the environment
 * stays `node`, mirroring tests/vitest/bbvLinkerFilterBar.spec.js.
 *
 * @spec openspec/changes/spend-analytics-ui/specs/spend-analytics/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SpendAnalyticsPanel, {
	SPEND_DIMENSIONS,
} from '../../src/components/spend-analytics/SpendAnalyticsPanel.vue'

/** A successful envelope, verbatim in the controller's documented shape. */
const SUPPLIER_OK = {
	dimension: 'supplier',
	label: 'Spend by supplier',
	groups: [
		{ key: 'VND-001', amount: 1250.5 },
		{ key: null, amount: 99.5 },
	],
	total: 1350,
	backend: 'postgres',
}

/**
 * Build a `this` for the component's methods, seeded with the real `data()`
 * so state transitions are exercised against the shipped initial shape.
 *
 * @param {object} [overrides] Fields to override on the fake instance.
 * @return {object} The bound context.
 */
function ctx(overrides = {}) {
	const base = SpendAnalyticsPanel.data.call({})
	return {
		...base,
		...SpendAnalyticsPanel.methods,
		...overrides,
	}
}

/**
 * An axios error carrying the controller's `{ error: … }` envelope.
 *
 * @param {number} status The HTTP status.
 * @param {string} message The server's message.
 * @return {Error} The rejection value.
 */
function httpError(status, message) {
	const err = new Error(`Request failed with status code ${status}`)
	err.response = { status, data: { error: message } }
	return err
}

describe('SpendAnalyticsPanel — the dimension contract', () => {
	it('offers exactly the four dimensions the controller accepts, by their literal query values', () => {
		// These strings ARE the contract: SpendAnalyticsController::DIMENSIONS
		// rejects anything else with a 400, so a typo here is a dead view, not
		// a cosmetic slip.
		expect(SPEND_DIMENSIONS.map((d) => d.key)).toEqual([
			'supplier',
			'category',
			'costCentre',
			'period',
		])
	})

	it('marks exactly the three GLLine-sourced views as GL-backed', () => {
		const c = ctx()
		expect(c.isGlBacked('supplier')).toBe(false)
		expect(c.isGlBacked('category')).toBe(true)
		expect(c.isGlBacked('costCentre')).toBe(true)
		expect(c.isGlBacked('period')).toBe(true)
	})
})

describe('SpendAnalyticsPanel — a view that did not answer reaches no figure', () => {
	it('reports a failed view as "error", not as "empty"', () => {
		const c = ctx({
			views: {
				category: {
					status: 'error',
					payload: null,
					error: 'Failed to compute spend analysis',
				},
			},
		})
		expect(c.stateFor('category')).toBe('error')
		// The negative half: the empty state must NOT also be reachable.
		expect(c.stateFor('category')).not.toBe('empty')
	})

	it('returns null — not 0 — as the total of a failed view', () => {
		const c = ctx({
			views: {
				category: { status: 'error', payload: null, error: 'boom' },
			},
		})
		expect(c.totalFor('category')).toBeNull()
		// And the formatter must not turn that absence into a currency amount.
		expect(c.formatAmount(c.totalFor('category'))).toBe('—')
	})

	it('returns no groups for a failed view even if a payload were somehow attached', () => {
		// Defence in depth: `loadDimension` never sets a payload on failure,
		// so this asserts the READ path refuses independently of the write
		// path. If both are guarded, neither can be the single point of
		// failure.
		const c = ctx({
			views: {
				period: {
					status: 'error',
					payload: { groups: [{ key: 'P1', amount: 42 }], total: 42 },
					error: 'boom',
				},
			},
		})
		expect(c.groupsFor('period')).toEqual([])
		expect(c.totalFor('period')).toBeNull()
		expect(c.backendFor('period')).toBe('')
	})

	it('surfaces the server’s own message rather than a paraphrase', () => {
		const c = ctx()
		expect(c.readError(httpError(500, 'Failed to compute spend analysis'))).toBe(
			'Failed to compute spend analysis',
		)
		// Falls back to the transport message when there is no envelope, so
		// the reader still gets a cause instead of a generic string.
		expect(c.readError(new Error('Network Error'))).toBe('Network Error')
	})
})

describe('SpendAnalyticsPanel — a measured empty result is its own state', () => {
	it('reports a 200 with no groups as "empty", never as "error"', () => {
		const c = ctx({
			views: {
				supplier: {
					status: 'ok',
					payload: { groups: [], total: 0, backend: 'postgres' },
					error: '',
				},
			},
		})
		expect(c.stateFor('supplier')).toBe('empty')
		expect(c.stateFor('supplier')).not.toBe('error')
	})

	it('reports a 200 with groups as "rows" and keeps the endpoint’s own total', () => {
		const c = ctx({
			views: { supplier: { status: 'ok', payload: SUPPLIER_OK, error: '' } },
		})
		expect(c.stateFor('supplier')).toBe('rows')
		// The endpoint's `total`, not a client-side sum of `groups`.
		expect(c.totalFor('supplier')).toBe(1350)
		expect(c.groupsFor('supplier')).toHaveLength(2)
		expect(c.backendFor('supplier')).toBe('postgres')
	})

	it('labels a null group key as unassigned rather than as an empty cell', () => {
		// OpenRegister returns null for rows whose group field is unset; an
		// empty cell would read as a missing label rather than as a real
		// bucket of money.
		const c = ctx()
		expect(c.keyLabel(null)).toBe('(unassigned)')
		expect(c.keyLabel('')).toBe('(unassigned)')
		expect(c.keyLabel('VND-001')).toBe('VND-001')
		expect(c.keyLabel(0)).toBe('0')
	})
})

describe('SpendAnalyticsPanel — administration context', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('no administration leaves the panel in its "none" state and dispatches no spend request', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { administrations: [], activeAdministrationId: null },
		})

		const c = ctx()
		await c.load.call(c)

		expect(c.contextState).toBe('none')
		// The load-bearing half: `administration_id` is required and a
		// non-member is masked as 404, so firing anyway would teach the reader
		// to read a 404 as "no data".
		const spendCalls = get.mock.calls.filter(([url]) =>
			String(url).includes('/analytics/spend'),
		)
		expect(spendCalls).toHaveLength(0)
	})

	it('falls back to the first membership when no active administration is set', async () => {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (String(url).includes('/administrations/context')) {
				return {
					data: {
						administrations: [
							{
								administrationId: 'ADM-042',
								name: 'Gemeente Testdorp',
							},
						],
						activeAdministrationId: null,
					},
				}
			}
			return { data: SUPPLIER_OK }
		})

		const c = ctx()
		await c.load.call(c)

		expect(c.contextState).toBe('ready')
		expect(c.administrationId).toBe('ADM-042')
		expect(c.administrationLabel).toBe('Gemeente Testdorp')
	})

	it('requests every dimension with the proven administration and the literal dimension value', async () => {
		const get = vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (String(url).includes('/administrations/context')) {
				return {
					data: {
						administrations: [
							{ administrationId: 'ADM-042', name: 'Testdorp' },
						],
						activeAdministrationId: 'ADM-042',
					},
				}
			}
			return { data: SUPPLIER_OK }
		})

		const c = ctx()
		await c.load.call(c)

		const spendCalls = get.mock.calls.filter(([url]) =>
			String(url).includes('/analytics/spend'),
		)
		expect(spendCalls).toHaveLength(4)
		expect(spendCalls.map(([, opts]) => opts.params.dimension).sort()).toEqual([
			'category',
			'costCentre',
			'period',
			'supplier',
		])
		for (const [, opts] of spendCalls) {
			// snake_case: the controller reads `administration_id`, and a
			// camelCase key would be an empty string and earn a 400.
			expect(opts.params.administration_id).toBe('ADM-042')
		}
	})

	it('one failing dimension does not suppress the three that answered', async () => {
		vi.spyOn(axios, 'get').mockImplementation(async (url, opts) => {
			if (String(url).includes('/administrations/context')) {
				return {
					data: {
						administrations: [
							{ administrationId: 'ADM-042', name: 'Testdorp' },
						],
						activeAdministrationId: 'ADM-042',
					},
				}
			}
			if (opts.params.dimension === 'supplier') {
				return { data: SUPPLIER_OK }
			}
			throw httpError(500, 'Failed to compute spend analysis')
		})

		const c = ctx()
		await c.load.call(c)

		expect(c.stateFor('supplier')).toBe('rows')
		expect(c.totalFor('supplier')).toBe(1350)
		for (const dimension of ['category', 'costCentre', 'period']) {
			expect(c.stateFor(dimension)).toBe('error')
			expect(c.errorFor(dimension)).toBe('Failed to compute spend analysis')
			expect(c.totalFor(dimension)).toBeNull()
		}
	})

	it('a failed context read is an error state, not an empty one, and dispatches no spend request', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockRejectedValue(httpError(500, 'Authorization failure'))

		const c = ctx()
		await c.load.call(c)

		expect(c.contextState).toBe('error')
		expect(c.contextError).toBe('Authorization failure')
		expect(
			get.mock.calls.filter(([url]) =>
				String(url).includes('/analytics/spend'),
			),
		).toHaveLength(0)
	})
})
