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

// components.schemas keys that were ALREADY declared as a full definition
// (type + required) by 2+ register.d fragments before the same-slug
// full-definition check below existed. contracts-single-home added that
// check specifically to close the `Contract` / `bookkeeping-ifrs15-revenue`
// collision (design.md §D1) — `Contract` is deliberately NOT in this list,
// because that is the exact collision the change fixes; once the IFRS-15
// side renames to `RevenueContract`, "Contract" no longer collides and
// needs no entry here.
//
// Everything else below was discovered mechanically the moment the check
// was switched on, scanning the WHOLE register (not just Contract) — a
// pre-existing debt class this change did not create and is out of scope to
// fix here (per its own Non-goals: "no CLM feature work, no IFRS-15
// revenue-recognition logic changes... no pipelinq-repo changes"; the
// `Invoice`/`CreditNote` pair below is explicitly the same "union-merge
// debt" the semantic-invoice-consume spec delta names as owned by whichever
// change consolidates `ARInvoice` into a canonical `Invoice`, NOT this one).
// ADR-020 diff-scoping: a new mechanical gate must not retroactively fail a
// PR on debt that PR did not introduce. Each entry MUST carry the colliding
// file pair/group and stay OUT of this list once its own change fixes it —
// this is a tracked backlog, not a permanent exemption.
const PRE_EXISTING_SAME_SLUG_COLLISIONS = new Set([
	// 10-bookings-create-appointment.json vs bookings-service-catalog.json /
	// 10-bookings-resource-calendar.json — numbered-prefix (10-bookings-*)
	// fragments appear to duplicate later unprefixed (bookings-*) fragments;
	// looks like an in-progress fragment-rename that never finished.
	'Service',
	'Resource',
	// 10-bookings-email-templates.json vs bookings-email-templates.json —
	// same numbered-prefix-vs-unprefixed duplicate pattern.
	'BookingConfirmationTemplate',
	'BookingReminderTemplate',
	'BookingCancellationTemplate',
	// 10-bookings-resource-calendar.json vs bookings-resource-calendar.json —
	// same pattern.
	'Calendar',
	'Booking',
	// 30-bookings-self-service-widget.json vs
	// bookings-self-service-widget.json — same pattern.
	'WidgetAccessKey',
	// 50-bookings-deposits.json / bookings-deposit-to-invoice.json vs
	// bookkeeping-quote-order-invoice.json — the bookings deposit-to-invoice
	// bridge duplicates core quote-order-invoice schemas. `Invoice` here is
	// the SAME union-merge debt the semantic-invoice-consume spec delta
	// (contracts-single-home) explicitly attributes to "whichever change
	// consolidates ARInvoice into a canonical Invoice" — not this change.
	'DepositPayment',
	'CreditNote',
	'Invoice',
	'InvoiceLine',
	// bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed.json vs
	// -02-aggregation-compliance.json — the 01/02 slice split duplicates
	// full definitions instead of the 02 fragment being a partial overlay.
	'BBVProgramme',
	'BudgetBBVMapping',
	// add-shillinq-bookkeeping-operations.json vs
	// bookkeeping-bcf-vat-compensation.json.
	'BcfClaim',
	// add-shillinq-bookkeeping-compliance.json vs
	// bookkeeping-period-close.json.
	'FiscalPeriod',
	// add-shillinq-bookkeeping-gr-consolidation.json vs
	// bookkeeping-intercompany-elimination.json vs
	// bookkeeping-treasury-ihb.json (3-way).
	'IntercompanyTransaction',
	// bookkeeping-consolidation-commercial.json vs
	// bookkeeping-intercompany-elimination.json.
	'IntercompanyRelation',
	// bookkeeping-bank-reconciliation.json vs
	// bookkeeping-reconciliation-reports.json.
	'BankReconciliation',
	// bookkeeping-provincies-bbv-variant.json vs
	// bookkeeping-verplichtingenadministratie.json.
	'Budget',
])

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
				registry[slug] = {
					files: [],
					auditDeclaredIn: [],
					fullDefinitionFiles: [],
					slug,
				}
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
			// Scoped to register.d FRAGMENTS only (per the spec Requirement's own
			// wording: "two or more `register.d` source files"). The monolith
			// `shillinq_register.json` is deliberately excluded here: fragments
			// routinely restate a full definition for a schema the monolith
			// already carries (the pre-ADR-037 baseline predates the fragment
			// split), and `deepMergeConfig` scalar-overwrite semantics mean a
			// later fragment's `required` still concatenates onto the base's —
			// a large, separate, pre-existing debt class across ~60 schemas that
			// is out of scope for this collision gate to police. This check's
			// job is the ADR-037 fragment-vs-fragment case that has no single
			// "authoritative base" to defer to — exactly the Contract collision.
			if (fp !== MAIN_REGISTER && isFullSchemaDefinition(def)) {
				registry[slug].fullDefinitionFiles.push(fp)
			}
		}
	}
	return registry
}

