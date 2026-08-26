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
					props: new Set(),
					aggregations: [],
					slug,
				}
			registry[slug].files.push(fp)
			// Accumulate the MERGED property set across every fragment that
			// touches this slug — a cross-schema field reference must be
			// checked against what the schema ends up with after
			// deepMergeConfig(), not against one fragment in isolation.
			if (def && def.properties && typeof def.properties === 'object') {
				for (const p of Object.keys(def.properties))
					registry[slug].props.add(p)
			}
			const aggs = def && def['x-openregister-aggregations']
			if (aggs && typeof aggs === 'object') {
				for (const [aggName, agg] of Object.entries(aggs)) {
					if (agg && typeof agg === 'object')
						registry[slug].aggregations.push({ aggName, agg, file: fp })
				}
			}
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
		def !== null
		&& typeof def === 'object'
		&& Object.hasOwn(def, 'type')
		&& Object.hasOwn(def, 'required')
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
			collisions.push({
				key,
				slug: entry.slug,
				files: entry.fullDefinitionFiles,
			})
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
		console.error(
			`  - "${key}" (slug "${slug}") is fully declared by ${files.length} files:`,
		)
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

// A declarative aggregation can name a field on ANOTHER schema
// (`join.on`, `join.select`) or a computed `formula` over its OWN fields.
// Nothing resolves those names at import time, so a rename on the target
// schema leaves the reference stranded and the aggregation silently
// resolves to nothing — it does not error, it just never matches.
//
// Found live on 2026-08-21: `CommitmentLine`'s
// `committedVsRealisedPerBudgetLine` joined `CommitmentBudget.programmaCode`
// and selected `geautoriseerd_bedrag`/`gerealiseerd_bedrag`, while the
// schema had been migrated to English (`programmeCode`, `authorised_amount`,
// `realised_amount`) — three dead references. `CommitmentBudget.free_capacity`
// carried a fourth in its formula. All four were the Dutch half of a rename
// that moved the properties but not the references to them.
//
// Deliberately CONSERVATIVE about formulas: only expressions built purely
// from bare identifiers, integers and + - * / ( ) are checked. Anything
// richer (`@self`, `??`, `sum(...)`, dotted paths — all of which appear
// legitimately elsewhere in register.d) is skipped rather than guessed at,
// because a noisy gate gets switched off and a gate that is off protects
// nothing.
const FORMULA_SIMPLE_RE =
	/^[A-Za-z_][A-Za-z0-9_]*(?:\s*[-+*/]\s*(?:[A-Za-z_][A-Za-z0-9_]*|\d+))*$/
const FORMULA_IDENT_RE = /[A-Za-z_][A-Za-z0-9_]*/g

// Every OpenRegister object carries these regardless of what the schema's
// `properties` declares, so a reference to one is legitimate and must not
// be flagged.
const IMPLICIT_OBJECT_FIELDS = new Set([
	'id',
	'uuid',
	'uri',
	'version',
	'created',
	'updated',
	'published',
	'depublished',
	'owner',
	'organisation',
	'application',
	'register',
	'schema',
])

// `join.on` appears in two shapes in the wild:
//   "CommitmentBudget.programmeCode"                                (bare)
//   "CashflowRecurring.administrationId = CashflowWeek.administrationId"  (equality)
// Splitting naively on the first dot turns the second into a field named
// `administrationId = CashflowWeek.administrationId`, which is a parser bug,
// not a finding. Split on `=` first and validate each side independently.
function qualifiedRefs(join) {
	const refs = []
	if (!join || typeof join !== 'object') return refs
	if (typeof join.on === 'string') {
		for (const side of join.on.split('=')) {
			const trimmed = side.trim()
			if (trimmed !== '') refs.push({ key: 'join.on', ref: trimmed })
		}
	}
	if (Array.isArray(join.select)) {
		for (const s of join.select)
			if (typeof s === 'string')
				refs.push({ key: 'join.select', ref: s.trim() })
	}
	if (typeof join.from === 'string')
		refs.push({ key: 'join.from', ref: join.from.trim() })
	return refs
}

// `groupBy` entries are either a bare field on the source schema or a
// qualified `Other.field`. Only the qualified form is checked here — the
// bare form would need the source schema resolved, which `source` /
// `sourceSchema` spell inconsistently across fragments. Checked because a
// stranded groupBy reference is exactly how the BBV programma roll-up
// silently grouped by nothing.
function groupByRefs(agg) {
	const refs = []
	if (!Array.isArray(agg.groupBy)) return refs
	for (const g of agg.groupBy) {
		if (typeof g === 'string' && g.includes('.') === true)
			refs.push({ key: 'groupBy', ref: g.trim() })
	}
	return refs
}

