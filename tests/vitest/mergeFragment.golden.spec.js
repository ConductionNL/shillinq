/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the lazy-fragment merge over generated manifests and
 * fragments: the counts returned, the pages after the merge, whether each
 * shell page kept its identity, and the page-to-fragment index.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import { describe, it } from 'vitest'
import {
	buildPageFragmentIndex,
	mergeFullFragmentIntoManifest,
} from '../../src/utils/mergeFragmentIntoManifest.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('mergeFragment')
const IDS = ['A', 'B', 'C', 'D', 7, '7', null]

/**
 * A generated page, or something that is not one.
 *
 * @param {object} r Random source.
 * @return {*}
 */
function page(r) {
	if (r.next() < 0.1) return r.pick([null, undefined, 0, 'page'])
	const p = { id: r.pick(IDS), route: `/${r.int(5)}` }
	if (r.next() > 0.4) p.config = { widgets: r.int(3) }
	if (r.next() > 0.5) p._fragment = r.pick(['frag-a', 'frag-b', 3, null])
	return p
}

describe('merge fragment golden', () => {
	it('matches the record', () => {
		const r = seeded(5)
		for (let i = 0; i < 200; i++) {
			const shell = Array.from({ length: r.int(5) }, () => page(r))
			const manifest =
				r.next() < 0.1
					? r.pick([null, {}, { pages: 'x' }])
					: { pages: shell }
			const fragment =
				r.next() < 0.1
					? r.pick([null, {}, { pages: {} }])
					: { pages: Array.from({ length: r.int(5) }, () => page(r)) }
			const before = Array.isArray(manifest?.pages) ? [...manifest.pages] : []
			const result = outcome(() =>
				mergeFullFragmentIntoManifest(manifest, fragment),
			)
			const kept = before.map((p, j) => manifest.pages[j] === p)
			const shared = Array.isArray(manifest?.pages)
				? manifest.pages.map(
						(p) => (fragment?.pages || []).includes?.(p) ?? false,
					)
				: []
			check(`${i}`, { result, manifest, kept, shared })
			check(
				`${i} index`,
				outcome(() =>
					buildPageFragmentIndex(
						r.next() < 0.1 ? r.pick([null, 'x', {}]) : shell,
					),
				),
			)
		}
	})
})
