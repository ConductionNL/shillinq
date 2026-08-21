#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-nav-reachability.js — nav-reachability-gate REQ-NAVR-001..006.
//
// Builds shillinq's EFFECTIVE manifest via the real, shared
// `buildManifest(base, fragments, menuLayout)` pipeline from
// @conduction/nextcloud-vue (never a re-implementation of merge/relocation/
// removal/settings-promotion semantics — ADR-044 decision 1), computes which
// pages are reachable from some surviving nav entry under the relation
// defined in openspec/changes/nav-reachability-gate/design.md §2, and fails
// the build on any orphaned page id that is NOT already listed in
// tests/nav-reachability-baseline.json's reason-bearing ratchet allow-list.
//
// This complements hydra's fleet-wide gate 30
// (hydra-gate-effective-manifest-crossref), which only checks ids literally
// present in menu-layout.json#removals. This gate checks EVERY page in the
// effective manifest, so it also catches a menu node deleted or re-homed
// directly in a fragment's menu[] without ever touching removals[] — the
// likely shape of a wholesale nav restructure such as nav-six-clusters.
//
// Usage:
//   node tests/validate-nav-reachability.js
//
// Exit codes:
//   0 — no NEW orphans (baselined orphans and stale-baseline warnings do not fail)
//   1 — one or more NEW orphans found, OR the real buildManifest pipeline
//       cannot be resolved (fail closed — see resolveBuildManifestModule())

'use strict'

const fs = require('fs')
const path = require('path')
const { pathToFileURL } = require('url')

const REPO_ROOT = path.resolve(__dirname, '..')
const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')
const MANIFEST_D_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')
const MENU_LAYOUT_PATH = path.join(REPO_ROOT, 'src', 'menu-layout.json')
const BASELINE_PATH = path.join(REPO_ROOT, 'tests', 'nav-reachability-baseline.json')

// Candidate locations for the real @conduction/nextcloud-vue buildManifest
// pipeline, checked in order — mirrors validate-manifest.js's candidate-list
// pattern for the app-manifest schema. No structural-lint / re-implemented
// fallback exists for this gate: a re-implemented merge/reachability pass IS
// the thing design.md §1 refuses to ship.
const BUILD_MANIFEST_CANDIDATES = [
	path.join(
		REPO_ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'utils',
		'buildManifest.js',
	),
	path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'utils', 'buildManifest.js'),
]

/**
 * Resolve the real buildManifest.js module via dynamic import(), trying each
 * candidate path in order. Fails closed (returns null) if none resolve — the
 * caller MUST exit non-zero rather than fall back to any approximation.
 *
 * @return {Promise<{modulePath: string, mod: object}|null>} The resolved
 *   module and the path it was loaded from, or null if none resolved.
 */
async function resolveBuildManifestModule() {
	for (const candidate of BUILD_MANIFEST_CANDIDATES) {
		if (!fs.existsSync(candidate)) continue
		try {
			const mod = await import(pathToFileURL(candidate).href)
			return { modulePath: candidate, mod }
		} catch (err) {
			console.error(
				`[check:nav-reachability] found ${candidate} but failed to import it: ${err.message}`,
			)
			return null
		}
	}
	return null
}

/**
 * Load the manifest inputs this app feeds to buildManifest(): the bundled
 * base manifest, every src/manifest.d/*.json fragment (sorted by filename,
 * matching require.context(...).keys().sort() semantics — the same order
 * scripts/generate-manifest-shell.js already uses), and menu-layout.json.
 * `src/manifest.d/README.md` exists and is NOT a fragment; filtering by
 * `.endsWith('.json')` excludes it, matching check-manifest-budget.js /
 * generate-manifest-shell.js's own guard against the same trap.
 *
 * @return {{base: object, fragments: Array<object>, menuLayout: object}}
 */
function loadManifestInputs() {
	const base = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
	const fragmentFiles = fs
		.readdirSync(MANIFEST_D_DIR)
		.filter((name) => name.endsWith('.json'))
		.sort()
	const fragments = fragmentFiles.map((name) =>
		JSON.parse(fs.readFileSync(path.join(MANIFEST_D_DIR, name), 'utf8')),
	)
	const menuLayout = JSON.parse(fs.readFileSync(MENU_LAYOUT_PATH, 'utf8'))
	return { base, fragments, menuLayout }
}

