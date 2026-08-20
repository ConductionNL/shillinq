#!/usr/bin/env node
/**
 * ADR-057 D3 gate — Dutch-as-source-string hard fail.
 *
 * English is the source language (ADR-007); a Dutch word used as a
 * `t()`/`n()` literal argument, or as a manifest `title`/`body`/`label`
 * value, is source text in the wrong language, not a translation. This
 * script greps for a curated Dutch-token stopword list (ADR-057 D3: "a
 * curated stopword list: en/de/het/van/voor/met/niet/… plus known
 * offenders") across two surfaces:
 *
 *   1. Literal `t('shillinq', '...')` / `n('shillinq', '...', '...', n)`
 *      call arguments in src/ (same extraction as check-l10n.js).
 *   2. `src/manifest.json` + `src/manifest.d/*.json` — the setup wizard
 *      (`setup.steps[].title`/`body`/`options[].label`/
 *      `optionsByParent[*][].label`) and page/menu navigation strings
 *      (`pages[].title`, `pages[].config.widgets[].title`,
 *      `menu[].label` recursively through `children[]`).
 *
 * Scope note (setup-wizard-english REQ-SWE-004): surface 2 is
 * intentionally limited to the fields REQ-SWE-001/REQ-SWE-002 migrated —
 * `columns[].label` / `fields[].label` (data-grid column/field labels)
 * are NOT scanned here. Those still carry substantial pre-existing Dutch
 * content across the fleet (property/enum-label translation is explicitly
 * out of scope for this change — see proposal.md Non-Goals; PRs #534/#560
 * cover that class of work with data migrations) and scanning them here
 * would fail this gate on debt this change never touched, rather than on
 * a real regression. Broadening this gate to cover columns/fields is a
 * follow-up once that migration lands.
 *
 * Jurisdiction-specific legal-entity acronyms/proper nouns (ZZP, MKB, VZW,
 * BV, BBV, GmbH, Verein, Einzelunternehmen, RGS, BTW, …) are deliberately
 * NOT in the stopword list — ADR-007's proper-noun/acronym exception.
 *
 * Usage:
 *   node tests/l10n/check-dutch-tokens.js
 *
 * Env:
 *   L10N_APP_ID    override the app id (default: package.json "name")
 *   L10N_SRC_DIR   override the source dir to scan (default: src)
 *
 * Exit codes:
 *   0  no Dutch-token match found
 *   1  one or more Dutch-token matches found (hard failure)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

'use strict'

const fs = require('fs')
const path = require('path')

const ROOT = process.cwd()

function readJson(p) {
	return JSON.parse(fs.readFileSync(p, 'utf8'))
}

const appId = process.env.L10N_APP_ID
	|| (fs.existsSync(path.join(ROOT, 'package.json'))
		? readJson(path.join(ROOT, 'package.json')).name
		: null)

if (!appId) {
	console.error('check-dutch-tokens: cannot determine app id (no L10N_APP_ID and no package.json "name")')
	process.exit(2)
}

// Curated Dutch stopword/token list (ADR-057 D3): common Dutch function
// words plus domain offenders observed in this app's own Dutch prose.
// Deliberately excludes jurisdiction acronyms and any word that also
// legitimately occurs in English business prose (e.g. "is"/"in"/"op" as a
// standalone English word or abbreviation are NOT included — too many
// false positives).
const DUTCH_TOKENS = new Set([
	// Articles / pronouns / conjunctions / prepositions
	'de', 'het', 'een', 'van', 'voor', 'met', 'niet', 'en', 'wordt', 'worden',
	'deze', 'dit', 'zijn', 'moet', 'moeten', 'kan', 'kunt', 'kunnen', 'wil',
	'wilt', 'geen', 'als', 'naar', 'door', 'bij', 'uit', 'onder',
	'tussen', 'binnen', 'buiten', 'tegen', 'tijdens', 'sinds', 'totdat',
	'omdat', 'terwijl', 'ook', 'nog', 'dan', 'toch', 'dus', 'maar',
	'echter', 'jouw', 'uw', 'onze', 'hun', 'haar', 'ik', 'jij', 'wij', 'zij',
	'je', 'ze', 'welk', 'welke', 'elke', 'ieder', 'iedere', 'alle', 'alles',
	'hierbij', 'hiermee', 'hiervan', 'hierop', 'waarop', 'waarvan', 'waarmee',
	'gestandaardiseerde', 'indeling', 'grondslag',
	// Domain-specific Dutch offenders observed in this app's manifest/l10n
	'gemeente', 'provincie', 'waterschap', 'belasting', 'belastingen',
	'rekeningschema', 'grootboek', 'grootboekrekening', 'boeking', 'boekingen',
	'factuur', 'facturen', 'bewaartermijn', 'bewaartermijnen', 'aansluiting',
	'aansluitingen', 'oninbare', 'oninbaar', 'afschrijving', 'afschrijvingen',
	'tijdelijke', 'tijdelijk', 'verschil', 'verschillen', 'schatkist',
	'urenregistratie', 'organisatietype', 'juridische', 'juridisch',
	'administratie', 'klaar', 'welkom', 'kies', 'aanmaken', 'laden',
	'controleer', 'gevestigd', 'referentiedata', 'winst-en-verliesrekening',
	'balans', 'passend', 'voorgesteld', 'aanpassen', 'standaardadministratie',
	'geregistreerd', 'gekoppeld', 'tarieven', 'taakvelden', 'duren',
	'starten', 'keuzes', 'hieronder', 'installatie', 'nederland', 'belgië',
	'duitsland', 'verlopen', 'binnenkort',
])

const WORD_RE = /[\p{L}][\p{L}'-]*/gu

