/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of `buildProfilePayload` (recurring invoice profiles). Each
 * scenario pins the payload as JSON text, so key order and the difference
 * between undefined, NaN and null are kept, compared with
 * tests/vitest/fixtures/recurringProfilePayload.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it } from 'vitest'
import { buildProfilePayload } from '../../src/modals/recurringInvoiceProfile.js'

const GOLDEN = path.join(__dirname, 'fixtures/recurringProfilePayload.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const FULL = {
	name: '  Hosting  ',
	customerReference: ' cust-1 ',
	lines: [
		{
			description: ' Server ',
			quantity: '2',
			unitPrice: '49.5',
			vatCode: '21',
			revenueAccount: '8000',
		},
	],
	frequency: 'quarterly',
	interval: '3',
	startDate: '2026-01-01',
	invoiceDay: '15',
	issueMode: 'auto-send',
	deliveryChannel: 'peppol',
	paymentTermsDays: '14',
	currency: 'USD',
	status: 'active',
}

const SCENARIOS = {
	'every field set': FULL,
	'empty form': {},
	'only a name': { name: 'x' },
	'blank strings and zeros': {
		name: '   ',
		customerReference: '',
		frequency: '',
		interval: 0,
		invoiceDay: '0',
		issueMode: '',
		deliveryChannel: '',
		paymentTermsDays: 0,
		currency: '',
		status: '',
		startDate: '',
	},
	'non-numeric numbers': { interval: 'x', invoiceDay: 'y', paymentTermsDays: 'z' },
	'null name and customer': { name: null, customerReference: null },
	'lines null': { lines: null },
	'lines filtered and normalised': {
		lines: [
			{ description: '', unitPrice: 0 },
			{ description: '  ', unitPrice: '0' },
			{ description: 'Only text' },
			{ unitPrice: '10' },
			{ description: null, unitPrice: -5 },
			{ description: 'Text', unitPrice: 'abc', quantity: 0, vatCode: 'x' },
			{
				description: ' Both ',
				unitPrice: 3,
				quantity: '2.5',
				vatCode: 9,
				revenueAccount: null,
			},
			{
				description: 'Negative qty',
				unitPrice: 1,
				quantity: -1,
				vatCode: '0',
			},
		],
	},
	'indexation zero': { indexationPercent: 0 },
	'indexation as text': { indexationPercent: '2.5' },
	'indexation not a number': { indexationPercent: 'abc' },
	'indexation empty string': { indexationPercent: '' },
	'indexation null': { indexationPercent: null },
	'indexation undefined': { indexationPercent: undefined },
	'indexation false': { indexationPercent: false },
	'end date': { endDate: '2027-01-01' },
	'end date and occurrences': { endDate: '2027-01-01', occurrenceCount: 4 },
	occurrences: { occurrenceCount: '6' },
	'occurrences zero': { occurrenceCount: 0 },
	'occurrences not a number': { occurrenceCount: 'many' },
	'empty end date with occurrences': { endDate: '', occurrenceCount: 3 },
	'everything with indexation and end': {
		...FULL,
		indexationPercent: '3',
		occurrenceCount: 12,
	},
	'an extra form field is ignored': {
		name: 'x',
		id: 'p-1',
		remainingOccurrences: 9,
	},
}

/**
 * JSON text of a value that keeps undefined, NaN and -0 apart from null and 0.
 *
 * @param {*} value The payload.
 * @return {string} JSON text.
 */
function faithful(value) {
	return JSON.stringify(value, (key, item) => {
		if (item === undefined) {
			return '(undefined)'
		}
		if (typeof item === 'number' && Number.isNaN(item)) {
			return '(NaN)'
		}
		if (Object.is(item, -0)) {
			return '(-0)'
		}
		return item
	})
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('buildProfilePayload', () => {
	it.each(Object.keys(SCENARIOS))('%s', (title) => {
		const form = structuredClone(SCENARIOS[title])
		const before = faithful(form)
		const result = faithful(buildProfilePayload(form))
		const record = { payload: result, formUnchanged: faithful(form) === before }
		if (RECORD) {
			recorded[title] = record
			return
		}
		expect(record).toEqual(golden[title])
	})

	it('has no recorded scenario the spec no longer runs', () => {
		if (!RECORD) {
			expect(Object.keys(golden).sort()).toEqual(Object.keys(SCENARIOS).sort())
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
