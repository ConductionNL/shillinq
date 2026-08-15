#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-registers.js — schema-level CI gate for capabilities
// bookkeeping-audit-trail (REQ-AT-001) and
// bookkeeping-rekenkamer-audit-pack (REQ-RAP-001).
//
// Asserts that every shillinq bookkeeping AND procurement register
// declares
//
//   "x-openregister-audit-trail": { "enabled": true }
//
// on its schema metadata. The check enumerates every JSON schema
// across `lib/Settings/shillinq_register.json` and every modular
// register fragment under `lib/Settings/register.d/*.json`, filters
// to the bookkeeping + procurement scope (= every schema NOT listed
// in NON_BOOKKEEPING below — note: procurement schemas like
// PurchaseOrder, Tender, Bid, AwardDecision are bookkeeping-by-default
// per REQ-RAP-001 and stay OUT of NON_BOOKKEEPING), and fails the
// build if any candidate is missing the audit-trail flag.
//
// The non-bookkeeping list is deliberately explicit — every NEW
// schema is bookkeeping-by-default and MUST therefore opt into OR's
// audit-trail-immutable abstraction per REQ-AT-001 / ADR-022. To
// genuinely exclude a non-bookkeeping schema (e.g. a new inventory
// or bookings register), add its slug to NON_BOOKKEEPING below with
// a one-line comment explaining why it is not bookkeeping. PRs that
// add bookkeeping schemas WITHOUT the audit-trail flag will fail
// this gate.
//
// Usage:
//   node tests/validate-registers.js
//
// Exit codes:
//   0 — every bookkeeping schema carries x-openregister-audit-trail.enabled=true
//   1 — one or more bookkeeping schemas are missing the flag (or a parse error)
//
// Per spec REQ-AT-001 (bookkeeping-audit-trail) AND REQ-RAP-001
// (bookkeeping-rekenkamer-audit-pack — Rekenkamer / Accountantscontrole
// audit pack) and ADR-022 (audit-trail-immutable from OR) + ADR-037
// (modular register fragments).

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

// Schemas that are NOT bookkeeping and therefore NOT subject to
// REQ-AT-001. Adding to this list is a deliberate opt-out — every
// new schema is bookkeeping-by-default. Each entry MUST carry a
// short comment explaining why it is not bookkeeping.
const NON_BOOKKEEPING = new Set([
	// Scaffolding example schema (not a real domain register).
	'example',
	// bookings capability — appointment / reservation scheduling.
	'Booking',
	'Appointment',
	'AppointmentSeries',
	'AvailabilityRule',
	'ConfirmationToken',
	'BookingConfirmationTemplate',
	'BookingReminderTemplate',
	'BookingCancellationTemplate',
	'BookingConstraint',
	'BookingNotificationTrigger',
	'BookingSmsReminderChannel',
	'Calendar',
	'DepositPayment',
	'Resource',
	'ResourceBreak',
	'Service',
	// notifications capability — notification dispatch + audit
	// (NotificationDelivery is OR-audited via its own immutable
	// contract; the audit-trail capability targets bookkeeping
	// registers per REQ-AT-001 scope).
	'NotificationDelivery',
	'TimelineDeadLetter',
	'TimelinePublishRetryEntry',
	// inventory capability — product / stock management. Not
	// bookkeeping; inventory has its own controls (stock movement
	// ledger, cycle count audit) outside REQ-AT-001 scope.
	'Product',
	'ProductAttribute',
	'Location',
	'InventoryStockTransfer',
	'InventoryStock',
	'Barcode',
	'InventoryCount',
	'InventoryCycleCount',
	'InventoryCycleCountLine',
	'InventoryLot',
	'InventoryReorderRule',
	'InventoryTransfer',
	'InventoryValuation',
	'InventoryVarianceReason',
	'MobileScannerSyncBatch',
	'BookingOrder',
	'OrderPick',
	'ExpiryAlert',
	'StockMove',
])

function loadJson(file) {
	const raw = fs.readFileSync(file, 'utf8')
	return JSON.parse(raw)
}

function collectSchemas() {
	const registry = {}
	const files = []
	if (fs.existsSync(MAIN_REGISTER)) files.push(MAIN_REGISTER)
	if (fs.existsSync(FRAGMENT_DIR) && fs.statSync(FRAGMENT_DIR).isDirectory()) {
		for (const fn of fs.readdirSync(FRAGMENT_DIR).sort()) {
			if (!fn.endsWith('.json')) continue
			files.push(path.join(FRAGMENT_DIR, fn))
		}
	}
	for (const fp of files) {
		let parsed
		try {
			parsed = loadJson(fp)
		} catch (err) {
			console.error(
				`[validate-registers] failed to parse ${fp}: ${err.message}`,
			)
			process.exit(1)
		}
		const schemas =
			(parsed && parsed.components && parsed.components.schemas) || {}
		for (const [slug, def] of Object.entries(schemas)) {
			if (!registry[slug])
				registry[slug] = { files: [], auditDeclaredIn: [], slug }
			registry[slug].files.push(fp)
			// The DB slug is the explicit `slug` when present, else the
			// components.schemas key. OpenRegister resolves the slug, not the
			// key. `files` is walked in the same sorted order that
			// SettingsService::deepMergeConfig() merges fragments in, and a
			// scalar in a later fragment overwrites the base — so the LAST
			// explicit `slug` is the one that reaches the database.
			if (def && typeof def.slug === 'string' && def.slug !== '') {
				registry[slug].slug = def.slug
			}
			const flag = def && def['x-openregister-audit-trail']
			if (flag && typeof flag === 'object' && flag.enabled === true) {
				registry[slug].auditDeclaredIn.push(fp)
			}
		}
	}
	return registry
}

