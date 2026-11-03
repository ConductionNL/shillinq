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
 * @spec openspec/specs/manifest-boot-performance/spec.md
 */
export function mergeFullFragmentIntoManifest(manifest, fullFragment) {
	const pages = arrayOrEmpty(manifest && manifest.pages)
	const counts = { updated: 0, appended: 0 }
	for (const fullPage of arrayOrEmpty(fullFragment && fullFragment.pages)) {
		if (isPage(fullPage)) {
			counts[mergePage(pages, fullPage)]++
		}
	}
	return counts
}

/**
 * Update the shell page with the full page's id in place, or append a copy.
 *
 * @param {Array<object>} pages The manifest's pages.
 * @param {object} fullPage The full page.
 * @return {string} `updated` or `appended`.
 */
function mergePage(pages, fullPage) {
	const existing = pages.find((p) => p && p.id === fullPage.id)
	if (!existing) {
		pages.push({ ...fullPage })
		return 'appended'
	}
	for (const key of Object.keys(fullPage)) {
		existing[key] = fullPage[key]
	}
	return 'updated'
}

/**
 * The value when it is an array, else an empty array.
 *
 * @param {unknown} value The value.
 * @return {Array}
 */
function arrayOrEmpty(value) {
	return Array.isArray(value) ? value : []
}

/**
 * Whether a value is a page with a string id.
 *
 * @param {unknown} page The value.
 * @return {boolean}
 */
function isPage(page) {
	return Boolean(page) && typeof page.id === 'string'
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
 * @spec openspec/specs/manifest-boot-performance/spec.md
 */
export function buildPageFragmentIndex(pages) {
	const index = new Map()
	for (const page of arrayOrEmpty(pages)) {
		if (isPage(page) && typeof page._fragment === 'string') {
			index.set(page.id, page._fragment)
		}
	}
	return index
}
