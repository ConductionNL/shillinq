/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the BankStatementWizard computation + persistence layer
 * (src/modals/bankStatementWizard.js): the format option descriptors, the
 * IBAN → GL-account localStorage memory round-trip with a 1-year TTL
 * (REQ-BSW-006), the import payload shape (REQ-BSW-004) and the
 * sessionStorage return-breadcrumb (REQ-BSW-005).
 *
 * The wizard's pure logic lives in this sibling .js (mirroring
 * invoiceQuickDraft.js) so it is testable in the node-env Vitest suite
 * without importing the .vue SFC (whose deep @nextcloud/vue NcDialog import
 * drags in a .css asset node cannot load). The step-advance behaviour that
 * consumes this logic — "remembered IBAN skips step 2", "advance from step 1
 * runs the import" — is driven by loadIbanMapping() asserted here.
 */

import { describe, it, expect, beforeEach } from 'vitest'
import {
	formatOptions,
	normalizeIban,
	loadIbanMapping,
	saveIbanMapping,
	buildImportPayload,
	setReturnBreadcrumb,
	BREADCRUMB_FLAG,
} from '../../src/modals/bankStatementWizard.js'

const IBAN_MAP_KEY = 'shillinq:bank-iban-map'

function fakeStorage() {
	const store = {}
	return {
		getItem: (k) => (k in store ? store[k] : null),
		setItem: (k, v) => {
			store[k] = String(v)
		},
		removeItem: (k) => {
			delete store[k]
		},
	}
}

beforeEach(() => {
	globalThis.localStorage = fakeStorage()
	globalThis.sessionStorage = fakeStorage()
})

describe('bankStatementWizard — format options (step 1)', () => {
	it('offers exactly camt053, mt940 and csv with accept hints', () => {
		const opts = formatOptions()
		expect(opts.map((o) => o.value)).toEqual(['camt053', 'mt940', 'csv'])
		for (const o of opts) {
			expect(typeof o.accept).toBe('string')
			expect(o.accept.length).toBeGreaterThan(0)
		}
	})
})

describe('bankStatementWizard — IBAN normalisation', () => {
	it('strips spaces and upper-cases', () => {
		expect(normalizeIban('nl91 abna 0417 1643 00')).toBe('NL91ABNA0417164300')
		expect(normalizeIban('')).toBe('')
		expect(normalizeIban(null)).toBe('')
	})
})

describe('bankStatementWizard — IBAN → account memory (REQ-BSW-006)', () => {
	it('round-trips a mapping (step 2 skip on repeat imports)', () => {
		saveIbanMapping('NL91ABNA0417164300', '10100')
		expect(loadIbanMapping('NL91ABNA0417164300')).toBe('10100')
		// Normalisation means a spaced/lower-cased IBAN still resolves.
		expect(loadIbanMapping('nl91 abna 0417 1643 00')).toBe('10100')
	})

	it('keeps multiple IBANs in the same map', () => {
		saveIbanMapping('NL91ABNA0417164300', '10100')
		saveIbanMapping('NL00BANK0987654321', '10200')
		expect(loadIbanMapping('NL91ABNA0417164300')).toBe('10100')
		expect(loadIbanMapping('NL00BANK0987654321')).toBe('10200')
	})

	it('returns null for an unknown or empty IBAN', () => {
		expect(loadIbanMapping('NL00UNKNOWN0000000000')).toBeNull()
		expect(loadIbanMapping('')).toBeNull()
	})

	it('does not persist when the GL account is missing', () => {
		saveIbanMapping('NL91ABNA0417164300', '')
		expect(loadIbanMapping('NL91ABNA0417164300')).toBeNull()
	})

	it('expires mappings older than one year', () => {
		saveIbanMapping('NL91ABNA0417164300', '10100')
		const raw = JSON.parse(globalThis.localStorage.getItem(IBAN_MAP_KEY))
		raw.savedAt = Date.now() - 366 * 24 * 60 * 60 * 1000
		globalThis.localStorage.setItem(IBAN_MAP_KEY, JSON.stringify(raw))
		expect(loadIbanMapping('NL91ABNA0417164300')).toBeNull()
	})

	it('never throws when localStorage is unavailable', () => {
		globalThis.localStorage = undefined
		expect(() => saveIbanMapping('NL91ABNA0417164300', '10100')).not.toThrow()
		expect(loadIbanMapping('NL91ABNA0417164300')).toBeNull()
	})
})

describe('bankStatementWizard — import payload (REQ-BSW-004)', () => {
	it('builds the JSON body posted to the import endpoint', () => {
		const payload = buildImportPayload({
			format: 'camt053',
			contents: '<Document/>',
			glAccountId: '10100',
		})
		expect(payload).toEqual({
			format: 'camt053',
			contents: '<Document/>',
			glAccountId: '10100',
			encoding: 'utf8',
		})
	})

	it('coerces missing fields to empty strings', () => {
		const payload = buildImportPayload({})
		expect(payload.format).toBe('')
		expect(payload.contents).toBe('')
		expect(payload.glAccountId).toBe('')
	})
})

describe('bankStatementWizard — return breadcrumb (REQ-BSW-005)', () => {
	it('persists the dashboard origin + statement id', () => {
		setReturnBreadcrumb('BS-2026-001')
		const raw = JSON.parse(globalThis.sessionStorage.getItem(BREADCRUMB_FLAG))
		expect(raw.from).toBe('financial-overview')
		expect(raw.statementId).toBe('BS-2026-001')
	})

	it('never throws when sessionStorage is unavailable', () => {
		globalThis.sessionStorage = undefined
		expect(() => setReturnBreadcrumb('BS-2026-001')).not.toThrow()
	})
})