// ── Case-insensitive slug collision check ────────────────────────────────
//
// OpenRegister resolves a schema slug CASE-INSENSITIVELY and non-
// deterministically. `SchemaMapper::findBySlugInIds()` runs
//
//   WHERE LOWER(slug) = :slug AND id IN (:registerSchemaIds)  LIMIT 1
//
// with NO ORDER BY, and `ObjectService::setSchema()` calls it for every
// string slug. So two schemas in the same register whose slugs differ only
// by case are ONE addressable schema as far as the app is concerned, and
// which of the two answers is whatever the database happens to return first.
//
// Measured on `development` (run 31110513361): this register shipped both
// `VatReturn` (Dutch BTW model — rubrieken / dueSalesTax /
// teBetalenOfTeruggave) and `VATReturn` (English filing model — returnNumber
// / periodYear / regime / statusCode). `VATReturnService::createReturn()`
// asked for `VATReturn`, OpenRegister handed back `VatReturn`, and the write
// was validated against the OTHER model's required list:
//
//   "The required properties (periodType, periodStart, periodEnd, rubrieken,
//    dueSalesTax, voorbelasting, teBetalenOfTeruggave) are
//    missing."
//
// POST /api/vat-returns answered 500, and because the Newman collection
// captures the new return's id from that response, EVERY downstream request
// in the collection ran against the literal string `{{return_id}}` — 14 of
// the 35 integration-test failures from ONE unresolvable name.
//
// This is not a style rule: no amount of correct application code can
// address a schema whose slug is ambiguous.
function checkSlugCaseCollisions(registry) {
	const byLower = new Map()
	for (const [key, entry] of Object.entries(registry)) {
		const lower = entry.slug.toLowerCase()
		if (!byLower.has(lower)) byLower.set(lower, [])
		byLower.get(lower).push({ key, slug: entry.slug, files: entry.files })
	}

	const collisions = []
	for (const [lower, entries] of byLower) {
		const distinct = new Set(entries.map((e) => e.slug))
		if (distinct.size > 1) collisions.push({ lower, entries })
	}

	console.log(`[validate-registers] distinct lower-cased slugs: ${byLower.size}`)
	if (collisions.length === 0) {
		console.log(
			'[validate-registers] PASS — no two schemas share a slug that differs only by case',
		)
		return true
	}

	console.error(
		'[validate-registers] FAIL — schemas whose slugs collide case-insensitively:',
	)
	for (const { lower, entries } of collisions) {
		console.error(`  - "${lower}" is claimed by ${entries.length} schemas:`)
		for (const e of entries) {
			const files = e.files.map((f) => path.relative(REPO_ROOT, f)).join(', ')
			console.error(
				`      slug "${e.slug}" (components key "${e.key}") declared in ${files}`,
			)
		}
	}
	console.error('')
	console.error(
		'[validate-registers] OpenRegister matches LOWER(slug) with LIMIT 1 and no ORDER BY,',
	)
	console.error(
		'[validate-registers] so only ONE of these is ever reachable and which one is undefined.',
	)
	console.error(
		'[validate-registers] Fix: give one of them a genuinely distinct slug (and update every',
	)
	console.error(
		'[validate-registers] setSchema()/find() call site), or merge them into a single schema.',
	)
	return false
}

function main() {
	const registry = collectSchemas()
	const all = Object.keys(registry).sort()
	const bookkeeping = all.filter((s) => !NON_BOOKKEEPING.has(s))
	const offenders = bookkeeping.filter(
		(s) => registry[s].auditDeclaredIn.length === 0,
	)

	console.log(`[validate-registers] total schemas: ${all.length}`)
	console.log(
		`[validate-registers] non-bookkeeping (skipped): ${all.length - bookkeeping.length}`,
	)
	console.log(
		`[validate-registers] bookkeeping (audit-trail required): ${bookkeeping.length}`,
	)
	console.log(
		`[validate-registers] schemas with x-openregister-audit-trail.enabled=true: ${bookkeeping.length - offenders.length}`,
	)

	const slugsOk = checkSlugCaseCollisions(registry)

	if (offenders.length === 0) {
		if (slugsOk === false) process.exit(1)
		console.log(
			'[validate-registers] PASS — every bookkeeping + procurement schema declares x-openregister-audit-trail.enabled=true (REQ-AT-001 / REQ-RAP-001)',
		)
		process.exit(0)
	}

	console.error(
		'[validate-registers] FAIL — the following bookkeeping/procurement schemas are missing x-openregister-audit-trail.enabled=true (REQ-AT-001 / REQ-RAP-001):',
	)
	for (const slug of offenders) {
		const file = registry[slug].files[0] || '(unknown)'
		console.error(`  - ${slug} (declared in ${path.relative(REPO_ROOT, file)})`)
	}
	console.error('')
	console.error('[validate-registers] Fix: add')
	console.error(
		'  "x-openregister-audit-trail": { "enabled": true, "description": "..." }',
	)
	console.error(
		'[validate-registers] to the schema fragment (typically the same register.d file that declares the schema).',
	)
	console.error(
		'[validate-registers] If the schema is NOT bookkeeping (e.g. inventory or bookings), add its slug to NON_BOOKKEEPING in tests/validate-registers.js with a one-line comment.',
	)
	process.exit(1)
}

main()