/**
 * @param {string} str Candidate text.
 * @return {string[]} Every Dutch stopword token found in str (verbatim casing).
 */
function findDutchTokens(str) {
	if (typeof str !== 'string') {
		return []
	}
	const hits = []
	let m
	WORD_RE.lastIndex = 0
	while ((m = WORD_RE.exec(str)) !== null) {
		if (DUTCH_TOKENS.has(m[0].toLowerCase())) {
			hits.push(m[0])
		}
	}
	return hits
}

const findings = []

// --- Surface 1: t()/n() literal call arguments in src/ ---

const srcDir = path.join(ROOT, process.env.L10N_SRC_DIR || 'src')
const exts = new Set(['.vue', '.js', '.ts', '.mjs', '.jsx', '.tsx'])
const srcFiles = []
if (fs.existsSync(srcDir)) {
	(function walk(dir) {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			if (entry.name === 'node_modules' || entry.name.startsWith('.')) {
				continue
			}
			const full = path.join(dir, entry.name)
			if (entry.isDirectory()) {
				walk(full)
			} else if (exts.has(path.extname(entry.name))) {
				srcFiles.push(full)
			}
		}
	})(srcDir)
}

const esc = appId.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
const tRe = new RegExp(
	'[\\$.]?\\bt\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)
const nRe = new RegExp(
	'[\\$.]?\\bn\\(\\s*[\'"`]' + esc + '[\'"`]\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)\\s*,\\s*'
	+ '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"|`([^`$]*)`)',
	'g',
)

for (const file of srcFiles) {
	const content = fs.readFileSync(file, 'utf8')
	let m
	while ((m = tRe.exec(content)) !== null) {
		const key = m[1] ?? m[2] ?? m[3]
		const hits = findDutchTokens(key)
		if (hits.length) {
			const line = content.slice(0, m.index).split('\n').length
			findings.push({ where: `${path.relative(ROOT, file)}:${line}`, value: key, hits })
		}
	}
	while ((m = nRe.exec(content)) !== null) {
		for (const key of [m[1] ?? m[2] ?? m[3], m[4] ?? m[5] ?? m[6]]) {
			const hits = findDutchTokens(key)
			if (hits.length) {
				const line = content.slice(0, m.index).split('\n').length
				findings.push({ where: `${path.relative(ROOT, file)}:${line}`, value: key, hits })
			}
		}
	}
}

// --- Surface 2: manifest.json + manifest.d/*.json ---
// Scope: setup.steps[] (title/body/options[].label/optionsByParent labels)
// + pages[].title + pages[].config.widgets[].title + menu[].label
// (recursive). See header note on why columns[]/fields[] labels are
// excluded.

function checkSetup(setup, where) {
	for (const s of (setup && setup.steps) || []) {
		for (const f of ['title', 'body']) {
			const hits = findDutchTokens(s[f])
			if (hits.length) {
				findings.push({ where: `${where} setup.steps[${s.id}].${f}`, value: s[f], hits })
			}
		}
		for (const o of s.options || []) {
			const hits = findDutchTokens(o.label)
			if (hits.length) {
				findings.push({ where: `${where} setup.steps[${s.id}].options[${o.value}].label`, value: o.label, hits })
			}
		}
		for (const parent of Object.keys(s.optionsByParent || {})) {
			for (const o of s.optionsByParent[parent]) {
				const hits = findDutchTokens(o.label)
				if (hits.length) {
					findings.push({
						where: `${where} setup.steps[${s.id}].optionsByParent.${parent}[${o.value}].label`,
						value: o.label,
						hits,
					})
				}
			}
		}
	}
}

function checkPages(pages, where) {
	for (const p of pages || []) {
		const hits = findDutchTokens(p.title)
		if (hits.length) {
			findings.push({ where: `${where} pages[${p.id}].title`, value: p.title, hits })
		}
		for (const w of (p.config && p.config.widgets) || []) {
			const whits = findDutchTokens(w.title)
			if (whits.length) {
				findings.push({ where: `${where} pages[${p.id}].config.widgets[${w.id}].title`, value: w.title, hits: whits })
			}
		}
	}
}

function checkMenu(items, where, trail) {
	for (const it of items || []) {
		const hits = findDutchTokens(it.label)
		if (hits.length) {
			findings.push({ where: `${where} menu${trail}[${it.id}].label`, value: it.label, hits })
		}
		if (it.children) {
			checkMenu(it.children, where, `${trail}>${it.id}`)
		}
	}
}

const manifestPath = path.join(ROOT, 'src', 'manifest.json')
if (fs.existsSync(manifestPath)) {
	const m = readJson(manifestPath)
	checkSetup(m.setup, 'src/manifest.json')
	checkPages(m.pages, 'src/manifest.json')
	checkMenu(m.menu, 'src/manifest.json', '')
}

const manifestDDir = path.join(ROOT, 'src', 'manifest.d')
if (fs.existsSync(manifestDDir)) {
	for (const entry of fs.readdirSync(manifestDDir, { withFileTypes: true })) {
		if (!entry.isFile() || !entry.name.endsWith('.json')) {
			continue
		}
		const fragPath = path.join(manifestDDir, entry.name)
		const rel = path.relative(ROOT, fragPath)
		let frag
		try {
			frag = readJson(fragPath)
		} catch (e) {
			findings.push({ where: rel, value: null, hits: [], parseError: e.message })
			continue
		}
		checkSetup(frag.setup, rel)
		checkPages(frag.pages, rel)
		checkMenu(frag.menu, rel, '')
	}
}

console.log(`check-dutch-tokens [${appId}]: scanned ${srcFiles.length} src file(s) `
	+ `+ manifest.json + manifest.d/*.json for ${DUTCH_TOKENS.size} curated Dutch tokens`)

if (findings.length === 0) {
	console.log('check-dutch-tokens: OK — no Dutch-token match found')
	process.exit(0)
}

console.error(`\ncheck-dutch-tokens: FAIL — ${findings.length} Dutch-token match(es) found:`)
for (const f of findings) {
	if (f.parseError) {
		console.error(`  • ${f.where}: could not parse (${f.parseError})`)
		continue
	}
	console.error(`  • ${f.where} = ${JSON.stringify(f.value)}  tokens=${JSON.stringify(f.hits)}`)
}
console.error('\nEnglish is the source language (ADR-007). Rewrite the flagged string(s) to English '
	+ 'source text and add the identity mapping to l10n/en.json, preserving the original Dutch value as '
	+ 'the l10n/nl.json translation under the same key (ADR-057 D3).')
process.exit(1)
