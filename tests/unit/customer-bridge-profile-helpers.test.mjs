/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure-Node unit tests for the slice-06 profile card / klantbeeld
 * timeline helpers. Mirrors the inventory-mobile-scanner-helpers
 * pattern: pure ESM, no Vue or NC runtime dependency, runnable as
 * `node --test tests/unit/customer-bridge-profile-helpers.test.mjs`.
 *
 * Covers the three test tasks in the slice-06 spec:
 *   - template rendering with valid Contact data — buildProfileFields()
 *     produces the expected ordered field list.
 *   - rendering with invalid / missing Contact data — selectProfileState()
 *     classifies unlinked / error / notfound; buildProfileFields() omits
 *     missing optionals; classifyContact() handles bad inputs.
 *   - history rendering with up to 5 entries + Load more —
 *     selectHistoryState() classifies the four envelope states and
 *     nextPageParams() advances offset by limit.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'

import {
	classifyContact,
	buildProfileFields,
	selectProfileState,
	selectHistoryState,
	formatTransactionAmount,
	formatTransactionDate,
	nextPageParams,
	buildPipelinqLink,
} from '../../src/composables/usePipelinqProfile.js'

// ---------------------------------------------------------------------------
// classifyContact
// ---------------------------------------------------------------------------

test('classifyContact returns organization for a contact with KvK', () => {
	const contact = { legalName: 'Acme Corp', kvkNumber: '12345678', found: true }
	assert.equal(classifyContact(contact), 'organization')
})

test('classifyContact returns individual when KvK is absent', () => {
	const contact = { legalName: 'Jane Doe', email: 'jane@example.com', found: true }
	assert.equal(classifyContact(contact), 'individual')
})

test('classifyContact returns null for missing / not-found contact', () => {
	assert.equal(classifyContact(null), null)
	assert.equal(classifyContact({ found: false }), null)
	assert.equal(classifyContact(undefined), null)
})

test('classifyContact treats whitespace-only KvK as no-KvK', () => {
	const contact = { legalName: 'Jane Doe', kvkNumber: '   ', found: true }
	assert.equal(classifyContact(contact), 'individual')
})

// ---------------------------------------------------------------------------
// buildProfileFields — valid + missing / invalid Contact data
// ---------------------------------------------------------------------------

test('buildProfileFields produces ordered list for full organization contact', () => {
	const contact = {
		externalId: 'cnt-1',
		legalName: 'Acme Corp',
		email: 'acme@example.com',
		phone: '+31 6 1234 5678',
		address: 'Hoofdweg 1, 1000 AA Amsterdam',
		kvkNumber: '12345678',
		found: true,
	}
	const fields = buildProfileFields(contact)
	assert.deepEqual(
		fields.map((f) => f.key),
		['legalName', 'kvkNumber', 'email', 'phone', 'address'],
	)
	const byKey = Object.fromEntries(fields.map((f) => [f.key, f]))
	assert.equal(byKey.legalName.emphasis, true)
	assert.equal(byKey.email.href, 'mailto:acme@example.com')
	// Spaces stripped from tel: href.
	assert.equal(byKey.phone.href, 'tel:+31612345678')
	assert.equal(byKey.address.value, 'Hoofdweg 1, 1000 AA Amsterdam')
})

test('buildProfileFields omits missing optional fields entirely (no empty labels)', () => {
	const contact = {
		externalId: 'cnt-2',
		legalName: 'Jane Doe',
		email: '',
		phone: null,
		address: '',
		kvkNumber: '',
		found: true,
	}
	const fields = buildProfileFields(contact)
	assert.deepEqual(fields.map((f) => f.key), ['legalName'])
})

test('buildProfileFields returns empty list for not-found contact', () => {
	assert.deepEqual(buildProfileFields(null), [])
	assert.deepEqual(buildProfileFields({ found: false }), [])
})

