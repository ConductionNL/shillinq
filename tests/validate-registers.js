#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-registers.js — CI gate that asserts every bookkeeping and
// procurement schema in lib/Settings/shillinq_register.json carries the
// `x-openregister-audit: true` extension flag.
//
// A schema is considered a financial/compliance schema if it appears in the
// hardcoded BOOKKEEPING_SCHEMAS list below, OR if it declares
// `x-openregister-lifecycle` or an `x-openregister-rbac` block whose roles
// include any of: bookkeeper, auditor, compliance-officer.
//
// Usage:
//   node tests/validate-registers.js
//
// Exit codes:
//   0 — every identified financial/compliance schema has x-openregister-audit: true
//       (schemas not yet present in the register are counted as "not-yet-added"
//        and do NOT cause a non-zero exit)
//   1 — one or more identified schemas are present in the register but are
//       missing x-openregister-audit: true

'use strict'

const fs   = require('fs')
const path = require('path')

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const REGISTER_PATH = path.resolve(__dirname, '..', 'lib', 'Settings', 'shillinq_register.json')

/**
 * Hardcoded list of schema slugs that MUST carry x-openregister-audit: true.
 * Organised by tier for readability; the tier comments are informational only.
 */
const BOOKKEEPING_SCHEMAS = [
	// T1 — core general-ledger
	'Account',
	'GLLine',
	'GLTransaction',
	'JournalEntry',
	// T2 — accounts payable / receivable
	'APInvoice',
	'ARInvoice',
	'APTransaction',
	'DunningNotice',
	// T2 — procurement
	'PurchaseOrder',
	'Tender',
	'Bid',
	'AwardDecision',
	// T3 — payment and approval
	'Payment',
	'Receipt',
	'ApprovalRequest',
	// existing GR / Iv3 schemas
	'GRDeelnemer',
	'GRVerdeelsleutel',
	'Iv3Export',
]

/**
 * RBAC role names that signal a financial/compliance schema.
 * A schema with x-openregister-rbac whose roles array contains any of these
 * is treated as a financial/compliance schema even if its slug is not listed
 * in BOOKKEEPING_SCHEMAS.
 */
const FINANCIAL_ROLES = new Set([
	'bookkeeper',
	'auditor',
	'compliance-officer',
])

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Load and parse a JSON file.  Throws with a human-readable message on
 * any filesystem or parse error.
 *
 * @param {string} filePath Absolute path to the JSON file.
 * @return {object} Parsed JSON value.
 */
function loadJson(filePath) {
	let raw
	try {
		raw = fs.readFileSync(filePath, 'utf8')
	} catch (err) {
		throw new Error(`Cannot read ${filePath}: ${err.message}`)
	}
	try {
		return JSON.parse(raw)
	} catch (err) {
		throw new Error(`Cannot parse ${filePath}: ${err.message}`)
	}
}

/**
 * Return true if the schema definition's x-openregister-rbac block
 * contains at least one financial/compliance role.
 *
 * @param {object} schemaDef The schema definition object from the register.
 * @return {boolean}
 */
function hasFinancialRole(schemaDef) {
	const rbac = schemaDef['x-openregister-rbac']
	if (!rbac || typeof rbac !== 'object') return false

	// The rbac block may expose roles as an array directly, or as keys of an
	// object (e.g. { bookkeeper: {…}, auditor: {…} }).  Support both shapes.
	let roleNames = []
	if (Array.isArray(rbac.roles)) {
		roleNames = rbac.roles
	} else if (rbac.roles && typeof rbac.roles === 'object') {
		roleNames = Object.keys(rbac.roles)
	} else if (typeof rbac === 'object') {
		// Top-level keys of the rbac block are the role names.
		roleNames = Object.keys(rbac)
	}

	return roleNames.some((role) => FINANCIAL_ROLES.has(String(role).toLowerCase()))
}

/**
 * Return true if the schema definition declares x-openregister-lifecycle,
 * which signals domain-workflow complexity typical of financial schemas.
 *
 * @param {object} schemaDef The schema definition object from the register.
 * @return {boolean}
 */
function hasLifecycle(schemaDef) {
	return Object.prototype.hasOwnProperty.call(schemaDef, 'x-openregister-lifecycle')
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main() {
	// Load the register.
	if (!fs.existsSync(REGISTER_PATH)) {
		console.error(`[validate-registers] ERROR: register not found: ${REGISTER_PATH}`)
		process.exit(1)
	}

	let register
	try {
		register = loadJson(REGISTER_PATH)
	} catch (err) {
		console.error(`[validate-registers] ERROR: ${err.message}`)
		process.exit(1)
	}

	const schemas = (register.components && register.components.schemas) || {}
	console.log(`[validate-registers] register: ${REGISTER_PATH}`)
	console.log(`[validate-registers] schemas in register: ${Object.keys(schemas).length}`)

	// Build the full set of slugs to check.
	// Start with the hardcoded list, then add any dynamically detected schemas.
	const toCheck = new Set(BOOKKEEPING_SCHEMAS)

	for (const [key, def] of Object.entries(schemas)) {
		if (!def || typeof def !== 'object') continue
		const slug = def.slug || key
		if (hasLifecycle(def) || hasFinancialRole(def)) {
			if (!toCheck.has(slug)) {
				console.log(`[validate-registers] INFO: dynamically identified financial schema: ${slug}`)
				toCheck.add(slug)
			}
		}
	}

	// Build a lookup from slug → schema definition for O(1) access.
	// Keys in `schemas` may differ from the `slug` field; support both.
	const bySlug = {}
	for (const [key, def] of Object.entries(schemas)) {
		if (!def || typeof def !== 'object') continue
		const slug = def.slug || key
		bySlug[slug] = def
		// Also index by the object key itself so "GLLine" matches whether the
		// key or the slug field is used as the canonical identifier.
		if (key !== slug) bySlug[key] = def
	}

	// Validate each schema in toCheck.
	let passed      = 0
	let failed      = 0
	let notYetAdded = 0

	for (const slug of [...toCheck].sort()) {
		const def = bySlug[slug]

		if (def === undefined) {
			console.warn(`[validate-registers] WARN: ${slug} not found in register (will be added by dependent spec)`)
			notYetAdded++
			continue
		}

		if (def['x-openregister-audit'] === true) {
			console.log(`[validate-registers] PASS: ${slug} has x-openregister-audit: true`)
			passed++
		} else {
			console.error(`[validate-registers] FAIL: ${slug} is missing x-openregister-audit: true`)
			failed++
		}
	}

	// Summary line.
	console.log(
		`[validate-registers] Result: ${passed} passed, ${failed} failed, ${notYetAdded} not-yet-added`,
	)

	process.exit(failed === 0 ? 0 : 1)
}

main()
