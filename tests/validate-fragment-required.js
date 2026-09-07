#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-fragment-required.js — one owner per schema `required` list.
//
// ADR-037 layers register fragments with SettingsService::deepMergeConfig(),
// which merges associative arrays by key and CONCATENATES list values. So when
// two fragments declare `required` for the SAME schema key, the effective
// stored `required` is the CONCATENATION of both lists — never the intersection
// and never a replacement. Two consequences, of very different severity:
//
//   CONFLICTING — the two lists are different SETS. The merged schema demands
//                 the union, i.e. the vocabulary of BOTH models at once, and no
//                 payload of either model can satisfy it. Measured on
//                 `Commitment`: `20-bookkeeping-tenderned-integratie.json`
//                 required [verplichtingNummer, omschrijving, bron, bedrag,
//                 administrationId, status] while
//                 `bookkeeping-verplichtingenadministratie.json` required
//                 [administrationId, verplichtingsnummer, soort, status] — ten
//                 entries containing TWO DIFFERENT SPELLINGS OF THE SAME FIELD.
//                 Every seed was skipped at `occ app:enable`, the Newman
//                 REQ-001 POST answered 400, and the TenderNed award listener
//                 created nothing — all silently, because ImportHandler only
//                 WARNS and the listener's catch is fail-soft.
//
//   DUPLICATE   — the two lists are the same SET. The merged array then holds
//                 each name twice. JSON Schema says `required` items MUST be
//                 unique, so this is malformed, but it stays satisfiable and
//                 nothing breaks today. Reported, never fatal.
//
// COMPARE AS SETS, NOT AS SEQUENCES. `required` is a set — order carries no
// meaning. An order-sensitive comparison reports 49 conflicts on this tree
// where a set comparison reports 47: two schemas declare the same names in a
// different order and merge to something perfectly satisfiable. Those two are
// false positives, and a gate that ships with known false positives gets
// switched off.
//
// This checker deliberately does NOT re-implement the merge. It does not need
// it: the defect is visible in the per-fragment DECLARATIONS, before any merge
// happens. `tests/validate-seeds.js` owns the merge replay and measures the
// downstream damage (seeds that cannot import); this measures the cause.
//
// Usage:  node tests/validate-fragment-required.js
//
// Exit codes:
//   0 — no schema key has a CONFLICTING multi-fragment `required` declaration
//       (or the count is at/below BASELINE)
//   1 — the conflicting count ROSE above BASELINE (or a parse error)

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const SETTINGS_DIR = path.join(REPO_ROOT, 'lib', 'Settings')
const FRAGMENT_DIR = path.join(SETTINGS_DIR, 'register.d')

// Number of schema keys whose multi-fragment `required` declarations are
// different SETS, measured on the commit that added this gate. A ratchet, not a
// waiver: 47 schemas are already broken this way and fixing them all is a much
// larger piece of work than the one this gate was written for. The count may
// only ever be LOWERED — no new conflicting declaration can land.
const BASELINE = 44

function loadJson(file) {
	try {
		return JSON.parse(fs.readFileSync(file, 'utf8'))
	} catch (err) {
		console.error(
			`[validate-fragment-required] failed to parse ${file}: ${err.message}`,
		)
		process.exit(1)
	}
}

// Every file SettingsService globs: lib/Settings/*register*.json plus every
// register.d/ fragment. Same surface as gates 18/51/54 and validate-seeds.
function registerFiles() {
	const files = []
	for (const name of fs.readdirSync(SETTINGS_DIR).sort()) {
		if (name.endsWith('.json') && name.includes('register')) {
			files.push(path.join(SETTINGS_DIR, name))
		}
	}
	if (fs.existsSync(FRAGMENT_DIR) && fs.statSync(FRAGMENT_DIR).isDirectory()) {
		for (const name of fs.readdirSync(FRAGMENT_DIR).sort()) {
			if (name.endsWith('.json')) files.push(path.join(FRAGMENT_DIR, name))
		}
	}
	return files
}

function sameSet(a, b) {
	const sa = [...new Set(a)].sort()
	const sb = [...new Set(b)].sort()
	return sa.length === sb.length && sa.every((v, i) => v === sb[i])
}