test('buildProfileFields trims whitespace before deciding presence', () => {
	const contact = {
		legalName: '   Acme Corp   ',
		email: '  ',
		phone: 'tel-value',
		kvkNumber: '\t12345\n',
		found: true,
	}
	const fields = buildProfileFields(contact)
	const byKey = Object.fromEntries(fields.map((f) => [f.key, f]))
	assert.equal(byKey.legalName.value, 'Acme Corp')
	assert.equal(byKey.kvkNumber.value, '12345')
	assert.equal('email' in byKey, false, 'whitespace-only email is omitted')
})

// ---------------------------------------------------------------------------
// selectProfileState — unlinked / error / notfound / ok
// ---------------------------------------------------------------------------

test('selectProfileState returns unlinked when notLinkedToPipelinq', () => {
	const payload = {
		booking: { appointmentId: 'apt-1' },
		contact: null,
		contactError: null,
		notLinkedToPipelinq: true,
	}
	assert.equal(selectProfileState(payload), 'unlinked')
})

test('selectProfileState returns error when contactError is set', () => {
	const payload = {
		booking: { appointmentId: 'apt-1', pipelinqContactId: 'cnt-1' },
		contact: null,
		contactError: 'Customer profile temporarily unavailable.',
		notLinkedToPipelinq: false,
	}
	assert.equal(selectProfileState(payload), 'error')
})

test('selectProfileState returns notfound when contact.found is false', () => {
	const payload = {
		booking: { appointmentId: 'apt-1', pipelinqContactId: 'cnt-1' },
		contact: { externalId: 'cnt-1', found: false },
		contactError: null,
		notLinkedToPipelinq: false,
	}
	assert.equal(selectProfileState(payload), 'notfound')
})

test('selectProfileState returns ok when contact is found', () => {
	const payload = {
		booking: { appointmentId: 'apt-1', pipelinqContactId: 'cnt-1' },
		contact: { externalId: 'cnt-1', legalName: 'Acme', found: true },
		contactError: null,
		notLinkedToPipelinq: false,
	}
	assert.equal(selectProfileState(payload), 'ok')
})

test('selectProfileState returns error for a missing payload', () => {
	assert.equal(selectProfileState(null), 'error')
	assert.equal(selectProfileState(undefined), 'error')
})

// ---------------------------------------------------------------------------
// selectHistoryState — history rendering with up to 5 entries + load-more
// ---------------------------------------------------------------------------

const okPayload = (klantbeeld) => ({
	booking: { appointmentId: 'apt-1', pipelinqContactId: 'cnt-1' },
	contact: { externalId: 'cnt-1', legalName: 'Acme', found: true },
	contactError: null,
	notLinkedToPipelinq: false,
	klantbeeld,
})

test('selectHistoryState returns ok with up to 5 transactions', () => {
	const klantbeeld = {
		transactions: Array.from({ length: 5 }, (_, i) => ({
			date: `2026-06-0${i + 1}`,
			description: `Invoice ${i + 1}`,
			amount: 100 + i,
			currency: 'EUR',
			status: 'paid',
		})),
		limit: 5,
		offset: 0,
		unavailable: false,
		empty: false,
	}
	assert.equal(selectHistoryState(okPayload(klantbeeld)), 'ok')
})

test('selectHistoryState returns empty when envelope reports empty', () => {
	const klantbeeld = { transactions: [], limit: 5, offset: 0, unavailable: false, empty: true }
	assert.equal(selectHistoryState(okPayload(klantbeeld)), 'empty')
})

test('selectHistoryState returns empty when transactions array is empty (without empty flag)', () => {
	const klantbeeld = { transactions: [], limit: 5, offset: 0, unavailable: false }
	assert.equal(selectHistoryState(okPayload(klantbeeld)), 'empty')
})

test('selectHistoryState returns unavailable when envelope reports unavailable', () => {
	const klantbeeld = { transactions: [], limit: 5, offset: 0, unavailable: true }
	assert.equal(selectHistoryState(okPayload(klantbeeld)), 'unavailable')
})

