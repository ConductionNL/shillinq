#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-seeds.js — ratchet gate on seed/schema integrity.
//
// Every object this app ships as register seed data MUST satisfy the
// `required` list of the schema it declares itself against. When it does not,
// OpenRegister's ImportHandler logs
//
//   [ImportHandler] Skipping object '<slug>' - import failed: The required
//   properties (...) are missing.
//
// at WARNING level and moves on. `occ app:enable` still exits 0, the install
// still looks clean, and the missing rows only surface much later as an empty
// list or a 404 from an endpoint that is working perfectly — it simply has
// nothing to return.
//
// That is exactly how this repo lost a whole feature's integration tests.
// Measured on `development` (run 31110513361): the `Calendar` schema is
// declared by TWO fragments — `bookings-resource-calendar.json`
// (administrationId + calendarId, and all 18 seeds) and
// `10-bookings-resource-calendar.json` (organization). ADR-037's
// deepMergeConfig() merges fragments by schema KEY, and `required` is a LIST,
// so the two lists were CONCATENATED: the effective schema required BOTH
// vocabularies at once and NO payload of either model could satisfy it.
// cal-001..cal-003 never imported, so
//
//   GET /api/v2/calendars        -> 200 {"calendars":[]}
//   GET /api/v2/calendars/cal-001 -> 404
//
// and the whole bookings collection failed against an app whose calendar code
// was never wrong. Same mechanism on `Commitment` (tenderned vs
// verplichtingenadministratie) — see the report in the PR.
//
// WHY A RATCHET, NOT A HARD ZERO: 81 shipped seed objects fail this check on
// the commit that introduced the gate, across ~28 schemas and many
// capabilities. Fixing all of them is a much larger piece of work than the one
// this gate was written for. A ratchet is still a HARD gate — the count may
// never rise — so no new broken seed can land, and the number can only be
// driven down. Lower the baseline whenever you fix some.
//
// Usage:  node tests/validate-seeds.js
//
// Exit codes:
//   0 — failing seed count is at or below the baseline
//   1 — failing seed count ROSE above the baseline (or a parse error)

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const MAIN_REGISTER = path.join(
	REPO_ROOT,
	'lib',
	'Settings',
	'shillinq_register.json',
)
const FRAGMENT_DIR = path.join(REPO_ROOT, 'lib', 'Settings', 'register.d')

// Number of shipped seed objects that fail their own schema's `required`
// list. Measured on the commit that added this gate. NEVER raise this to make
// a build pass — that is the whole point of the ratchet.
// Lowered 74 -> 71 on 2026-08-16: the three `Service` seeds in
// 10-bookings-create-appointment.json and 30-bookings-self-service-widget.json
// now carry the full merged-schema `required` set (code, prepareTime,
// bufferBefore, bufferAfter, basePrice, dynamicPricing, serviceCategory).
// Lowered 71 -> 61 by contracts-single-home (measured before/after the
// rename): un-merging `Contract` fixed 7 previously-failing seed objects
// that had been checked against the pre-fix merged `Contract` schema's
// concatenated `required` list spanning both domains — 3 CLM demo seeds
// (contract-cleaning-2026 etc., missing the IFRS-15-only customerId /
// signedAt / fixedConsideration / lifecycleState) and 4 IFRS-15 demo seeds
// (missing the CLM-only title / contractType / status). Post-fix, the 3 CLM
// seeds check cleanly against CLM's own `Contract.required` and the 4
// IFRS-15 seeds (now `"schema": "RevenueContract"`) check cleanly against
// `RevenueContract`'s own `required`; the `contract-handoff-demo-2026` seed
// already satisfied both required lists before and after, so is not part of
// this delta.
// Lowered 61 -> 53 by the commitment anglicisation, and by the same kind of
// fix as the one above: un-merging a schema whose `required` list had been the
// concatenation of two domains. All 8 were `Budget` seeds failing against a
// merged required list spanning both (financialYear, authorised_amount,
// budgetName, totalAmount, programmeStructure, status, fiscalYear — two
// schemas' requirements fused into one). `Budget` is now split, and each seed
// checks against the schema it actually belongs to: the 6 in
// bookkeeping-provincies-bbv-variant.json are `BbvProgrammeBudget`, the 2 in
// bookkeeping-verplichtingenadministratie.json are `CommitmentBudget`. Verified
// by counting the seeds on both sides — 726 -> 746 objects checked, so none of
// the 8 was removed; they moved schema and now satisfy it.
//
// The 9th change in that range is a rename with no effect on the count:
// `1 x Verplichting — missing: kind` is now `1 x Commitment — missing: kind`.
// It still fails, and is still counted here.
const BASELINE = 53

const asArray = (value) => (Array.isArray(value) ? value : [])