// Page config fields that link one page to another, per design.md §2. A page
// P being reachable makes the page named by P.config[field] reachable too,
// for every field in this list. Deliberately does NOT include OpenRegister's
// runtime related-object widgets (query-time, not a static manifest edge —
// design.md §2 explicit non-goal) or config.createDialog (declared on some
// index pages but not currently consumed by any renderer — see the baseline
// reasons for the four *CreateDialog pages this omission affects).
const LINK_FIELDS = [
	'indexRoute',
	'detailRoute',
	'rowRoute',
	'clickRoute',
	'viewAllRoute',
]

/**
 * Compute the reachable-page-id set for an effective manifest shape
 * `{ pages, menu }`, per design.md §2: every page whose id is named by a
 * `route` on some menu[] node (at any depth) is reachable (the base case);
 * then the transitive closure of LINK_FIELDS is added until no further page
 * becomes reachable (the inductive case). `href`/`action` menu nodes (no
 * `route`) are not page links and are skipped.
 *
 * @param {{pages: Array<object>, menu: Array<object>}} manifest The effective manifest.
 * @return {{reachable: Set<string>, orphans: Array<string>}} The reachable
 *   set and the sorted list of page ids NOT in it.
 */
function computeReachable(manifest) {
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	const menu = Array.isArray(manifest.menu) ? manifest.menu : []
	const pageIds = new Set(pages.map((p) => p.id))
	const reachable = new Set()

	;(function collectMenuRoutes(nodes) {
		for (const n of nodes) {
			if (n.route && pageIds.has(n.route)) reachable.add(n.route)
			if (Array.isArray(n.children)) collectMenuRoutes(n.children)
		}
	})(menu)

	let changed = true
	while (changed) {
		changed = false
		for (const page of pages) {
			if (!reachable.has(page.id)) continue
			const cfg = page.config || {}
			for (const field of LINK_FIELDS) {
				const target = cfg[field]
				if (target && pageIds.has(target) && !reachable.has(target)) {
					reachable.add(target)
					changed = true
				}
			}
		}
	}

	const orphans = [...pageIds].filter((id) => !reachable.has(id)).sort()
	return { reachable, orphans }
}

/**
 * Deep-clone a plain JSON-shaped value (menu arrays only carry JSON-safe
 * data), so each replay stage below mutates its own copy — applyMenuRelocations
 * / applyMenuRemovals / applySettingsSection all mutate their input in place.
 *
 * @param {*} value Any JSON-serialisable value.
 * @return {*} A deep clone.
 */
function clone(value) {
	return JSON.parse(JSON.stringify(value))
}

/**
 * Replay the four menu-layout stages one at a time (mergeMenuItems ->
 * applyMenuRelocations -> applyMenuRemovals -> applySettingsSection), per
 * design.md §5, returning the reachable set computed against `pages` at each
 * intermediate stage. Reusable against any real or synthetic
 * `{ base, fragments, menuLayout }` input, using ONLY the real exported
 * pipeline functions passed in via `stageFns` — never a re-implementation.
 *
 * @param {object} base Bundled base manifest (`{ menu, pages, ... }`).
 * @param {Array<object>} fragments Fragment objects (each may carry `pages`/`menu`).
 * @param {object} menuLayout `{ relocations?, removals?, settingsSection? }`.
 * @param {{mergeMenuItems: Function, applyMenuRelocations: Function, applyMenuRemovals: Function, applySettingsSection: Function}} stageFns
 *   The real exported stage functions from @conduction/nextcloud-vue's buildManifest.js.
 * @return {{pages: Array<object>, stages: {preLayout: object, afterRelocations: object, afterRemovals: object, afterSettings: object}}}
 *   `pages` is the merged page list (base + every fragment's pages, later
 *   wins by id); each `stages.*` entry is `{ reachable, orphans }` from
 *   computeReachable() against that stage's menu.
 */
function replayStages(base, fragments, menuLayout, stageFns) {
	const {
		mergeMenuItems,
		applyMenuRelocations,
		applyMenuRemovals,
		applySettingsSection,
	} = stageFns

	// Merge pages the same way buildManifest() does: base pages, then each
	// fragment's pages overlay by id (later wins).
	const pages = [...(base.pages || [])]
	for (const frag of Array.isArray(fragments) ? fragments : []) {
		if (frag && Array.isArray(frag.pages)) {
			for (const page of frag.pages) {
				const idx = pages.findIndex((p) => p.id === page.id)
				if (idx === -1) pages.push(page)
				else pages[idx] = page
			}
		}
	}

	const preLayoutMenu = []
	mergeMenuItems(preLayoutMenu, base.menu || [])
	for (const frag of Array.isArray(fragments) ? fragments : []) {
		if (frag && Array.isArray(frag.menu))
			mergeMenuItems(preLayoutMenu, frag.menu)
	}

	const afterRelocationsMenu = applyMenuRelocations(
		clone(preLayoutMenu),
		(menuLayout || {}).relocations,
	)
	const afterRemovalsMenu = applyMenuRemovals(
		clone(afterRelocationsMenu),
		(menuLayout || {}).removals,
	)
	const afterSettingsMenu = applySettingsSection(
		clone(afterRemovalsMenu),
		(menuLayout || {}).settingsSection,
	)

	const stages = {
		preLayout: computeReachable({ pages, menu: preLayoutMenu }),
		afterRelocations: computeReachable({ pages, menu: afterRelocationsMenu }),
		afterRemovals: computeReachable({ pages, menu: afterRemovalsMenu }),
		afterSettings: computeReachable({ pages, menu: afterSettingsMenu }),
	}

	return { pages, stages }
}

