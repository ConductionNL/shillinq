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
// Re-measured 2026-08-10 (gate-53 effective-manifest-crossref): 1,110,250
// bytes, +16,588 on the 1,093,662 this branch started from. The growth is one
// deliberate, non-recurring addition — the v2 schema requires a `_note` on
// every `type:"custom"` page whose component is not a lib `Cn*` SFC,
// documenting why decomposition into a standard page type was not feasible,
// and 45 such pages had none. Writing those notes cost ~19 KB of prose; the
// same branch freed ~1 KB by deleting dead keys (featureFlag, group, kind,
// i18n, visibility) and ~3 KB by deleting the duplicate in-app Settings page.
// Raised, not trimmed, on purpose: trimming here means deleting the schema's
// required documentation, which is the opposite of what the gate asks for.
// The number of custom pages is bounded, so this is a one-off step, not a new
// growth rate — the structural fix (code-splitting manifest.d/) is still the
// undecided scope of REQ-MBP-001.
//
// HEADROOM IS PART OF THE SETTING, not a leftover. 1,120,000 leaves 9,750 B
// (0.88%), deliberately close to the 6,338 B (0.58%) this check ran with
// before, so the tripwire keeps its sensitivity. The first draft of this bump
// set 1,150,000 — 6.3x more slack than the change needed — which would have
// let the next ~38 KB of growth, none of it schema-mandated prose, land
// silently. A tripwire that only fires after 38 KB of drift is not measuring
// the thing it was written to measure. When a future change legitimately needs
// more, re-measure and re-state the ratio here rather than leaving slack
// behind for it.
//
// Re-measured 2026-08-17 (bookkeeping-provincies-bbv-variant, #866/#862):
// 1,121,104 bytes, +6,230 on the 1,114,874 this branch started from. The
// growth is one fragment,
// `src/manifest.d/bookkeeping-provincies-bbv-variant.json`, going 9,088 →
// 15,318: the BBV Compliance Dashboard's body was declared under a
// `config.dashboard.*` vocabulary NOTHING reads, and re-expressing it in the
// vocabulary CnDashboardPage does read costs bytes — seven widgets, each with
// its own `endpointSource` block carrying the three REQ-BBC-002 filter params,
// plus a `layout[]` entry apiece. That repetition is the declarative
// vocabulary's own shape, not prose: there is no way to hoist a shared
// endpoint binding across widgets.
//
// TRIMMED FIRST, then raised. The first draft of this change measured
// 1,122,261 (+7,387); cutting two widget `_note`s and tightening the two page
// notes recovered 1,157 B. What is left of the prose is the RECORD of the
// defect — that the page mounted empty for every visitor while the manifest
// validated — and this file's own note above says deleting the schema's
// documentation to fit is the opposite of what the gate asks for.
//
// HEADROOM RE-STATED, per the paragraph above: 1,126,300 leaves 5,196 B
// (0.46%), matching the 5,126 B (0.46%) this check ran with immediately
// before, so the tripwire keeps exactly the sensitivity it had. It is NOT
// rounded up to a comfortable number.
//
// Re-measured 2026-08-22 (spend-analytics-ui): 1,128,098 bytes, +2,447 on the
// 1,125,651 this branch started from. The growth is one new fragment,
// `src/manifest.d/spend-analytics-ui.json`, giving
// `GET /api/analytics/spend` its first frontend consumer — one dashboard page
// plus one nested menu leaf. TRIMMED FIRST, per this file's own rule: the
// first draft measured 3,373 B and moving the widget-choice argument out of
// the manifest `_note` and into the component docblock + proposal.md, where it
// does not ship in the main webpack chunk, recovered 926 B. What is left is
// the pointer, not the argument.
//
// ⚠️ THE HEADROOM HAD ALREADY GONE, and that is the finding worth recording.
// The 2026-08-17 entry above set 1,126,300 against a measured 1,121,104 and
// called the resulting 5,196 B "part of the setting". By the time this change
// branched, the base measured 1,125,651: +4,547 B of organic growth had landed
// against a budget nobody re-measured, leaving 649 B — 0.058%, not 0.46%. The
// gate did not fire for any of that growth; it fired for the first change
// unlucky enough to arrive after the slack ran out. A tripwire whose margin is
// consumed silently between re-measurements reports the arrival order of
// changes, not their size.
//
// HEADROOM RE-STATED, per the same rule: 1,128,750 leaves 652 B (0.058%),
// matching the 649 B (0.058%) this check ran with immediately before, so the
// tripwire keeps exactly the sensitivity it had — and is NOT topped back up to
// the 0.46% of 2026-08-17, because that slack is what let 4,547 B land
// unseen. At this ratio essentially every manifest change must re-measure,
// which is the honest consequence of the rule as written; if that is not the
// intended policy, the fix is to decide a margin deliberately and say why,
// not to round this constant up here.
const DEFAULT_BUDGET_BYTES = 1_128_750

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
		console.error(
			`[check-manifest-budget] could not read manifest files: ${err.message}`,
		)
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