function main() {
	const files = registerFiles()
	if (files.length < 50) {
		// A scan that silently found nothing to check must NOT report success.
		console.error(
			`[validate-fragment-required] FAIL — only ${files.length} register file(s)`,
		)
		console.error(
			'[validate-fragment-required] were found, which is implausibly few. The scan',
		)
		console.error(
			'[validate-fragment-required] did not run properly; a green result here would',
		)
		console.error(
			'[validate-fragment-required] say nothing about the fragments.',
		)
		process.exit(1)
	}

	// schemaKey -> [{ file, required }]
	const declarations = new Map()
	for (const file of files) {
		const doc = loadJson(file)
		const schemas = (doc.components && doc.components.schemas) || {}
		for (const [name, schema] of Object.entries(schemas)) {
			if (!schema || typeof schema !== 'object') continue
			const required = schema.required
			if (!Array.isArray(required) || required.length === 0) continue
			if (!declarations.has(name)) declarations.set(name, [])
			declarations.get(name).push({ file: path.basename(file), required })
		}
	}

	const conflicting = []
	const duplicate = []
	for (const [name, entries] of [...declarations].sort()) {
		if (entries.length < 2) continue
		const allSame = entries.every((e) =>
			sameSet(e.required, entries[0].required),
		)
		;(allSame ? duplicate : conflicting).push({ name, entries })
	}

	console.log(
		`[validate-fragment-required] register files scanned: ${files.length}`,
	)
	console.log(
		`[validate-fragment-required] schema keys declaring \`required\`: ${declarations.size}`,
	)
	console.log(
		`[validate-fragment-required] DUPLICATE (same set declared twice — malformed but satisfiable): ${duplicate.length}`,
	)
	console.log(
		`[validate-fragment-required] CONFLICTING (merged union is unsatisfiable): ${conflicting.length} (baseline ${BASELINE})`,
	)

	for (const { name, entries } of conflicting) {
		const union = [...new Set(entries.flatMap((e) => e.required))].sort()
		console.log(
			`    ${name} — merged \`required\` demands all ${union.length} of: ${union.join(', ')}`,
		)
		for (const e of entries)
			console.log(`        ${e.file}: ${e.required.join(', ')}`)
	}
	if (duplicate.length > 0) {
		console.log('')
		console.log(
			'[validate-fragment-required] DUPLICATE (lower severity — collapse to one owner):',
		)
		for (const { name, entries } of duplicate) {
			console.log(`    ${name}: ${entries.map((e) => e.file).join(', ')}`)
		}
	}

	if (conflicting.length > BASELINE) {
		console.error('')
		console.error(
			`[validate-fragment-required] FAIL — ${conflicting.length} schema keys have a`,
		)
		console.error(
			`[validate-fragment-required] conflicting multi-fragment \`required\`, up from the`,
		)
		console.error(`[validate-fragment-required] baseline of ${BASELINE}.`)
		console.error(
			'[validate-fragment-required] ADR-037 CONCATENATES list values on merge, so the',
		)
		console.error(
			'[validate-fragment-required] effective `required` is the UNION of both lists and',
		)
		console.error(
			'[validate-fragment-required] no payload of either model can satisfy it. Nothing',
		)
		console.error(
			'[validate-fragment-required] reports this at runtime: ImportHandler only WARNS',
		)
		console.error(
			'[validate-fragment-required] and `occ app:enable` still exits 0.',
		)
		console.error(
			'[validate-fragment-required] FIX: give the schema ONE owning fragment that',
		)
		console.error(
			'[validate-fragment-required] declares `required`; overlay fragments contribute',
		)
		console.error(
			'[validate-fragment-required] properties only. Do NOT raise BASELINE.',
		)
		process.exit(1)
	}

	if (conflicting.length < BASELINE) {
		console.log('')
		console.log(
			`[validate-fragment-required] PASS — and ${BASELINE - conflicting.length} better than baseline.`,
		)
		console.log(
			`[validate-fragment-required] Please lower BASELINE in tests/validate-fragment-required.js to ${conflicting.length}.`,
		)
		process.exit(0)
	}

	console.log('')
	console.log('[validate-fragment-required] PASS — at baseline.')
	process.exit(0)
}

main()