// A "full" schema definition declares both `type` and `required` — i.e. it
// is a complete OpenAPI object schema in its own right, not a partial
// ADR-037 augmentation overlay (e.g. `configuration`/extra `properties`/
// `x-openregister-handoff` only, with no `type`/`required` of its own — the
// legitimate pattern used by fragments like semantic-invoice-consume.json).
function isFullSchemaDefinition(def) {
	return (
		def !== null &&
		typeof def === 'object' &&
		Object.prototype.hasOwnProperty.call(def, 'type') &&
		Object.prototype.hasOwnProperty.call(def, 'required')
	)
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

// ── Same-slug full-definition collision check ────────────────────────────
//
// `checkSlugCaseCollisions` above catches two DIFFERENT slugs that collide
// case-insensitively. It does NOT catch two register.d fragments declaring
// the IDENTICAL `components.schemas` key as two separate FULL schema bodies
// — the defect `contracts-single-home` exists to close: before that change,
// both `contract-lifecycle-management.json` and
// `bookkeeping-ifrs15-revenue.json` declared a full `Contract` (type +
// required), and `SettingsService::deepMergeConfig()` deep-merged the two
// into one schema spanning two unrelated domains (concatenated `required`,
// unioned lifecycle states/transitions) — the exact same merge algorithm
// that makes the case-collision check above necessary, applied to an
// IDENTICAL key instead of a case-varying one.
//
// A fragment that only adds a partial ADR-037 augmentation (e.g.
// `configuration.implements`, one extra `property`, `x-openregister-handoff`
// — no `type`/`required` of its own, the pattern
// `semantic-invoice-consume.json` uses to layer a handoff binding onto
// CLM's `Contract`) is NOT a full definition and MUST NOT be flagged.
function checkSameSlugFullDefinitionCollisions(registry) {
	const collisions = []
	const preExistingSkipped = []
	for (const [key, entry] of Object.entries(registry)) {
		if (entry.fullDefinitionFiles.length > 1) {
			if (PRE_EXISTING_SAME_SLUG_COLLISIONS.has(key)) {
				preExistingSkipped.push(key)
				continue
			}
			collisions.push({ key, slug: entry.slug, files: entry.fullDefinitionFiles })
		}
	}

	if (preExistingSkipped.length > 0) {
		console.log(
			`[validate-registers] same-slug full-definition check: ${preExistingSkipped.length} pre-existing collision(s) skipped via PRE_EXISTING_SAME_SLUG_COLLISIONS (tracked backlog, see tests/validate-registers.js)`,
		)
	}

	if (collisions.length === 0) {
		console.log(
			'[validate-registers] PASS — no NEW components.schemas key is declared as a full definition (type + required) by 2+ register.d files',
		)
		return true
	}

	console.error(
		'[validate-registers] FAIL — components.schemas keys declared as a FULL definition (both `type` and `required`) by 2+ source files:',
	)
	for (const { key, slug, files } of collisions) {
		console.error(`  - "${key}" (slug "${slug}") is fully declared by ${files.length} files:`)
		for (const f of files) {
			console.error(`      ${path.relative(REPO_ROOT, f)}`)
		}
	}
	console.error('')
	console.error(
		'[validate-registers] SettingsService::deepMergeConfig() deep-merges same-key components.schemas',
	)
	console.error(
		'[validate-registers] entries: two full definitions concatenate `required` (array_merge) and deep-merge',
	)
	console.error(
		'[validate-registers] `properties`/`x-openregister-lifecycle`/`x-openregister-notifications` into one',
	)
	console.error(
		'[validate-registers] ambiguous schema spanning two unrelated domains.',
	)
	console.error(
		'[validate-registers] Fix: rename one schema to a genuinely distinct slug (and update every consumer —',
	)
	console.error(
		'[validate-registers] manifest.d schema references, FK descriptions, seed objects, setSchema() call sites,',
	)
	console.error(
		'[validate-registers] test assertions), or convert one fragment to a partial augmentation (drop its own',
	)
	console.error(
		'[validate-registers] `type`/`required` and only add `configuration`/extra `properties`/handoff metadata).',
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
	const sameSlugFullDefinitionOk = checkSameSlugFullDefinitionCollisions(registry)

	if (offenders.length === 0) {
		if (slugsOk === false || sameSlugFullDefinitionOk === false) process.exit(1)
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
