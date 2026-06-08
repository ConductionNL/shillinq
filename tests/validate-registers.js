#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-registers.js — schema-level CI gate for capability
// bookkeeping-audit-trail (REQ-AT-001).
//
// Asserts that every shillinq bookkeeping register declares
//
//   "x-openregister-audit-trail": { "enabled": true }
//
// on its schema metadata. The check enumerates every JSON schema
// across `lib/Settings/shillinq_register.json` and every modular
// register fragment under `lib/Settings/register.d/*.json`, filters
// to the bookkeeping scope (= every schema NOT listed in
// NON_BOOKKEEPING below), and fails the build if any candidate is
// missing the audit-trail flag.
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
// Per spec REQ-AT-001 / capability bookkeeping-audit-trail and
// ADR-022 (audit-trail-immutable from OR) + ADR-037 (modular
// register fragments).

'use strict'

const fs = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..')
const MAIN_REGISTER = path.join(REPO_ROOT, 'lib', 'Settings', 'shillinq_register.json')
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
	'Order',
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
			console.error(`[validate-registers] failed to parse ${fp}: ${err.message}`)
			process.exit(1)
		}
		const schemas = (parsed && parsed.components && parsed.components.schemas) || {}
		for (const [slug, def] of Object.entries(schemas)) {
			if (!registry[slug]) registry[slug] = { files: [], auditDeclaredIn: [] }
			registry[slug].files.push(fp)
			const flag = def && def['x-openregister-audit-trail']
			if (flag && typeof flag === 'object' && flag.enabled === true) {
				registry[slug].auditDeclaredIn.push(fp)
			}
		}
	}
	return registry
}

function main() {
	const registry = collectSchemas()
	const all = Object.keys(registry).sort()
	const bookkeeping = all.filter((s) => !NON_BOOKKEEPING.has(s))
	const offenders = bookkeeping.filter((s) => registry[s].auditDeclaredIn.length === 0)

	console.log(`[validate-registers] total schemas: ${all.length}`)
	console.log(`[validate-registers] non-bookkeeping (skipped): ${all.length - bookkeeping.length}`)
	console.log(`[validate-registers] bookkeeping (audit-trail required): ${bookkeeping.length}`)
	console.log(`[validate-registers] schemas with x-openregister-audit-trail.enabled=true: ${bookkeeping.length - offenders.length}`)

	if (offenders.length === 0) {
		console.log('[validate-registers] PASS — every bookkeeping schema declares x-openregister-audit-trail.enabled=true (REQ-AT-001)')
		process.exit(0)
	}

	console.error('[validate-registers] FAIL — the following bookkeeping schemas are missing x-openregister-audit-trail.enabled=true (REQ-AT-001):')
	for (const slug of offenders) {
		const file = registry[slug].files[0] || '(unknown)'
		console.error(`  - ${slug} (declared in ${path.relative(REPO_ROOT, file)})`)
	}
	console.error('')
	console.error('[validate-registers] Fix: add')
	console.error('  "x-openregister-audit-trail": { "enabled": true, "description": "..." }')
	console.error('[validate-registers] to the schema fragment (typically the same register.d file that declares the schema).')
	console.error('[validate-registers] If the schema is NOT bookkeeping (e.g. inventory or bookings), add its slug to NON_BOOKKEEPING in tests/validate-registers.js with a one-line comment.')
	process.exit(1)
}

main()
