#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-semantic-markers.js — schema-level CI gate for capability
// semantic-schema-markers (REQ-SEM-001).
//
// Asserts that every `x-schema-org` marker declared anywhere in
// `lib/Settings/shillinq_register.json` or the modular register
// fragments under `lib/Settings/register.d/*.json` is a valid CURIE:
// a namespace prefix followed by a colon (e.g. `schema:Invoice`,
// `schatkist:BankingRule`) or the `ns#` local form (e.g. `ns#Contract`).
//
// Bare type names such as `Invoice` or `Order` are rejected — a
// semantic consumer (ADR-048/051 handoffs, MDM type-matching, the
// softwarecatalog / GEMMA mappers) resolves markers by their CURIE
// prefix, so an un-prefixed marker is silently skipped.
//
// Usage:
//   node tests/validate-semantic-markers.js
//
// Exit codes:
//   0 — every x-schema-org marker is a valid prefixed CURIE
//   1 — one or more markers are bare / malformed (or a parse error)
//
// Per spec REQ-SEM-001 (semantic-schema-markers) and ADR-048
// (schema.org marker convention) + ADR-037 (modular register fragments).

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

// A valid marker is either a CURIE (`prefix:LocalName`) or the `ns#Local`
// local-namespace form. `prefix` is a lower/mixed-case token; the local
// name is any non-empty string of word characters.
const CURIE_RE = /^([A-Za-z][A-Za-z0-9_-]*:[A-Za-z0-9_.-]+|ns#[A-Za-z0-9_.-]+)$/

function loadJson(file) {
	return JSON.parse(fs.readFileSync(file, 'utf8'))
}

function collectFiles() {
	const files = []
	if (fs.existsSync(MAIN_REGISTER)) files.push(MAIN_REGISTER)
	if (fs.existsSync(FRAGMENT_DIR) && fs.statSync(FRAGMENT_DIR).isDirectory()) {
		for (const fn of fs.readdirSync(FRAGMENT_DIR).sort()) {
			if (fn.endsWith('.json')) files.push(path.join(FRAGMENT_DIR, fn))
		}
	}
	return files
}

// Recursively walk any JSON value, collecting every `x-schema-org` string
// with the enclosing object's `slug`/`title` for a useful error message.
function walk(node, file, offenders, contextName) {
	if (Array.isArray(node)) {
		for (const item of node) walk(item, file, offenders, contextName)
		return
	}
	if (!node || typeof node !== 'object') return
	const name = node.slug || node.title || contextName
	if (Object.hasOwn(node, 'x-schema-org')) {
		const marker = node['x-schema-org']
		if (typeof marker !== 'string' || !CURIE_RE.test(marker)) {
			offenders.push({ file, name, marker })
		}
	}
	for (const [key, value] of Object.entries(node)) {
		if (key === 'x-schema-org') continue
		walk(value, file, offenders, name)
	}
}

function main() {
	const offenders = []
	let markerCount = 0
	for (const fp of collectFiles()) {
		let parsed
		try {
			parsed = loadJson(fp)
		} catch (err) {
			console.error(
				`[validate-semantic-markers] failed to parse ${fp}: ${err.message}`,
			)
			process.exit(1)
		}
		const before = offenders.length
		const countBox = { n: 0 }
		countMarkers(parsed, countBox)
		markerCount += countBox.n
		walk(parsed, fp, offenders, path.basename(fp))
		void before
	}

	if (offenders.length > 0) {
		console.error(
			'[validate-semantic-markers] FAIL — bare / malformed x-schema-org markers:',
		)
		for (const o of offenders) {
			console.error(
				`  ${path.relative(REPO_ROOT, o.file)} — ${o.name}: ${JSON.stringify(o.marker)}`,
			)
		}
		console.error(
			`\n${offenders.length} offending marker(s). Every marker MUST be a CURIE (prefix:Type or ns#Type).`,
		)
		process.exit(1)
	}
	console.log(
		`[validate-semantic-markers] OK — ${markerCount} x-schema-org marker(s) are valid CURIEs.`,
	)
}

function countMarkers(node, box) {
	if (Array.isArray(node)) {
		for (const item of node) countMarkers(item, box)
		return
	}
	if (!node || typeof node !== 'object') return
	if (Object.hasOwn(node, 'x-schema-org')) box.n += 1
	for (const value of Object.values(node)) countMarkers(value, box)
}

main()
