/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the lazy-fragment merge logic backing
 * shillinq-manifest-boot-payload-reduction REQ-MBP-001's router guard
 * (src/main.js). Covers the in-place merge contract CnPageRenderer's
 * reactivity depends on (same object reference, own-key copy) and the
 * pageId → fragment index the router guard uses to decide what to lazy-load.
 *
 * @spec openspec/changes/shillinq-manifest-boot-payload-reduction/specs/manifest-boot-performance/spec.md#req-mbp-001
 */

import { describe, expect, it } from 'vitest'
import { computed, reactive, toRaw } from 'vue'
import {
	buildPageFragmentIndex,
	mergeFullFragmentIntoManifest,
} from '../../src/utils/mergeFragmentIntoManifest.js'

describe('mergeFullFragmentIntoManifest — reactivity (the load-bearing contract)', () => {
	it('a computed reading page.config on a reactive() manifest re-evaluates after the lazy merge adds it', () => {
		// This reproduces CnPageRenderer's actual dependency shape:
		// `resolvedProps` is a computed that reads `this.currentPage.config`,
		// where `currentPage` comes from a `pageById` Map built over
		// `manifest.pages`. `config` does NOT exist on the slim shell page, so
		// this asserts the merge ADDS a brand-new key in a way the reactivity
		// system tracks. Under Vue 2 that required `Vue.set`; under Vue 3 the
		// `reactive()` Proxy traps the plain assignment — but only if the merge
		// writes THROUGH the proxy rather than to a raw reference. If it did
		// not, this computed would keep returning `undefined` forever.
		const manifest = reactive({
			pages: [
				{
					id: 'Resources',
					route: '/bookings/resources',
					type: 'index',
					title: 'Resources',
					_fragment: '10-bookings-resource-calendar',
				},
			],
		})

		const configColumns = computed(() => {
			const page = manifest.pages.find((p) => p.id === 'Resources')
			return page && page.config && page.config.columns
		})

		expect(configColumns.value).toBeUndefined()

		mergeFullFragmentIntoManifest(manifest, {
			pages: [
				{
					id: 'Resources',
					route: '/bookings/resources',
					type: 'index',
					title: 'Resources',
					config: { columns: [{ key: 'name' }] },
				},
			],
		})

		// A Vue 3 computed re-runs lazily on next access once its reactive
		// dependency was invalidated — no nextTick needed, we read the value
		// directly rather than asserting on a DOM re-render.
		expect(configColumns.value).toEqual([{ key: 'name' }])
	})

	it('object identity is preserved across the merge (router/pageById Map references stay valid)', () => {
		const slimPage = { id: 'Resources', _fragment: 'frag' }
		const manifest = reactive({ pages: [slimPage] })

		mergeFullFragmentIntoManifest(manifest, {
			pages: [{ id: 'Resources', config: { x: 1 } }],
		})

		// `manifest.pages[0]` reads back as the reactive PROXY of slimPage, not
		// slimPage itself — `toBe(slimPage)` would fail for a reason that has
		// nothing to do with the merge. `toRaw` unwraps it, which is what the
		// contract actually means: the merge must not REPLACE the object.
		expect(toRaw(manifest.pages[0])).toBe(slimPage)
		expect(slimPage.config).toEqual({ x: 1 })
	})
})

describe('mergeFullFragmentIntoManifest', () => {
	it('updates a matching slim page IN PLACE (same object reference)', () => {
		const slimPage = {
			id: 'Resources',
			route: '/bookings/resources',
			type: 'index',
			title: 'Resources',
			_fragment: '10-bookings-resource-calendar',
		}
		const manifest = { pages: [slimPage] }

		const fullFragment = {
			pages: [
				{
					id: 'Resources',
					route: '/bookings/resources',
					type: 'index',
					title: 'Resources',
					config: {
						register: 'shillinq',
						schema: 'Resource',
						columns: [{ key: 'name' }],
					},
				},
			],
		}

		const result = mergeFullFragmentIntoManifest(manifest, fullFragment)

		expect(result).toEqual({ updated: 1, appended: 0 })
		// Same reference — this is the load-bearing reactivity contract.
		expect(manifest.pages[0]).toBe(slimPage)
		expect(manifest.pages[0].config).toEqual({
			register: 'shillinq',
			schema: 'Resource',
			columns: [{ key: 'name' }],
		})
	})

	it('appends a page with no matching slim entry (defensive: shell/fragment drift)', () => {
		const manifest = { pages: [] }
		const fullFragment = {
			pages: [
				{
					id: 'Orphan',
					route: '/orphan',
					type: 'index',
					title: 'Orphan',
					config: {},
				},
			],
		}

		const result = mergeFullFragmentIntoManifest(manifest, fullFragment)

		expect(result).toEqual({ updated: 0, appended: 1 })
		expect(manifest.pages).toHaveLength(1)
		expect(manifest.pages[0].id).toBe('Orphan')
	})

	it('merges every page in a multi-page fragment', () => {
		const pageA = { id: 'A', _fragment: 'frag' }
		const pageB = { id: 'B', _fragment: 'frag' }
		const manifest = { pages: [pageA, pageB] }
		const fullFragment = {
			pages: [
				{ id: 'A', config: { x: 1 } },
				{ id: 'B', config: { x: 2 } },
			],
		}

		const result = mergeFullFragmentIntoManifest(manifest, fullFragment)

		expect(result).toEqual({ updated: 2, appended: 0 })
		expect(manifest.pages[0].config).toEqual({ x: 1 })
		expect(manifest.pages[1].config).toEqual({ x: 2 })
	})

	it('is a no-op on an empty/missing fragment pages array', () => {
		const manifest = { pages: [{ id: 'A', _fragment: 'frag' }] }

		expect(mergeFullFragmentIntoManifest(manifest, {})).toEqual({
			updated: 0,
			appended: 0,
		})
		expect(mergeFullFragmentIntoManifest(manifest, { pages: [] })).toEqual({
			updated: 0,
			appended: 0,
		})
	})

	it('tolerates a missing/non-array manifest.pages', () => {
		expect(
			mergeFullFragmentIntoManifest({}, { pages: [{ id: 'A', config: {} }] }),
		).toEqual({ updated: 0, appended: 1 })
	})

	it('skips malformed full-page entries without an id', () => {
		const manifest = { pages: [{ id: 'A', _fragment: 'frag' }] }
		const result = mergeFullFragmentIntoManifest(manifest, {
			pages: [{ config: {} }, null],
		})

		expect(result).toEqual({ updated: 0, appended: 0 })
		expect(manifest.pages).toHaveLength(1)
	})
})

describe('buildPageFragmentIndex', () => {
	it('maps page id to its owning fragment stem for slim (shell) pages', () => {
		const pages = [
			{ id: 'Resources', _fragment: '10-bookings-resource-calendar' },
			{ id: 'Calendars', _fragment: '10-bookings-resource-calendar' },
			{ id: 'Dashboard', route: '/' }, // base manifest page — no _fragment.
		]

		const index = buildPageFragmentIndex(pages)

		expect(index.get('Resources')).toBe('10-bookings-resource-calendar')
		expect(index.get('Calendars')).toBe('10-bookings-resource-calendar')
		expect(index.has('Dashboard')).toBe(false)
	})

	it('returns an empty map for a missing/non-array input', () => {
		expect(buildPageFragmentIndex(undefined).size).toBe(0)
		expect(buildPageFragmentIndex(null).size).toBe(0)
	})
})
