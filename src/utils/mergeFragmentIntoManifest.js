// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Pure merge logic backing the lazy-fragment router guard in src/main.js
// (shillinq-manifest-boot-payload-reduction REQ-MBP-001). At boot,
// `mergedManifest.pages` holds SLIM shell entries (id/route/type/title —
// see scripts/generate-manifest-shell.js) built from every
// `src/manifest.d/*.json` fragment, so no fragment's `config` (columns,
// widgets, forms, actions — the bulk of a fragment's bytes) ships in the
// `main` webpack chunk. When the router navigates into a page whose
// fragment has not been loaded yet, `main.js` dynamically imports that ONE
// fragment (webpack code-splits `require.context(..., 'lazy')` per file)
// and calls `mergeFullFragmentIntoManifest` to fill in the full page data.
//
// CnPageRenderer (`@conduction/nextcloud-vue`) resolves the current page via
// `pageById.get(routeName)` — a Map built from `manifest.pages` — and its
// `resolvedProps` computed reads `page.config` reactively.
//
// Under Vue 2 this needed `Vue.set()`: adding a brand-new key to an
// already-observed object was not detected (the "reactivity caveat"), and the
// slim shell page objects do NOT pre-declare `config`. Vue 3's `reactive()` is
// a Proxy, so a plain assignment on a reactive object triggers the `set` trap
// and is tracked whether or not the key existed — `Vue.set` is gone from Vue 3
// precisely because it has nothing left to do. Plain assignment is therefore
// the correct Vue 3 spelling AND keeps this function pure enough to run
// against plain objects in the unit tests.

/**
 * Merge one fully-loaded fragment's pages into the live, reactive manifest.
 * Every page already present as a slim shell entry (by `id`) is updated IN
 * PLACE — every own-enumerable key on the full page object is assigned onto
 * the existing slim object (Vue 3's reactive Proxy picks up a brand-new key
 * like `config` as well as a pre-existing one),
 * preserving that object's identity so `CnPageRenderer`'s `pageById` Map and
 * the router's route table (both built from the SAME object references)
 * stay valid. A full page with no matching slim entry (should not happen if
 * the shell generator and this fragment agree, but kept defensive — e.g. a
 * fragment edited without regenerating the shell) is appended as a new page.
 *
 * @param {object}        manifest         The live (`reactive()`-wrapped) merged manifest; `manifest.pages` is mutated.
 * @param {object}        fullFragment     The dynamically-imported fragment JSON (`{pages, menu, ...}`).
 * @param {Array<object>} fullFragment.pages Full page objects (with `config` etc.) from the fragment.
 * @return {{updated: number, appended: number}} How many pages were updated in place vs. newly appended.
 */
export function mergeFullFragmentIntoManifest(manifest, fullFragment) {
	const pages = Array.isArray(manifest && manifest.pages) ? manifest.pages : []
	const fullPages = Array.isArray(fullFragment && fullFragment.pages)
		? fullFragment.pages
		: []

	let updated = 0
	let appended = 0

	for (const fullPage of fullPages) {
		if (!fullPage || typeof fullPage.id !== 'string') {
			continue
		}

		const existing = pages.find((p) => p && p.id === fullPage.id)
		if (existing) {
			for (const key of Object.keys(fullPage)) {
				existing[key] = fullPage[key]
			}
			updated++
		} else {
			pages.push({ ...fullPage })
			appended++
		}
	}

	return { updated, appended }
}

/**
 * Build a `Map<pageId, fragmentStem>` from the shell document's slim pages
 * (each carrying `_fragment`), so the router guard can look up which lazy
 * chunk owns the page the user is navigating to. Pages with no `_fragment`
 * (i.e. from the bundled base `manifest.json`, which is never slimmed) are
 * omitted — they already carry full `config` after the initial merge and
 * never need a lazy load.
 *
 * @param {Array<object>} pages The merged manifest's `pages[]` (post-boot-merge, still carrying `_fragment` on slim entries).
 * @return {Map<string, string>} pageId → fragment filename stem.
 */
export function buildPageFragmentIndex(pages) {
	const index = new Map()
	if (!Array.isArray(pages)) {
		return index
	}

	for (const page of pages) {
		if (
			page
			&& typeof page.id === 'string'
			&& typeof page._fragment === 'string'
		) {
			index.set(page.id, page._fragment)
		}
	}

	return index
}
