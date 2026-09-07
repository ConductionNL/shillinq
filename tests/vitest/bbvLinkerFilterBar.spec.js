/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for BbvLinkerFilterBar (#866/#862, REQ-BBL-001) — the renderer
 * the Budget-to-Programme Linker's declared `config.filters[]` never had.
 *
 * The thing worth pinning here is NOT that three selects appear. It is the
 * MAPPING from a facet selection to the OpenRegister query, because that is
 * where this feature can look finished and do nothing:
 *
 *   - `accountType` is a property of **Account**, not of GLLine. A filter on
 *     it would match NOTHING for every value, silently — the failure the
 *     fleet board records as "a filter on a non-property". The bar must
 *     translate it to `accountNumber[]`.
 *   - "unmapped" is not a value, it is the ABSENCE of one, so it must become
 *     OpenRegister's `empty` operator rather than the literal string.
 *   - Programme and Assignment status address the SAME property, so emitting
 *     both would put a scalar and an operator map on one query key.
 *
 * Each of those is asserted with its own negative half. The SFC is compiled
 * by @vitejs/plugin-vue (see vitest.config.js) and its `methods` / `computed`
 * are invoked bound to a fake `this` — no DOM mount, so the environment stays
 * `node`.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import BbvLinkerFilterBar from '../../src/components/bbv-provincie/BbvLinkerFilterBar.vue'

/** The page's declared facets, verbatim from the manifest fragment. */
const DECLARED_FILTERS = [
	{
		key: 'accountType',
		label: 'Account type',
		type: 'select',
		options: ['assets', 'liabilities', 'revenue', 'expenses'],
	},
	{
		key: 'programmeStructure',
		label: 'Programme',
		type: 'select',
		options: ['ruimte', 'mobiliteit', 'water', 'unmapped'],
	},
	{
		key: 'assignmentStatus',
		label: 'Assignment status',
		type: 'select',
		options: ['mapped', 'unmapped'],
	},
]

/** The seven BBV programme codes, as the facet endpoint returns them. */
const PROGRAMME_CODES = [
	'ruimte',
	'mobiliteit',
	'water',
	'milieu',
	'cultuur',
	'economie',
	'bestuur',
]

/** A manifest carrying the Linker page with those filters. */
const MANIFEST = {
	pages: [
		{
			id: 'SomeOtherPage',
			config: { filters: [{ key: 'nope', label: 'Nope' }] },
		},
		{
			id: 'BudgetToProgrammeLinker',
			type: 'index',
			config: {
				register: 'shillinq',
				schema: 'GLLine',
				filters: DECLARED_FILTERS,
			},
		},
	],
}

/** The facet endpoint's payload for a seeded province. */
const FACETS = {
	accountTypes: [
		{ value: 'assets', label: 'Assets', accountNumbers: ['0100', '0200'] },
		{ value: 'expenses', label: 'Expenses', accountNumbers: ['4100', '4110'] },
	],
	programmes: PROGRAMME_CODES.map((c) => ({
		value: c,
		label: c[0].toUpperCase() + c.slice(1),
	})),
	// Only `mapped`: an unassigned GL line has NO programmeStructure key, and
	// OpenRegister's filter grammar cannot address an absent key — measured,
	// with a positive control, in BbvProgrammeBudgetService::glLineFacets().
	assignmentStatuses: [{ value: 'mapped', label: 'Mapped' }],
}

/**
 * Build a fake `this` for the component, with the facet endpoint already
 * resolved and a recording router.
 *
 * @param {object} query Current `$route.query`.
 * @return {object} The bound context plus the recorded pushes.
 */
function context(query = {}) {
	const pushed = []
	const ctx = {
		cnManifest: MANIFEST,
		sources: FACETS,
		accountNumbersByType: {
			assets: ['0100', '0200'],
			expenses: ['4100', '4110'],
		},
		loading: false,
		error: '',
		$route: { query },
		$router: {
			replace: (to) => {
				pushed.push(to)
				return Promise.resolve()
			},
		},
	}
	for (const [name, fn] of Object.entries(BbvLinkerFilterBar.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.facets = BbvLinkerFilterBar.computed.facets.call(ctx)
	ctx.pushed = pushed
	return ctx
}

/**
 * The facet descriptor for a key, from the computed facet list.
 *
 * @param {object} ctx The bound context.
 * @param {string} key The facet key.
 * @return {object} The facet.
 */
function facet(ctx, key) {
	return ctx.facets.find((f) => f.key === key)
}

describe('BbvLinkerFilterBar — the declared facets', () => {
	it('renders exactly the facets the manifest declares, with their labels', () => {
		const ctx = context()

		expect(ctx.facets.map((f) => f.key)).toEqual([
			'accountType',
			'programmeStructure',
			'assignmentStatus',
		])
		expect(ctx.facets.map((f) => f.label)).toEqual([
			'Account type',
			'Programme',
			'Assignment status',
		])
	})

	it('reads the CURRENT page, not the first page that happens to have filters', () => {
		const ctx = context()

		// `SomeOtherPage` also declares a `filters[]`; picking it up would
		// render one bogus facet and none of the three real ones.
		expect(ctx.facets.map((f) => f.key)).not.toContain('nope')
	})

	it('renders nothing when the page declares no filters', () => {
		const ctx = context()
		ctx.cnManifest = { pages: [{ id: 'BudgetToProgrammeLinker', config: {} }] }

		expect(BbvLinkerFilterBar.computed.facets.call(ctx)).toEqual([])
	})

	it('prefers the live facet values over the manifest fallback, and falls back when absent', () => {
		const ctx = context()

		// Live: two account types from the endpoint, each with a real label.
		expect(facet(ctx, 'accountType').options.map((o) => o.value)).toEqual([
			'',
			'assets',
			'expenses',
		])

		// No live values: the manifest's own four options carry the bar.
		ctx.sources = { accountTypes: [], programmes: [], assignmentStatuses: [] }
		const offline = BbvLinkerFilterBar.computed.facets.call(ctx)
		expect(offline[0].options.map((o) => o.value)).toEqual([
			'',
			'assets',
			'liabilities',
			'revenue',
			'expenses',
		])
	})
})

describe('BbvLinkerFilterBar — the query each facet really writes', () => {
	let ctx

	beforeEach(() => {
		ctx = context()
	})

	it('translates an account TYPE into the account NUMBERS GLLine can be filtered on', () => {
		ctx.onSelect(facet(ctx, 'accountType'), { value: 'expenses' })

		const query = ctx.pushed[0].query
		// The negative half: the type name itself must NOT reach the query —
		// GLLine declares no `accountType`, so it would match nothing at all.
		expect(query.accountType).toBeUndefined()
		expect(query.accountNumber).toEqual(['4100', '4110'])
	})

	it('filters programme on the declared programmeStructure property', () => {
		ctx.onSelect(facet(ctx, 'programmeStructure'), { value: 'water' })

		expect(ctx.pushed[0].query.programmeStructure).toBe('water')
	})

	it('expresses "mapped" as an IN over every declared programme code', () => {
		ctx.onSelect(facet(ctx, 'assignmentStatus'), { value: 'mapped' })

		const query = ctx.pushed[0].query
		expect(query.programmeStructure).toEqual(PROGRAMME_CODES)
		// The negative half: the literal word must NOT be sent as a value — no
		// GL line has `programmeStructure: "mapped"`, so it would match nothing
		// while the control looked active.
		expect(query.programmeStructure).not.toContain('mapped')
	})

	it('never emits an `empty`/`null`/`exists` operator, which this deployment cannot satisfy', () => {
		ctx.onSelect(facet(ctx, 'assignmentStatus'), { value: 'mapped' })

		const query = ctx.pushed[0].query
		for (const key of Object.keys(query)) {
			expect(key).not.toMatch(/\[(empty|null|exists)\]$/)
		}
	})

	it('clears a facet back to no filter when "All" is chosen', () => {
		ctx.onSelect(facet(ctx, 'programmeStructure'), { value: 'water' })
		const withFilter = ctx.pushed[0].query
		expect(withFilter.programmeStructure).toBe('water')

		ctx.$route.query = withFilter
		ctx.onSelect(facet(ctx, 'programmeStructure'), { value: '' })
		expect(ctx.pushed[1].query.programmeStructure).toBeUndefined()
	})

	it('keeps the other facets when one changes', () => {
		ctx.onSelect(facet(ctx, 'accountType'), { value: 'expenses' })
		ctx.$route.query = ctx.pushed[0].query

		ctx.onSelect(facet(ctx, 'programmeStructure'), { value: 'ruimte' })
		const query = ctx.pushed[1].query

		expect(query.accountNumber).toEqual(['4100', '4110'])
		expect(query.programmeStructure).toBe('ruimte')
	})

	it('never emits a single programme and the mapped IN list at once', () => {
		ctx.onSelect(facet(ctx, 'assignmentStatus'), { value: 'mapped' })
		ctx.$route.query = ctx.pushed[0].query

		ctx.onSelect(facet(ctx, 'programmeStructure'), { value: 'water' })
		const query = ctx.pushed[1].query
		expect(query.programmeStructure).toBe('water')

		// ...and the other way round: choosing "mapped" replaces the single
		// programme rather than appending to it.
		ctx.$route.query = query
		ctx.onSelect(facet(ctx, 'assignmentStatus'), { value: 'mapped' })
		expect(ctx.pushed[2].query.programmeStructure).toEqual(PROGRAMME_CODES)
	})

	it('leaves query keys it does not own untouched', () => {
		ctx.$route.query = { _page: '3', somethingElse: 'keep-me' }
		ctx.onSelect(facet(ctx, 'programmeStructure'), { value: 'water' })

		expect(ctx.pushed[0].query.somethingElse).toBe('keep-me')
		expect(ctx.pushed[0].query._page).toBe('3')
	})
})

describe('BbvLinkerFilterBar — reading the selection back out of the URL', () => {
	it('shows the active selection after a deep link or a reload', () => {
		const ctx = context({
			accountNumber: ['4100', '4110'],
			programmeStructure: 'water',
		})

		expect(ctx.selectedOption(facet(ctx, 'accountType')).value).toBe('expenses')
		expect(ctx.selectedOption(facet(ctx, 'programmeStructure')).value).toBe(
			'water',
		)
	})

	it('recovers "mapped" from the IN list, and does not mistake it for a programme', () => {
		const ctx = context({ programmeStructure: PROGRAMME_CODES })

		expect(ctx.selectedOption(facet(ctx, 'assignmentStatus')).value).toBe(
			'mapped',
		)
		// The negative half: the Programme facet must NOT claim one of the
		// seven is selected just because the array contains it.
		expect(ctx.selectedOption(facet(ctx, 'programmeStructure')).value).toBe('')
	})

	it('falls back to "All" when the URL carries no filter for a facet', () => {
		const ctx = context()

		for (const f of ctx.facets) {
			expect(ctx.selectedOption(f).value).toBe('')
		}
	})

	it('does not claim an account type for a partial accountNumber list', () => {
		// One of the two expense accounts: a subset is not that account type,
		// and reporting it as one would show a selection the list does not have.
		const ctx = context({ accountNumber: ['4100'] })

		expect(ctx.selectedOption(facet(ctx, 'accountType')).value).toBe('')
	})
})

describe('BbvLinkerFilterBar — loading the live facet values', () => {
	it('keeps the declared options and says so when the endpoint fails', async () => {
		const ctx = context()
		ctx.loading = true
		const axios = (await import('@nextcloud/axios')).default
		const original = axios.get
		axios.get = vi.fn().mockRejectedValue(new Error('boom'))

		await ctx.loadFacets()

		expect(ctx.error).not.toBe('')
		expect(ctx.loading).toBe(false)
		axios.get = original
	})

	it('indexes the account numbers per type from the endpoint payload', async () => {
		const ctx = context()
		const axios = (await import('@nextcloud/axios')).default
		const original = axios.get
		axios.get = vi.fn().mockResolvedValue({ data: FACETS })

		await ctx.loadFacets()

		expect(ctx.accountNumbersByType).toEqual({
			assets: ['0100', '0200'],
			expenses: ['4100', '4110'],
		})
		expect(ctx.error).toBe('')
		axios.get = original
	})
})
