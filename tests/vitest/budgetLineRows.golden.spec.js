/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of normaliseBudgetLineRows: every payload shape the
 * aggregation endpoint (or an older OpenRegister) can return, compared with
 * tests/vitest/fixtures/budgetLineRows.golden.json. NaN, -0 and undefined
 * are recorded as markers.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it } from 'vitest'
import { normaliseBudgetLineRows } from '../../src/views/budgetLineCommitmentsHelpers.js'

const GOLDEN = path.join(__dirname, 'fixtures/budgetLineRows.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const GROUP = {
	keys: {
		programme: '5.1',
		costCentre: 'FAC',
		financialYear: 2026,
		generalLedgerAccount: '4000',
	},
	values: { sum_remaining_committed: 300, sum_invoiced_amount: 200 },
	joined: { 'CommitmentBudget.authorised_amount': 1000 },
}

const LEGACY = {
	programme: '6.2',
	cost_centre: 'ICT',
	financial_year: 2025,
	general_ledger_account: '4100',
	remaining_committed: '40',
	invoiced_amount: '10',
	geautoriseerd: '100',
}

const PAYLOADS = {
	'groups envelope': { groups: [GROUP] },
	'legacy buckets': { buckets: [LEGACY] },
	'bare array': [GROUP, LEGACY],
	'groups wins over buckets': { groups: [GROUP], buckets: [LEGACY] },
	'groups not an array': { groups: 'x', buckets: [LEGACY] },
	'nothing usable': { groups: null, buckets: {} },
	null: null,
	undefined,
	'a string': 'groups',
	'a number': 7,
	'empty groups': { groups: [] },
	'null and scalar groups': { groups: [null, 0, 'x', true] },
	'empty group': { groups: [{}] },
	'keys only': { groups: [{ keys: { programme: 'P' } }] },
	'snake keys inside keys': {
		groups: [
			{
				keys: {
					cost_centre: 'CC',
					financial_year: 0,
					general_ledger_account: 'GL',
				},
			},
		],
	},
	'camel beats snake': {
		groups: [
			{
				keys: {
					costCentre: 'A',
					cost_centre: 'B',
					financialYear: 1,
					financial_year: 2,
					generalLedgerAccount: 'C',
					general_ledger_account: 'D',
				},
			},
		],
	},
	'null keys fall back': {
		groups: [
			{
				keys: {
					programme: null,
					costCentre: null,
					cost_centre: 'S',
					financialYear: null,
				},
			},
		],
	},
	'values with plain names': {
		groups: [{ values: { remaining_committed: 5, invoiced_amount: 6 } }],
	},
	'values beat legacy fields': {
		groups: [
			{
				values: { sum_remaining_committed: 1, sum_invoiced_amount: 2 },
				verplicht: 9,
				gerealiseerd: 9,
			},
		],
	},
	'null values fall back to legacy': {
		groups: [
			{
				values: { sum_remaining_committed: null, sum_invoiced_amount: null },
				verplicht: 3,
				gerealiseerd: 4,
			},
		],
	},
	'joined null falls back': {
		groups: [
			{
				joined: { 'CommitmentBudget.authorised_amount': null },
				geautoriseerd: 50,
			},
		],
	},
	'joined missing, group legacy amount': {
		groups: [{ joined: {}, geautoriseerd: 70 }],
	},
	'non-numeric amounts': {
		groups: [
			{
				values: { sum_remaining_committed: 'abc', sum_invoiced_amount: '' },
				joined: { 'CommitmentBudget.authorised_amount': 'x' },
			},
		],
	},
	'over-committed': {
		groups: [
			{
				values: { sum_remaining_committed: 900, sum_invoiced_amount: 500 },
				joined: { 'CommitmentBudget.authorised_amount': 1000 },
			},
		],
	},
	'numeric programme and zero year': {
		groups: [{ keys: { programme: 0, financialYear: 0, costCentre: 12 } }],
	},
	'zero dimensions': {
		groups: [{ keys: { programme: 0, costCentre: 0, generalLedgerAccount: 0 } }],
	},
	'negative zero amounts': {
		groups: [
			{
				values: { sum_remaining_committed: 0, sum_invoiced_amount: 0 },
				joined: { 'CommitmentBudget.authorised_amount': -0 },
			},
		],
	},
}

/**
 * Make a value JSON-faithful: NaN, -0 and undefined become marker strings.
 *
 * @param {*} value Any recorded value.
 * @return {*} The same value with the markers in place.
 */
function faithful(value) {
	if (value === undefined) {
		return '(undefined)'
	}
	if (Number.isNaN(value)) {
		return '(NaN)'
	}
	if (Object.is(value, -0)) {
		return '(-0)'
	}
	if (Array.isArray(value)) {
		return value.map((item) => faithful(item))
	}
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(
			Object.entries(value).map(([key, item]) => [key, faithful(item)]),
		)
	}
	return value
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('normaliseBudgetLineRows', () => {
	it.each(Object.keys(PAYLOADS))('%s', (title) => {
		let result
		try {
			result = faithful(normaliseBudgetLineRows(PAYLOADS[title]))
		} catch (e) {
			result = { threw: e.constructor.name }
		}
		if (RECORD) {
			recorded[title] = result
			return
		}
		expect(result).toEqual(golden[title])
	})

	it('has no recorded scenario the spec no longer runs', () => {
		if (!RECORD) {
			expect(Object.keys(golden).sort()).toEqual(Object.keys(PAYLOADS).sort())
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
