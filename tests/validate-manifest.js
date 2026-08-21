#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest.js — schema-validates src/manifest.json against the
// @conduction/nextcloud-vue app-manifest schema using Ajv, then runs a
// manifest-internal consistency check (unique page ids; every menu.route
// points at an existing page id — the ADR-024/ADR-029 reachability gate).
//
// Usage:
//   node tests/validate-manifest.js
//
// Exit codes:
//   0 — manifest validates against the schema AND is internally consistent
//   1 — manifest fails validation/consistency (or schema/manifest cannot be loaded)
//
// Schema lookup order (first hit wins):
//   1. Env var APP_MANIFEST_SCHEMA — explicit absolute path to a schema JSON
//   2. node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json
//   3. ../nextcloud-vue/src/schemas/app-manifest.schema.json (sibling worktree)
//   4. /tmp/worktrees/nextcloud-vue-manifest-v1/src/schemas/app-manifest.schema.json (v1.2.0 consolidation worktree)
//   5. /tmp/worktrees/nextcloud-vue-page-type-extensions/src/schemas/app-manifest.schema.json (v1.1.0 fallback)
//
// The fourth / fifth options exist because the v1.x schema is not yet
// released to npm; the consolidated `manifest-v1` worktree carries the
// canonical v1.2.0 source. Once published, options 1 and 2 take over.

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')

const MANIFEST_PATH = path.join(REPO_ROOT, 'src', 'manifest.json')

// The manifest's own $schema declares which schema generation it is
// written against (v2 since the CnAppRoot manifest-shell migration).
// Validating a v2 manifest against the v1 schema produced ~480 phantom
// errors (v2-only field types, sidebarProps tab shapes, actions), so the
// candidates must point at the schema file the manifest actually names.
const SCHEMA_BASENAME = (() => {
	try {
		const declared =
			JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8')).$schema || ''
		const base = declared.split('/').pop()
		return base && base.endsWith('.json') ? base : 'app-manifest-v2.schema.json'
	} catch (_) {
		return 'app-manifest-v2.schema.json'
	}
})()

const SCHEMA_CANDIDATES = [
	process.env.APP_MANIFEST_SCHEMA,
	// Vendored canonical v2 schema (includes the setup block + metric cacheTtl);
	// preferred so the gate does not depend on a fresh node_modules copy.
	path.join(REPO_ROOT, 'tests', 'schemas', 'app-manifest-v2.schema.json'),
	path.join(
		REPO_ROOT,
		'node_modules',
		'@conduction',
		'nextcloud-vue',
		'src',
		'schemas',
		SCHEMA_BASENAME,
	),
	path.join(REPO_ROOT, '..', 'nextcloud-vue', 'src', 'schemas', SCHEMA_BASENAME),
].filter(Boolean)

function findSchemaPath() {
	for (const candidate of SCHEMA_CANDIDATES) {
		try {
			if (fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
				return candidate
			}
		} catch (_) {
			// continue to next candidate
		}
	}
	return null
}

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

function loadAjv() {
	// The canonical schema uses JSON Schema draft 2020-12 (`$schema`:
	// "https://json-schema.org/draft/2020-12/schema"). Standard Ajv (v7+)
	// does not auto-load the 2020 meta-schema; we need the `ajv/dist/2020`
	// entry point.
	// Declared without initialisers: every path below assigns or returns, so a
	// placeholder value would be dead (eslint no-useless-assignment).
	let Ajv2020
	let addFormats
	try {
		// Ajv 8+ ships the 2020 draft entry point.
		Ajv2020 = require('ajv/dist/2020').default || require('ajv/dist/2020')
	} catch (_) {
		try {
			// Fall back to standard Ajv (will fail to compile the 2020-draft
			// schema; we surface that error clearly).
			Ajv2020 = require('ajv').default || require('ajv')
		} catch (__) {
			console.error('[validate-manifest] Ajv not installed in node_modules.')
			console.error(
				'[validate-manifest] Install with: npm i -D ajv ajv-formats',
			)
			console.error(
				'[validate-manifest] Falling back to a structural lint pass.',
			)
			return { Ajv: null, addFormats: null }
		}
	}
	try {
		addFormats = require('ajv-formats').default || require('ajv-formats')
	} catch (_) {
		// ajv-formats is optional; the schema uses "uri" format on $schema
		// which without ajv-formats is silently accepted.
		addFormats = null
	}
	return { Ajv: Ajv2020, addFormats }
}