/**
 * For a single orphaned page id, find the earliest replay stage (per
 * design.md §5) at which it drops out of the reachable set, and return the
 * attributed cause. Exactly one of the four strings below is returned.
 *
 * @param {string} pageId The orphaned page id to attribute.
 * @param {{preLayout: object, afterRelocations: object, afterRemovals: object, afterSettings: object}} stages
 *   The four `{ reachable, orphans }` results from replayStages().
 * @return {string} One of: 'no menu entry in any base/fragment menu[]',
 *   'relocations', 'removals', 'settingsSection'.
 */
function attributeCause(pageId, stages) {
	if (!stages.preLayout.reachable.has(pageId)) {
		return 'no menu entry in any base/fragment menu[]'
	}
	if (!stages.afterRelocations.reachable.has(pageId)) {
		return 'relocations'
	}
	if (!stages.afterRemovals.reachable.has(pageId)) {
		return 'removals'
	}
	if (!stages.afterSettings.reachable.has(pageId)) {
		return 'settingsSection'
	}
	// Reachable at every replay stage — should not happen for a page the
	// caller already determined is orphaned in the final manifest, since
	// afterSettings mirrors buildManifest()'s own returned menu. Surfaced
	// rather than silently mis-attributed.
	return 'unknown (reachable at every replay stage; inconsistent with buildManifest() output)'
}

/**
 * Load the ratchet baseline and diff it against the computed orphan list,
 * per design.md §4 / REQ-NAVR-003.
 *
 * @param {Array<string>} orphans Sorted orphan page ids from computeReachable().
 * @param {{exceptions: Record<string, string>}} baseline The parsed baseline file.
 * @return {{newOrphans: Array<string>, staleExceptions: Array<string>}}
 */
function diffBaseline(orphans, baseline) {
	const exceptions = (baseline && baseline.exceptions) || {}
	const orphanSet = new Set(orphans)
	const newOrphans = orphans.filter((id) => !(id in exceptions)).sort()
	const staleExceptions = Object.keys(exceptions)
		.filter((id) => !orphanSet.has(id))
		.sort()
	return { newOrphans, staleExceptions }
}

/**
 * Collect every menu id declared by the base manifest and the fragments,
 * at any depth. `menu-layout.json` addresses MENU ids; addressing a PAGE id
 * there is a silent no-op.
 *
 * @param {object} base      The bundled base manifest.
 * @param {Array<object>} fragments The `manifest.d` fragments.
 *
 * @return {Set<string>} Every declared menu id.
 */
function collectMenuIds(base, fragments) {
	const ids = new Set()
	const walk = (items) => {
		for (const item of items || []) {
			if (item && typeof item.id === 'string' && item.id !== '')
				ids.add(item.id)
			walk(item && item.children)
		}
	}
	walk(base && base.menu)
	for (const fragment of fragments) walk(fragment && fragment.menu)
	return ids
}

/**
 * An id in `settingsSection` or on either side of a `relocations` pair that
 * matches no menu entry does NOTHING — it is indistinguishable from a correct
 * id, because the pipeline has nothing to report against. That is exactly how
 * the External Connections demotion failed: the PAGE id was used where the
 * MENU id was required.
 *
 * `removals` is deliberately NOT checked: this repo keeps it empty by policy
 * (see `_removals_note`), and a stale id there is inert in the harmless
 * direction.
 *
 * @param {object} base      The bundled base manifest.
 * @param {Array<object>} fragments The `manifest.d` fragments.
 * @param {object} menuLayout `{ relocations?, settingsSection?, removals? }`.
 *
 * @return {Array<{list: string, id: string}>} Inert ids, empty when clean.
 */
