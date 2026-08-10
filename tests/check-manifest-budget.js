#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-manifest-budget.js — fails the build when the combined byte size of
// src/manifest.json + src/manifest.d/*.json exceeds a defined budget
// (shillinq-manifest-boot-payload-reduction, REQ-MBP-001). ALL of this JSON
// is currently bundled synchronously into the `main` webpack chunk
// (src/main.js: `import bundledManifest from './manifest.json'` +
// `require.context('./manifest.d/', ...)`), so every byte here ships on
// EVERY page load regardless of which feature area the user opens. This
// script does not implement code-splitting (that is a larger, currently
// undecided architecture change — see openspec/changes/
// shillinq-manifest-boot-payload-reduction/design.md) — it only stops the
// payload from silently growing further while that decision is pending.
//
// Usage:
//   node tests/check-manifest-budget.js
//   MANIFEST_BUDGET_BYTES=1200000 node tests/check-manifest-budget.js
//
// Exit codes:
//   0 — combined size is at or under the budget
//   1 — combined size exceeds the budget (or the manifest files are unreadable)

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')
const MANIFEST_D_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')

// Budget set just above the measured post-cleanup total (removal of the
// duplicate bookings-resource-calendar.json fragment, REQ-MBP-002), leaving
// headroom for organic growth before this check starts failing builds.
// Re-measure and adjust deliberately if a legitimate large feature area is
// added — this is a tripwire, not a hard architectural ceiling.
//
// Re-measured 2026-07-13 (accountant-portal): the combined total had already
// drifted to 1,066,101 bytes from organic fragment growth across the
// intervening waves — BEFORE this change's own ~1KB
// accountant-portal.json fragment — so the gate was failing independent of
// this change. Bumped with headroom rather than trimmed: the actual
// structural fix (code-splitting manifest.d/ so an unopened feature area
// never ships its JSON) is the explicit, larger, still-undecided scope of
// shillinq-manifest-boot-payload-reduction (REQ-MBP-001) — this tripwire is
// deliberately kept loose, not re-architected, here.
//
// Re-measured 2026-08-10 (gate-53 effective-manifest-crossref): 1,112,863
// bytes. The growth is one deliberate, non-recurring addition — the v2 schema
// requires a `_note` on every `type:"custom"` page whose component is not a
// lib `Cn*` SFC, documenting why decomposition into a standard page type was
// not feasible, and 45 such pages had none. Writing those notes added ~19.2 KB
// of prose; the same commit REMOVED ~1 KB of dead keys (featureFlag, group,
// kind, i18n, visibility), so the net is ~+18 KB against ~6 KB of headroom.
// Raised, not trimmed, on purpose: trimming here means deleting the schema's
// required documentation, which is the opposite of what the gate asks for.
// The number of custom pages is bounded, so this is a one-off step, not a new
// growth rate — the structural fix (code-splitting manifest.d/) is still the
// undecided scope of REQ-MBP-001.
const DEFAULT_BUDGET_BYTES = 1_150_000

/**
 * Sum the byte size of every regular file in a directory (non-recursive),
 * matching the require.context('./manifest.d/', false, /\.json$/) glob
 * shape used by src/main.js.
 *
 * @param {string} dir Directory to scan.
 * @return {number} Combined byte size of every *.json file in dir.
 */
function sumJsonFileSizes(dir) {
	const entries = fs.readdirSync(dir, { withFileTypes: true })
	let total = 0
	for (const entry of entries) {
		if (entry.isFile() && entry.name.endsWith('.json')) {
			total += fs.statSync(path.join(dir, entry.name)).size
		}
	}
	return total
}

function main() {
	const budget = Number(process.env.MANIFEST_BUDGET_BYTES) || DEFAULT_BUDGET_BYTES

	let manifestSize
	let fragmentsSize
	try {
		manifestSize = fs.statSync(MANIFEST_PATH).size
		fragmentsSize = sumJsonFileSizes(MANIFEST_D_DIR)
	} catch (err) {
		// eslint-disable-next-line no-console
		console.error(`[check-manifest-budget] could not read manifest files: ${err.message}`)
		process.exit(1)
		return
	}

	const total = manifestSize + fragmentsSize

	// eslint-disable-next-line no-console
	console.log(
		`[check-manifest-budget] manifest.json=${manifestSize}B manifest.d/=${fragmentsSize}B `
		+ `total=${total}B budget=${budget}B`,
	)

	if (total > budget) {
		// eslint-disable-next-line no-console
		console.error(
			`[check-manifest-budget] FAIL: combined manifest JSON (${total}B) exceeds the `
			+ `${budget}B budget shipped in the main webpack chunk (REQ-MBP-001). Either trim `
			+ `the added fragment(s), or raise MANIFEST_BUDGET_BYTES deliberately if the growth `
			+ `is justified — re-measure with \`du -bc src/manifest.json src/manifest.d/*.json\`.`,
		)
		process.exit(1)
		return
	}

	// eslint-disable-next-line no-console
	console.log('[check-manifest-budget] PASS')
}

main()