function structuralLint(manifest) {
	// Minimal structural fallback when Ajv isn't available.
	const errors = []
	if (!manifest.version || typeof manifest.version !== 'string') {
		errors.push('top-level: version (string) is required')
	}
	if (!Array.isArray(manifest.menu))
		errors.push('top-level: menu (array) is required')
	if (!Array.isArray(manifest.pages))
		errors.push('top-level: pages (array) is required')
	// Library default page types (app-manifest schema v1.5: index/detail/dashboard/
	// logs/settings/chat/files/form/map plus the "custom" escape hatch), extended
	// with the app-local renderer types Shillinq registers (roadmap, report).
	const allowedTypes = new Set([
		'index',
		'detail',
		'dashboard',
		'logs',
		'settings',
		'chat',
		'files',
		'form',
		'map',
		'custom',
		'roadmap',
		'report',
	])
	const seenIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const page = manifest.pages[i]
		if (!page || typeof page !== 'object') {
			errors.push(`pages[${i}]: must be an object`)
			continue
		}
		for (const required of ['id', 'route', 'type', 'title']) {
			if (!page[required] || typeof page[required] !== 'string') {
				errors.push(
					`pages[${i}]: missing required string field "${required}"`,
				)
			}
		}
		if (page.type && !allowedTypes.has(page.type)) {
			errors.push(`pages[${i}].type: "${page.type}" not in v1.1 enum`)
		}
		if (page.id) {
			if (seenIds.has(page.id))
				errors.push(`pages[${i}].id: duplicate "${page.id}"`)
			seenIds.add(page.id)
		}
		if (page.type === 'custom' && !page.component) {
			errors.push(`pages[${i}]: type=custom requires component field`)
		}
	}
	return errors
}

/**
 * Manifest-internal consistency checks (beyond JSON-Schema validation):
 * every `pages[].id` is unique, and every `menu[]` entry that declares a
 * `route` points at an existing `pages[].id`. `routesFromManifest()` in
 * `src/main.js` turns each page into a vue-router route named after its
 * id, and `CnAppNav` navigates by route name — a `menu.route` with no
 * matching page is a dead link. ADR-024 / ADR-029 reachability gate.
 *
 * @param {object} manifest The parsed manifest.
 * @return {Array<string>} Human-readable error messages (empty == OK).
 */
function consistencyCheck(manifest) {
	const errors = []
	const pageIds = new Set()
	for (let i = 0; i < (manifest.pages || []).length; i++) {
		const id = manifest.pages[i] && manifest.pages[i].id
		if (!id) continue
		if (pageIds.has(id)) errors.push(`pages[${i}].id: duplicate "${id}"`)
		pageIds.add(id)
	}
	for (let i = 0; i < (manifest.menu || []).length; i++) {
		const entry = manifest.menu[i] || {}
		if (entry.route && !pageIds.has(entry.route)) {
			errors.push(
				`menu[${i}].route: "${entry.route}" has no matching pages[].id`,
			)
		}
	}
	return errors
}