function findInertLayoutIds(base, fragments, menuLayout) {
	const known = collectMenuIds(base, fragments)
	const inert = []
	for (const id of menuLayout.settingsSection || []) {
		if (known.has(id) === false) inert.push({ list: 'settingsSection', id })
	}
	for (const [source, target] of Object.entries(menuLayout.relocations || {})) {
		if (known.has(source) === false) {
			inert.push({ list: 'relocations (source)', id: source })
		}
		if (known.has(target) === false) {
			inert.push({ list: 'relocations (target)', id: target })
		}
	}
	return inert
}

async function main() {
	const resolved = await resolveBuildManifestModule()
	if (!resolved) {
		console.error(
			'[check:nav-reachability] FAIL: could not resolve the real @conduction/nextcloud-vue '
				+ 'buildManifest pipeline. Checked:\n'
				+ BUILD_MANIFEST_CANDIDATES.map((c) => `  - ${c}`).join('\n')
				+ '\n[check:nav-reachability] This gate refuses to fall back to a re-implemented '
				+ 'merge/reachability pass (design.md §1) — install node_modules (npm ci) or provide '
				+ 'a sibling nextcloud-vue worktree, then re-run.',
		)
		process.exit(1)
		return
	}
	const { modulePath, mod } = resolved
	const {
		buildManifest,
		mergeMenuItems,
		applyMenuRelocations,
		applyMenuRemovals,
		applySettingsSection,
	} = mod

	console.log(`[check:nav-reachability] buildManifest pipeline: ${modulePath}`)

	const { base, fragments, menuLayout } = loadManifestInputs()
	const manifest = buildManifest(base, fragments, menuLayout)

	console.log(
		`[check:nav-reachability] effective manifest: ${manifest.pages.length} pages, `
			+ `${manifest.menu.length} top-level menu entries`,
	)

	const inertIds = findInertLayoutIds(base, fragments, menuLayout)
	if (inertIds.length > 0) {
		console.error(
			'[check:nav-reachability] FAIL: menu-layout.json names ids that match NO menu entry. '
				+ 'These are INERT — they lift nothing, move nothing, and report nothing:',
		)
		for (const { list, id } of inertIds) {
			console.error(`  - ${list}: "${id}"`)
		}

		console.error(
			'[check:nav-reachability] These are MENU ids, not PAGE ids. External Connections sat '
				+ 'top-level for months because settingsSection carried the PAGE id '
				+ '"ExternalAdaptersStatus" while the menu id is "ExternalConnections" — the demotion '
				+ 'silently did nothing, and nothing reported it.',
		)
		process.exit(1)
		return
	}

	const { orphans } = computeReachable(manifest)

	console.log(
		`[check:nav-reachability] orphaned page ids (unreachable from any menu entry): ${orphans.length}`,
	)

	let baseline
	try {
		baseline = JSON.parse(fs.readFileSync(BASELINE_PATH, 'utf8'))
	} catch (err) {
		console.error(
			`[check:nav-reachability] FAIL: could not read/parse ${BASELINE_PATH}: ${err.message}`,
		)
		process.exit(1)
		return
	}

	const { newOrphans, staleExceptions } = diffBaseline(orphans, baseline)

	if (staleExceptions.length > 0) {
		console.warn(
			`[check:nav-reachability] WARN: ${staleExceptions.length} baseline exception(s) are no longer `
				+ 'orphaned (page became reachable, or was deleted) — safe to prune from '
				+ 'tests/nav-reachability-baseline.json:',
		)
		for (const id of staleExceptions) {
			console.warn(`  - ${id}`)
		}
	}

	if (newOrphans.length === 0) {
		console.log(
			`[check:nav-reachability] PASS: 0 new orphans (${orphans.length - newOrphans.length} baselined, `
				+ `${staleExceptions.length} stale warnings above).`,
		)
		return
	}

	console.error(
		`[check:nav-reachability] FAIL: ${newOrphans.length} new orphan(s) not in the baseline:`,
	)
	const { stages } = replayStages(base, fragments, menuLayout, {
		mergeMenuItems,
		applyMenuRelocations,
		applyMenuRemovals,
		applySettingsSection,
	})
	for (const id of newOrphans) {
		const cause = attributeCause(id, stages)

		console.error(`  - ${id}: cause = ${cause}`)
	}

	console.error(
		'[check:nav-reachability] Either restore reachability for the id(s) above, or — if the '
			+ 'loss is deliberate and the page is reachable some other, non-menu way — add a '
			+ 'reason-bearing entry to tests/nav-reachability-baseline.json (see its _meta block).',
	)
	process.exit(1)
}

module.exports = {
	LINK_FIELDS,
	computeReachable,
	replayStages,
	attributeCause,
	diffBaseline,
	loadManifestInputs,
	resolveBuildManifestModule,
}

if (require.main === module) {
	main()
}