test('selectHistoryState returns hidden when profile is not ok', () => {
	const klantbeeld = { transactions: [], limit: 5, offset: 0, unavailable: false }
	const payload = {
		...okPayload(klantbeeld),
		notLinkedToPipelinq: true,
		contact: null,
	}
	assert.equal(selectHistoryState(payload), 'hidden')
})

test('selectHistoryState returns hidden when klantbeeld envelope is missing', () => {
	const payload = okPayload(null)
	assert.equal(selectHistoryState(payload), 'hidden')
})

// ---------------------------------------------------------------------------
// nextPageParams — Load more advances offset by limit
// ---------------------------------------------------------------------------

test('nextPageParams advances offset by limit', () => {
	assert.deepEqual(
		nextPageParams({ limit: 5, offset: 0 }),
		{ limit: 5, offset: 5 },
	)
	assert.deepEqual(
		nextPageParams({ limit: 5, offset: 5 }),
		{ limit: 5, offset: 10 },
	)
})

test('nextPageParams falls back to defaults when envelope is missing fields', () => {
	assert.deepEqual(nextPageParams(null), { limit: 5, offset: 5 })
	assert.deepEqual(nextPageParams({}), { limit: 5, offset: 5 })
	assert.deepEqual(nextPageParams({ limit: 'bogus' }), { limit: 5, offset: 5 })
})

test('nextPageParams clamps limit to >= 1', () => {
	assert.deepEqual(
		nextPageParams({ limit: 0, offset: 0 }),
		{ limit: 1, offset: 1 },
	)
})

// ---------------------------------------------------------------------------
// formatTransactionAmount / formatTransactionDate
// ---------------------------------------------------------------------------

test('formatTransactionAmount renders the row currency with 2 decimals', () => {
	assert.equal(formatTransactionAmount({ amount: 100, currency: 'EUR' }), 'EUR 100.00')
	assert.equal(formatTransactionAmount({ amount: 12.5, currency: 'USD' }), 'USD 12.50')
})

test('formatTransactionAmount falls back to EUR when currency is missing', () => {
	assert.equal(formatTransactionAmount({ amount: 42 }), 'EUR 42.00')
})

test('formatTransactionAmount renders 0.00 when the amount is non-numeric', () => {
	assert.equal(formatTransactionAmount({ amount: 'bogus', currency: 'EUR' }), 'EUR 0.00')
	assert.equal(formatTransactionAmount({}), 'EUR 0.00')
})

test('formatTransactionDate returns YYYY-MM-DD slice for an ISO timestamp', () => {
	assert.equal(formatTransactionDate('2026-06-07T12:34:56Z'), '2026-06-07')
})

test('formatTransactionDate returns input verbatim for malformed dates', () => {
	assert.equal(formatTransactionDate('not-a-date'), 'not-a-date')
	assert.equal(formatTransactionDate(''), '')
	assert.equal(formatTransactionDate(null), '')
})

// ---------------------------------------------------------------------------
// buildPipelinqLink
// ---------------------------------------------------------------------------

test('buildPipelinqLink composes the external contact URL', () => {
	assert.equal(
		buildPipelinqLink('https://pipelinq.example.org', 'cnt-42'),
		'https://pipelinq.example.org/contacts/cnt-42',
	)
})

test('buildPipelinqLink trims trailing slashes on base URL', () => {
	assert.equal(
		buildPipelinqLink('https://pipelinq.example.org///', 'cnt-42'),
		'https://pipelinq.example.org/contacts/cnt-42',
	)
})

test('buildPipelinqLink URL-encodes the external id', () => {
	assert.equal(
		buildPipelinqLink('https://pipelinq.example.org', 'cnt 42/special'),
		'https://pipelinq.example.org/contacts/cnt%2042%2Fspecial',
	)
})

test('buildPipelinqLink returns null when base URL or id is missing', () => {
	assert.equal(buildPipelinqLink('', 'cnt-1'), null)
	assert.equal(buildPipelinqLink('https://pipelinq.example.org', ''), null)
	assert.equal(buildPipelinqLink(null, null), null)
})