// ---------------------------------------------------------------------------
// Dead-config-key gate (fragment scope)
// ---------------------------------------------------------------------------
//
// WHY THIS EXISTS. `src/manifest.json` is the only document the Ajv pass above
// validates, but the per-feature page config actually ships in
// `src/manifest.d/*.json` fragments, lazily loaded at runtime
// (`scripts/generate-manifest-shell.js` deliberately drops `config` from the
// boot shell). Those fragments were therefore completely unvalidated — which is
// exactly how the defect below survived across 21 files.
//
// THE DEFECT. A fragment declared its related-object lists under
// `config.relatedLists`. No component reads that key. `CnDetailPage`'s prop is
// `relatedCollections`; `CnPageRenderer` spreads `config` onto props, so a
// `relatedLists` block lands in `$attrs`, renders NOTHING, and the page shows
// the generic "No relations yet" widget — with no error, no warning, and every
// quality gate green. A dead config key is invisible precisely because it is
// inert.
//
// WHAT THIS CHECKS. Two things, both hard failures:
//   1. No page config declares a key on DEAD_CONFIG_KEYS.
//   2. The gate actually SAW its subject — zero fragments, or zero page
//      configs, is a failure, not a pass. A check that stopped reaching what it
//      inspects must not be able to report success.
// Plus a self-check (3) when `node_modules` is present: it re-derives the real
// prop names off the installed `Cn*` components and fails if the premise behind
// the deny-list has changed (i.e. if `relatedLists` ever BECOMES a prop, or if
// the documented replacement stops being one). That keeps the list honest
// instead of letting it fossilise.

/** Fragment directory holding the lazily-loaded per-feature page config. */
const MANIFEST_D_DIR = path.join(REPO_ROOT, 'src', 'manifest.d')

/** Installed nextcloud-vue component root; absent in a bare checkout. */
const CN_COMPONENTS_DIR = path.join(
	REPO_ROOT,
	'node_modules',
	'@conduction',
	'nextcloud-vue',
	'src',
	'components',
)

/**
 * Config keys that no `Cn*` page component accepts as a prop, mapped to the
 * key that replaces them. Declaring one is always a silent no-op, so it is a
 * hard failure.
 *
 * Keep this list EXPLICIT rather than derived. A naive "every config key must
 * be a prop" rule produces false positives by design: `config` legitimately
 * carries renderer-level keys (`register`, `schema`, `detailRoute`,
 * `indexRoute`, `_note`, …) that `CnPageRenderer` consumes itself and never
 * forwards to the page component. An explicit list of keys PROVEN dead is
 * honest; a derived one would be noise. `checkCnPropPremise()` below guards
 * this list against drift.
 *
 * @type {Object<string,string>}
 */
const DEAD_CONFIG_KEYS = {
	// Proven on LedgerGroupDetail: converting the key to `relatedCollections`
	// made the child rows actually render.
	relatedLists: 'relatedCollections',
}

/**
 * Fragments allowed to still carry a dead key because a DIFFERENT open branch
 * owns the fix, keyed to the reason. This exemption is SELF-RETIRING: once the
 * owning change lands and the key is gone, `checkFragmentConfigKeys()` fails
 * demanding the entry be deleted, so a stale exemption cannot sit here quietly
 * widening the gate.
 *
 * @type {Object<string,string>}
 */
// Empty by design. Entries here are SELF-RETIRING: the gate FAILS when a
// listed fragment no longer declares a dead key, so a waiver cannot outlive
// its reason. The one entry ('budget-core-schema.json', pending PR #1011) did
// exactly that the moment #1011 merged — the gate demanded its own exemption
// be deleted, which is this change. Add an entry only for a fragment whose fix
// is genuinely in flight, and name the PR that retires it.
const PENDING_FRAGMENTS = {}

/**
 * Re-derive the `Cn*` prop names from the installed component sources and
 * verify the premise DEAD_CONFIG_KEYS rests on: each dead key must NOT be a
 * prop on any `Cn*` component, and its documented replacement MUST be one.
 *
 * Skipped (not failed) when `node_modules` is absent, so the gate still runs
 * in a bare checkout — the deny-list is enforced either way.
 *
 * @return {Array<string>} Premise-violation messages (empty == OK or skipped).
 */