// Mirror of SettingsService::deepMergeConfig() (ADR-037). Associative arrays
// merge by key union; LIST arrays CONCATENATE (this is the behaviour that
// makes two `required` declarations unsatisfiable); scalars overwrite.
function deepMerge(base, overlay) {
	for (const [key, value] of Object.entries(overlay)) {
		const baseValue = base[key]
		if (
			value
			&& typeof value === 'object'
			&& baseValue
			&& typeof baseValue === 'object'
		) {
			const bothLists = Array.isArray(baseValue) && Array.isArray(value)
			const neitherList = !Array.isArray(baseValue) && !Array.isArray(value)
			if (bothLists) base[key] = baseValue.concat(value)
			else if (neitherList) deepMerge(baseValue, value)
			else base[key] = value
		} else {
			base[key] = value
		}
	}
	return base
}

function loadJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch (err) {
		console.error(`[validate-seeds] failed to parse ${file}: ${err.message}`)
		process.exit(1)
	}
}

function main() {
	if (!fs.existsSync(MAIN_REGISTER)) {
		console.error(`[validate-seeds] main register not found at ${MAIN_REGISTER}`)
		process.exit(1)
	}

	const seeds = []
	// ImportHandler reads BOTH buckets:
	//   foreach ([$data['components']['objects'], $data['objects']] as $seedBucket)
	const collect = (source, doc) => {
		for (const obj of asArray(doc.objects)) seeds.push({ source, obj })
		for (const obj of asArray(doc.components && doc.components.objects))
			seeds.push({ source, obj })
	}

	let merged = loadJson(MAIN_REGISTER)
	collect('shillinq_register.json', merged)

	if (fs.existsSync(FRAGMENT_DIR) && fs.statSync(FRAGMENT_DIR).isDirectory()) {
		// Sorted, exactly as SettingsService globs + sorts them.
		for (const name of fs.readdirSync(FRAGMENT_DIR).sort()) {
			if (!name.endsWith('.json')) continue
			const doc = loadJson(path.join(FRAGMENT_DIR, name))
			collect(name, doc)
			merged = deepMerge(merged, doc)
		}
	}

	const schemas = (merged.components && merged.components.schemas) || {}
	const offenders = new Map()
	let checked = 0
	let unknownSchema = 0

	for (const { source, obj } of seeds) {
		const self = obj['@self'] || {}
		const schemaName = self.schema
		if (!schemaName) continue

		const def = schemas[schemaName]
		if (!def) {
			unknownSchema++
			continue
		}

		checked++
		const required = [...new Set(asArray(def.required))]
		const missing = required.filter(
			(prop) => obj[prop] === undefined || obj[prop] === null,
		)
		if (missing.length === 0) continue

		if (!offenders.has(schemaName)) {
			offenders.set(schemaName, {
				count: 0,
				missing: new Set(),
				sources: new Set(),
			})
		}
		const entry = offenders.get(schemaName)
		entry.count++
		entry.sources.add(source)
		missing.forEach((m) => entry.missing.add(m))
	}

	const failing = [...offenders.values()].reduce((sum, e) => sum + e.count, 0)

	console.log(`[validate-seeds] seed objects checked: ${checked}`)
	if (unknownSchema > 0) {
		console.log(
			`[validate-seeds] seeds naming a schema no fragment declares: ${unknownSchema}`,
		)
	}
	console.log(
		`[validate-seeds] seeds that cannot satisfy their schema: ${failing} (baseline ${BASELINE})`,
	)

	// A scan that silently found nothing to check must NOT report success.
	if (checked < 300) {
		console.error(
			`[validate-seeds] FAIL — only ${checked} seed objects were checked, which is`,
		)
		console.error(
			'[validate-seeds] implausibly few. The scan did not run properly; a green',
		)
		console.error(
			'[validate-seeds] result here would say nothing about the seeds.',
		)
		process.exit(1)
	}

	for (const [schema, entry] of [...offenders].sort(
		(a, b) => b[1].count - a[1].count,
	)) {
		console.log(
			`    ${String(entry.count).padStart(3)} x ${schema} — missing: ${[...entry.missing].join(', ')}`
				+ `  (declared in ${[...entry.sources].join(', ')})`,
		)
	}

	if (failing > BASELINE) {
		console.error('')
		console.error(
			`[validate-seeds] FAIL — ${failing} seed objects cannot import, up from the`,
		)
		console.error(
			`[validate-seeds] baseline of ${BASELINE}. Something in this change made it worse.`,
		)
		console.error(
			'[validate-seeds] Most likely cause: a schema key declared by two fragments with',
		)
		console.error(
			'[validate-seeds] different `required` lists. ADR-037 merges fragments by key and',
		)
		console.error(
			'[validate-seeds] CONCATENATES list values, so the effective `required` is the UNION',
		)
		console.error(
			'[validate-seeds] of both and no payload of either model can satisfy it.',
		)
		console.error(
			'[validate-seeds] Fix the seed or the schema — do NOT raise BASELINE.',
		)
		process.exit(1)
	}

	if (failing < BASELINE) {
		console.log('')
		console.log(
			`[validate-seeds] PASS — and ${BASELINE - failing} better than baseline.`,
		)
		console.log(
			`[validate-seeds] Please lower BASELINE in tests/validate-seeds.js to ${failing}.`,
		)
		process.exit(0)
	}

	console.log('[validate-seeds] PASS — at baseline.')
	process.exit(0)
}

main()