// Known-unresolved references that are NOT a mechanical rename and must not
// be guessed at. Each entry is a decision waiting on a human, not a defect
// this gate should silently tolerate forever — the point of listing them
// here (rather than deleting the check) is that the gate keeps protecting
// every OTHER reference while these stay visible.
//
// `GLLine.fiscalYearId`: GLLine has no fiscal-year property at all. Its
// nearest field is `periodId`, but a period is a FINER grain than a year,
// so substituting it would silently change what these P&L roll-ups group
// by — an architecture decision (add a fiscalYearId to GLLine, or join
// through GLTransaction/Period to derive the year), not a rename.
const AGGREGATION_REF_BASELINE = new Map([
	[
		'GLLine.fiscalYearId',
		'GLLine declares no fiscal-year field; periodId is a finer grain, so this needs a schema decision, not a rename. Affects AnalyticalDimension.segmentPnl, AnalyticalDimension.segmentPnlByCostObject, Project.segmentPnl.',
	],
])

// `@`-prefixed placeholders in an aggregation's filter are NOT resolved.
//
// OpenRegister's PlaceholderResolver acts only on values beginning with `$`
// (it implements `$currentUser` and the date expressions). A value starting
// with `@` fails that test and is returned UNCHANGED, so the filter that runs
// is a literal string comparison against `"@self.whatever"` — which matches
// nothing. The aggregation then returns an empty result with HTTP 200, and the
// page renders its own empty state over live data. That is issue #1216, and it
// was found only by querying a real instance; every unit test and gate was
// green throughout.
//
// TWO TIERS, deliberately:
//
//   1. `administrationId` / `organisationId` — ZERO tolerance. These are
//      tenant scoping, and they have all been removed. An administration is a
//      shillinq-specific layer over OpenRegister's organisation tenancy, so it
//      is NOT `@self` metadata: it is a normal property, and the CALLER passes
//      it as a narrowing filter (`?filter[administrationId]=...`), which
//      openregister#2852 accepts and can never relax. A new one appearing means
//      somebody re-introduced an aggregation that silently returns nothing.
//
//   2. every other `@self.*` — RATCHET. Those are a different intent ("this
//      object's field", e.g. `@self.id`), and deleting them would widen the
//      aggregation rather than fix it. They need the placeholder to be
//      implemented, not removed. Tracked in #1255; the count may fall, never
//      rise.
const AGG_PLACEHOLDER_TENANT_KEYS = new Set(['administrationId', 'organisationId'])
// Measured 2026-08-26 by this check, after removing 67 tenant placeholders
// across 22 files. Counted BY THE GATE, not by a one-off script — an earlier
// estimate of 73 came from a narrower hand-written predicate and was wrong.
const AGG_PLACEHOLDER_BASELINE = 84

function collectPlaceholders(node, path, out) {
	if (node === null || node === undefined) return
	if (typeof node === 'string') {
		if (node.includes('@self.')) out.push({ path, value: node })
		return
	}
	if (Array.isArray(node)) {
		node.forEach((v, i) => collectPlaceholders(v, `${path}[${i}]`, out))
		return
	}
	if (typeof node === 'object') {
		for (const [k, v] of Object.entries(node)) {
			collectPlaceholders(v, path ? `${path}.${k}` : k, out)
		}
	}
}

function checkAggregationPlaceholders(registry) {
	const tenant = []
	const others = []

	for (const slug of Object.keys(registry).sort()) {
		for (const { aggName, agg, file } of registry[slug].aggregations) {
			const found = []
			for (const key of ['filter', 'where', 'join', 'match']) {
				collectPlaceholders(agg[key], key, found)
			}
			// NOT `path` — that shadows the `path` module this file already
			// imports, and the shadow only bites on the failure branch.
			for (const { path: at, value } of found) {
				const leaf = at.split('.').pop().replace(/\[\d+\]$/, '')
				const where = `${slug}.${aggName} ${at} = ${JSON.stringify(value)}`
					+ ` (${path.relative(REPO_ROOT, file)})`
				if (AGG_PLACEHOLDER_TENANT_KEYS.has(leaf)) tenant.push(where)
				else others.push(where)
			}
		}
	}

	console.log(
		`[validate-registers] aggregation @self placeholders: ${tenant.length} tenant, `
			+ `${others.length} other (baseline ${AGG_PLACEHOLDER_BASELINE})`,
	)

	let ok = true

	if (tenant.length > 0) {
		console.error(
			'[validate-registers] FAIL — an aggregation scopes a tenant with an `@self` placeholder, '
				+ 'which OpenRegister does not resolve. The filter runs as a literal string, matches '
				+ 'nothing, and the aggregation returns an empty result with HTTP 200 (#1216):',
		)
		for (const t of tenant) console.error(`  - ${t}`)
		console.error('')
		console.error(
			'[validate-registers] Fix: remove the key from the declaration and have the CALLER pass it — '
				+ '`?filter[administrationId]=<active>`. An administration is a normal property, not @self metadata.',
		)
		console.error(
			'[validate-registers] Removing it WITHOUT a caller that supplies one turns "returns nothing" '
				+ 'into "returns every administration", so change the caller first.',
		)
		ok = false
	}

	if (others.length > AGG_PLACEHOLDER_BASELINE) {
		console.error(
			`[validate-registers] FAIL — @self placeholders in aggregation filters rose to ${others.length}, `
				+ `above the baseline of ${AGG_PLACEHOLDER_BASELINE}. These resolve to nothing (#1255).`,
		)
		ok = false
	} else if (others.length < AGG_PLACEHOLDER_BASELINE) {
		console.log(
			`[validate-registers] ${AGG_PLACEHOLDER_BASELINE - others.length} better than baseline — `
				+ `please lower AGG_PLACEHOLDER_BASELINE to ${others.length}.`,
		)
	}

	return ok
}