function checkCnPropPremise() {
	const errors = []
	let dirs
	try {
		dirs = fs.readdirSync(CN_COMPONENTS_DIR).filter((d) => d.startsWith('Cn'))
	} catch (_) {
		console.log(
			'[validate-manifest] Cn* prop premise: SKIPPED (node_modules absent) — deny-list still enforced',
		)
		return errors
	}
	if (dirs.length === 0) {
		console.log(
			'[validate-manifest] Cn* prop premise: SKIPPED (no Cn* components found)',
		)
		return errors
	}

	// Collect prop names from every `Cn*.vue`'s `props: { ... }` block. Props
	// are declared one-per-line as `\t\tname: {`, so a line-anchored scan is
	// enough and avoids parsing SFCs.
	const props = new Set()
	for (const dir of dirs) {
		const file = path.join(CN_COMPONENTS_DIR, dir, `${dir}.vue`)
		let src
		try {
			src = fs.readFileSync(file, 'utf8')
		} catch (_) {
			continue
		}
		const propsAt = src.indexOf('props: {')
		if (propsAt === -1) continue
		for (const line of src.slice(propsAt).split('\n')) {
			const m = line.match(/^\t\t([A-Za-z][A-Za-z0-9]*): \{/)
			if (m) props.add(m[1])
		}
	}
	if (props.size === 0) {
		errors.push(
			'Cn* prop premise: parsed 0 prop names from the installed components — the scan stopped seeing its subject',
		)
		return errors
	}

	for (const [dead, replacement] of Object.entries(DEAD_CONFIG_KEYS)) {
		if (props.has(dead)) {
			errors.push(
				`Cn* prop premise: "${dead}" is on the DEAD_CONFIG_KEYS list but IS now a prop on a Cn* component — the list is stale, re-check it`,
			)
		}
		if (!props.has(replacement)) {
			errors.push(
				`Cn* prop premise: "${dead}" is documented as replaced by "${replacement}", but "${replacement}" is not a prop on any Cn* component — the replacement is wrong or was renamed`,
			)
		}
	}
	console.log(
		`[validate-manifest] Cn* prop premise: checked ${DEAD_CONFIG_KEYS ? Object.keys(DEAD_CONFIG_KEYS).length : 0} dead key(s) against ${props.size} prop names from ${dirs.length} Cn* components`,
	)
	return errors
}

/**
 * Scan every `src/manifest.d/*.json` fragment for dead page-config keys.
 *
 * Fails when a fragment declares a key on {@link DEAD_CONFIG_KEYS}, and ALSO
 * when the scan inspected zero fragments or zero page configs — a gate that
 * stopped reaching its subject must not report success.
 *
 * @return {Array<string>} Human-readable error messages (empty == OK).
 */
function checkFragmentConfigKeys() {
	const errors = []
	let files
	try {
		files = fs
			.readdirSync(MANIFEST_D_DIR)
			.filter((f) => f.endsWith('.json'))
			.sort()
	} catch (e) {
		return [
			`dead-config-key gate: cannot read ${MANIFEST_D_DIR} (${e.message}) — the gate inspected NOTHING`,
		]
	}

	if (files.length === 0) {
		return [
			`dead-config-key gate: 0 fragments found in ${MANIFEST_D_DIR} — the gate inspected NOTHING, which is a failure, not a pass`,
		]
	}

	let configsSeen = 0
	const hitFragments = new Set()
	for (const file of files) {
		let fragment
		try {
			fragment = loadJson(path.join(MANIFEST_D_DIR, file))
		} catch (e) {
			errors.push(`${file}: not parseable as JSON (${e.message})`)
			continue
		}
		for (const page of fragment.pages || []) {
			const config = page && page.config
			if (!config || typeof config !== 'object') continue
			configsSeen++
			for (const [dead, replacement] of Object.entries(DEAD_CONFIG_KEYS)) {
				if (!Object.hasOwn(config, dead)) continue
				hitFragments.add(file)
				if (PENDING_FRAGMENTS[file]) continue
				errors.push(
					`${file}: pages[id=${page.id || '?'}].config declares "${dead}", which no Cn* page component accepts as a prop — it lands in $attrs and renders NOTHING. Use "${replacement}" ({title, register, schema, filter, columns, rowRoute}) and pick a filter token that resolves against what the CHILD actually stores (@objectId = the row's UUID; @object.<field> = a FLAT payload field, and it cannot reach @self.slug).`,
				)
			}
		}
	}

	if (configsSeen === 0) {
		errors.push(
			`dead-config-key gate: read ${files.length} fragments but found 0 page configs — the gate inspected NOTHING, which is a failure, not a pass`,
		)
	}

	// Self-retiring exemptions: once the owning change lands, the key is gone
	// and the entry must go with it, or it silently widens the gate forever.
	for (const [file, reason] of Object.entries(PENDING_FRAGMENTS)) {
		if (!files.includes(file)) {
			errors.push(
				`dead-config-key gate: PENDING_FRAGMENTS lists "${file}", which no longer exists — delete the entry (${reason})`,
			)
		} else if (!hitFragments.has(file)) {
			errors.push(
				`dead-config-key gate: PENDING_FRAGMENTS exempts "${file}", but it no longer declares a dead config key — the fix landed, so DELETE the entry (${reason})`,
			)
		}
	}

	console.log(
		`[validate-manifest] dead-config-key gate: inspected ${configsSeen} page configs across ${files.length} fragments`,
	)
	return errors
}

/**
 * Run the consistency check and exit 0/1 accordingly. Called from every
 * path where schema/structural validation already passed.
 *
 * @param {object} manifest The parsed manifest.
 * @return {void}
 */
function finishOk(manifest) {
	const errors = consistencyCheck(manifest)
	if (errors.length === 0) {
		console.log('[validate-manifest] consistency check: PASS (0 issues)')
	} else {
		console.error('[validate-manifest] consistency check: FAIL')
		for (const err of errors) console.error(`  - ${err}`)
	}

	// Fragment-scope gate. Runs on every path that reaches here, and reports
	// independently of the consistency check so one failing gate never hides
	// the other's result.
	const deadKeyErrors = [...checkCnPropPremise(), ...checkFragmentConfigKeys()]
	if (deadKeyErrors.length === 0) {
		console.log('[validate-manifest] dead-config-key gate: PASS (0 issues)')
	} else {
		console.error('[validate-manifest] dead-config-key gate: FAIL')
		for (const err of deadKeyErrors) console.error(`  - ${err}`)
	}

	process.exit(errors.length + deadKeyErrors.length === 0 ? 0 : 1)
}

function main() {
	if (!fs.existsSync(MANIFEST_PATH)) {
		console.error(`[validate-manifest] manifest not found: ${MANIFEST_PATH}`)
		process.exit(1)
	}

	const manifest = loadJson(MANIFEST_PATH)
	console.log(`[validate-manifest] manifest: ${MANIFEST_PATH}`)
	console.log(`[validate-manifest] manifest.version: ${manifest.version}`)
	console.log(`[validate-manifest] pages: ${(manifest.pages || []).length}`)

	const schemaPath = findSchemaPath()
	if (!schemaPath) {
		console.warn(
			'[validate-manifest] no schema candidate resolved; falling back to structural lint.',
		)
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log('[validate-manifest] structural lint: PASS (0 issues)')
			finishOk(manifest)
			return
		}
		console.error('[validate-manifest] structural lint: FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}
	console.log(`[validate-manifest] schema: ${schemaPath}`)
	const schema = loadJson(schemaPath)
	console.log(`[validate-manifest] schema.version: ${schema.version || '(unset)'}`)

	const { Ajv, addFormats } = loadAjv()
	if (!Ajv) {
		const errors = structuralLint(manifest)
		if (errors.length === 0) {
			console.log(
				'[validate-manifest] structural lint (no Ajv): PASS (0 issues)',
			)
			finishOk(manifest)
			return
		}
		console.error('[validate-manifest] structural lint (no Ajv): FAIL')
		for (const err of errors) console.error(`  - ${err}`)
		process.exit(1)
	}

	const ajv = new Ajv({ allErrors: true, strict: false })
	if (addFormats) addFormats(ajv)
	const validate = ajv.compile(schema)
	const ok = validate(manifest)
	if (ok) {
		console.log('[validate-manifest] Ajv validation: PASS (0 errors)')
		finishOk(manifest)
		return
	}
	console.error('[validate-manifest] Ajv validation: FAIL')
	for (const err of validate.errors || []) {
		console.error(
			`  - ${err.instancePath || '(root)'} ${err.message} (keyword=${err.keyword})`,
		)
	}
	process.exit(1)
}

main()
