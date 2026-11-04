/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of `buildGenerateRequest` (Generate invoices dialog). Each
 * scenario pins the request as JSON text, so key order and the difference
 * between undefined, NaN and null are kept, and checks that the inputs are
 * not mutated or shared, compared with
 * tests/vitest/fixtures/invoiceGenerateRequest.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../src/modals/InvoiceGenerateWizard.vue', () => ({ default: {} }))
vi.mock('../../src/utils/usageBilling.js', () => ({ loadBillableReadings: vi.fn() }))

const { buildGenerateRequest } =
	await import('../../src/utils/invoiceGenerateWizard.js')

const GOLDEN = path.join(__dirname, 'fixtures/invoiceGenerateRequest.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const BASE = {
	billingModel: 't_and_m',
	customerId: 'c-1',
	fromDate: '2026-10-01',
	toDate: '2026-10-31',
}
const IDS = {
	timeEntryIds: ['t1', 't2'],
	expenseIds: ['e1'],
	meterReadingIds: ['m1'],
}

const SCENARIOS = {
	'time and materials': [BASE, IDS],
	'usage keeps the readings': [{ ...BASE, billingModel: 'usage' }, IDS],
	'usage without readings': [
		{ ...BASE, billingModel: 'usage' },
		{ timeEntryIds: ['t1'] },
	],
	'nothing chosen': [BASE, {}],
	'null id lists': [
		BASE,
		{ timeEntryIds: null, expenseIds: null, meterReadingIds: null },
	],
	'empty source': [{}, {}],
	'every reference': [
		{
			...BASE,
			projectId: 'p-1',
			rateCardId: 'rc-1',
			retainerScheduleId: 'rs-1',
			milestoneId: 'ms-1',
		},
		IDS,
	],
	'references in another order': [
		{ milestoneId: 'ms-1', projectId: 'p-1', ...BASE, rateCardId: 'rc-1' },
		IDS,
	],
	'empty and zero references': [
		{
			...BASE,
			projectId: '',
			rateCardId: 0,
			retainerScheduleId: null,
			milestoneId: undefined,
		},
		IDS,
	],
	'a numeric reference': [{ ...BASE, projectId: 7 }, IDS],
	'an unknown source field': [{ ...BASE, notes: 'x', fixedFeeCents: 5 }, IDS],
	'fixed fee as text with a comma': [
		{ ...BASE, billingModel: 'fixed_fee', fixedFee: '1250,55' },
		IDS,
	],
	'fixed fee as a number': [
		{ ...BASE, billingModel: 'fixed_fee', fixedFee: 99.995 },
		IDS,
	],
	'fixed fee with a dot': [
		{ ...BASE, billingModel: 'mixed', fixedFee: '10.5' },
		IDS,
	],
	'fixed fee with two commas': [
		{ ...BASE, billingModel: 'fixed_fee', fixedFee: '1,000,5' },
		IDS,
	],
	'fixed fee zero': [{ ...BASE, billingModel: 'fixed_fee', fixedFee: '0' }, IDS],
	'fixed fee negative': [{ ...BASE, billingModel: 'mixed', fixedFee: -3 }, IDS],
	'fixed fee not a number': [
		{ ...BASE, billingModel: 'fixed_fee', fixedFee: 'abc' },
		IDS,
	],
	'fixed fee empty': [{ ...BASE, billingModel: 'fixed_fee', fixedFee: '' }, IDS],
	'fixed fee null': [{ ...BASE, billingModel: 'fixed_fee', fixedFee: null }, IDS],
	'fixed fee infinite': [
		{ ...BASE, billingModel: 'fixed_fee', fixedFee: 'Infinity' },
		IDS,
	],
	'fixed fee on another model': [
		{ ...BASE, billingModel: 'retainer', fixedFee: '100' },
		IDS,
	],
	'fixed fee with references': [
		{
			...BASE,
			billingModel: 'mixed',
			fixedFee: '0.01',
			projectId: 'p-1',
			milestoneId: 'ms-2',
		},
		IDS,
	],
	'fixed fee rounding half': [
		{ ...BASE, billingModel: 'fixed_fee', fixedFee: '0.005' },
		IDS,
	],
}

/**
 * JSON text of a value that keeps undefined, NaN and -0 apart from null and 0.
 *
 * @param {*} value The request.
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

describe('buildGenerateRequest', () => {
	it.each(Object.keys(SCENARIOS))('%s', (title) => {
		const [source, chosen] = structuredClone(SCENARIOS[title])
		const before = faithful([source, chosen])
		const request = buildGenerateRequest(source, chosen)
		const shared = ['timeEntryIds', 'expenseIds', 'meterReadingIds'].filter(
			(key) => Array.isArray(chosen[key]) && request[key] === chosen[key],
		)
		const record = {
			request: faithful(request),
			inputsUnchanged: faithful([source, chosen]) === before,
			shared,
		}
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
