/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the lazy-fragment merge logic backing
 * shillinq-manifest-boot-payload-reduction REQ-MBP-001's router guard
 * (src/main.js). Covers the in-place merge contract CnPageRenderer's Vue 2
 * reactivity depends on (same object reference, own-key copy) and the
 * pageId → fragment index the router guard uses to decide what to lazy-load.
 *
 * @spec openspec/changes/shillinq-manifest-boot-payload-reduction/specs/manifest-boot-performance/spec.md#req-mbp-001
 */

import { describe, it, expect } from 'vitest'
import Vue from 'vue'
import {
	mergeFullFragmentIntoManifest,
	buildPageFragmentIndex,
} from '../../src/utils/mergeFragmentIntoManifest.js'

describe('mergeFullFragmentIntoManifest — Vue 2 reactivity (the load-bearing contract)', () => {
	it('a computed reading page.config on a Vue.observable() manifest re-evaluates after the lazy merge adds it', () => {
		// This reproduces CnPageRenderer's actual dependency shape:
		// `resolvedProps` is a computed that reads `this.currentPage.config`,
		// where `currentPage` comes from a `pageById` Map built over
		// `manifest.pages` (see node_modules/@conduction/nextcloud-vue/src/
		// components/CnPageRenderer/CnPageRenderer.vue). If merging the lazy
		// fragment did not correctly notify Vue's reactivity system, this
		// computed would keep returning `undefined` forever.
		const manifest = Vue.observable({
			pages: [{ id: 'Resources', route: '/bookings/resources', type: 'index', title: 'Resources', _fragment: '10-bookings-resource-calendar' }],
		})

		const vm = new Vue({
			computed: {
				configColumns() {
					const page = manifest.pages.find((p) => p.id === 'Resources')
					return page && page.config && page.config.columns
				},
			},
		})

		expect(vm.configColumns).toBeUndefined()

		mergeFullFragmentIntoManifest(manifest, {
			pages: [{ id: 'Resources', route: '/bookings/resources', type: 'index', title: 'Resources', config: { columns: [{ key: 'name' }] } }],
		})

		// Vue 2 computed getters re-run lazily on next access once their
		// reactive dependency was notified — no need for nextTick here since
		// we are reading the computed's current value directly, not asserting
		// on a DOM re-render.
		expect(vm.configColumns).toEqual([{ key: 'name' }])
	})

	it('object identity is preserved across the merge (router/pageById Map references stay valid)', () => {
		const slimPage = { id: 'Resources', _fragment: 'frag' }
		const manifest = Vue.observable({ pages: [slimPage] })

		mergeFullFragmentIntoManifest(manifest, { pages: [{ id: 'Resources', config: { x: 1 } }] })

		expect(manifest.pages[0]).toBe(slimPage)
	})
})

describe('mergeFullFragmentIntoManifest', () => {
	it('updates a matching slim page IN PLACE (same object reference)', () => {
		const slimPage = { id: 'Resources', route: '/bookings/resources', type: 'index', title: 'Resources', _fragment: '10-bookings-resource-calendar' }
		const manifest = { pages: [slimPage] }

		const fullFragment = {
			pages: [
				{ id: 'Resources', route: '/bookings/resources', type: 'index', title: 'Resources', config: { register: 'shillinq', schema: 'Resource', columns: [{ key: 'name' }] } },
			],
		}

		const result = mergeFullFragmentIntoManifest(manifest, fullFragment)

		expect(result).toEqual({ updated: 1, appended: 0 })
		// Same reference — this is the load-bearing reactivity contract.
		expect(manifest.pages[0]).toBe(slimPage)
		expect(manifest.pages[0].config).toEqual({ register: 'shillinq', schema: 'Resource', columns: [{ key: 'name' }] })
	})

	it('appends a page with no matching slim entry (defensive: shell/fragment drift)', () => {
		const manifest = { pages: [] }
		const fullFragment = { pages: [{ id: 'Orphan', route: '/orphan', type: 'index', title: 'Orphan', config: {} }] }

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

		expect(mergeFullFragmentIntoManifest(manifest, {})).toEqual({ updated: 0, appended: 0 })
		expect(mergeFullFragmentIntoManifest(manifest, { pages: [] })).toEqual({ updated: 0, appended: 0 })
	})

	it('tolerates a missing/non-array manifest.pages', () => {
		expect(mergeFullFragmentIntoManifest({}, { pages: [{ id: 'A', config: {} }] })).toEqual({ updated: 0, appended: 1 })
	})

	it('skips malformed full-page entries without an id', () => {
		const manifest = { pages: [{ id: 'A', _fragment: 'frag' }] }
		const result = mergeFullFragmentIntoManifest(manifest, { pages: [{ config: {} }, null] })

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