function checkAggregationFieldRefs(registry) {
	const problems = []
	const baselined = []
	let checkedRefs = 0

	for (const slug of Object.keys(registry).sort()) {
		for (const { aggName, agg, file } of registry[slug].aggregations) {
			for (const { key, ref } of [
				...qualifiedRefs(agg.join),
				...groupByRefs(agg),
			]) {
				const dot = ref.indexOf('.')
				if (dot === -1) continue
				const targetSlug = ref.slice(0, dot)
				const field = ref.slice(dot + 1)
				const target = registry[targetSlug]
				// An unknown target schema is a different defect class
				// (and may live in another app's register) — not this check's job.
				if (!target) continue
				if (IMPLICIT_OBJECT_FIELDS.has(field) === true) continue
				checkedRefs++
				if (
					target.props.has(field) === false
					&& AGGREGATION_REF_BASELINE.has(ref) === true
				) {
					baselined.push(`${slug}.${aggName} ${key}="${ref}"`)
					continue
				}
				if (target.props.has(field) === false) {
					problems.push(
						`${slug}.${aggName} ${key}="${ref}" — ${targetSlug} has no property "${field}" `
							+ `(it has: ${[...target.props].sort().join(', ')})\n      declared in ${file}`,
					)
				}
			}

			if (agg.type === 'calculation' && typeof agg.formula === 'string') {
				const f = agg.formula.trim()
				if (FORMULA_SIMPLE_RE.test(f) === true) {
					for (const ident of f.match(FORMULA_IDENT_RE) || []) {
						checkedRefs++
						if (registry[slug].props.has(ident) === false) {
							problems.push(
								`${slug}.${aggName} formula references "${ident}", which is not a property of ${slug} `
									+ `(it has: ${[...registry[slug].props].sort().join(', ')})\n      declared in ${file}`,
							)
						}
					}
				}
			}
		}
	}

	console.log(
		`[validate-registers] aggregation field references checked: ${checkedRefs}`,
	)
	// Never let a baselined entry pass silently — a waiver nobody sees is a
	// waiver nobody revisits.
	if (baselined.length > 0) {
		console.log(
			`[validate-registers] aggregation refs BASELINED (known-unresolved, awaiting a decision): ${baselined.length}`,
		)
		// Deliberately NOT the "  - " prefix the failure list uses: I misread
		// my own output once while building this, grepping "^  - " and seeing
		// waived entries as findings. A waiver must not look like a failure.
		for (const b of baselined) {
			const reason = AGGREGATION_REF_BASELINE.get(
				b.slice(b.indexOf('"') + 1, b.lastIndexOf('"')),
			)
			console.log(`  ~ [baselined] ${b}\n      reason: ${reason}`)
		}
	}
	// A check that examined nothing must not report success.
	if (checkedRefs === 0) {
		console.error(
			'[validate-registers] FAIL — the aggregation field-reference check resolved ZERO references. '
				+ 'That means it stopped seeing its own subject (parsing or shape drift), not that the registers are clean.',
		)
		return false
	}
	if (problems.length === 0) {
		console.log(
			'[validate-registers] PASS — every checkable aggregation field reference resolves to a real property',
		)
		return true
	}
	console.error(
		'[validate-registers] FAIL — aggregation field references that cannot ever resolve:',
	)
	for (const p of problems) console.error(`  - ${p}`)
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
	const aggregationRefsOk = checkAggregationFieldRefs(registry)
	const aggregationPlaceholdersOk = checkAggregationPlaceholders(registry)

	if (offenders.length === 0) {
		if (
			slugsOk === false
			|| sameSlugFullDefinitionOk === false
			|| aggregationRefsOk === false
			|| aggregationPlaceholdersOk === false
		)
			process.exit(1)
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
