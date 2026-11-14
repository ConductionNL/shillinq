/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of buildRaiseRequest over generated down-payment dialog states:
 * the request body, key order included, for orders shillinq holds or not,
 * modes, typed amounts, dates and VAT breakdowns.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildRaiseRequest } from '../../src/utils/downPaymentApi.js'

const FIXTURE = path.join(__dirname, 'fixtures', 'downPaymentRequest.golden.json')

/**
 * A deterministic random source (mulberry32).
 *
 * @param {number} seed The seed.
 * @return {Function} A function returning a value in [0, 1).
 */
function random(seed) {
	let a = seed
	return () => {
		a = (a + 0x6d2b79f5) | 0
		let t = Math.imul(a ^ (a >>> 15), 1 | a)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

const IDS = ['adm-1', '', undefined, 7]
const ORDERS = [
	undefined,
	null,
	{ value: 'o-1', label: 'Order 1', shillinq: true },
	{ value: 'o-2', label: 'Order 2', shillinq: false },
	{ value: 3, label: undefined },
	{},
]
const AMOUNTS = [
	'10',
	'10,5',
	' 1 000,25 ',
	'',
	undefined,
	null,
	25,
	'abc',
	'-3',
	'0',
]
const MODES = ['amount', 'percentage', undefined]
const DATES = ['2026-02-01', '', undefined]

/**
 * A generated dialog state.
 *
 * @param {Function} next Random source.
 * @return {object}
 */
function draft(next) {
	const pick = (list) => list[Math.floor(next() * list.length)]
	const form = {
		administrationId: pick(IDS),
		customerId: pick(IDS),
		order: pick(ORDERS),
		mode: pick(MODES),
		value: pick(AMOUNTS),
		invoiceDate: pick(DATES),
	}
	if (next() < 0.8) {
		form.rates = { 21: pick(AMOUNTS), 9: pick(AMOUNTS), 0: pick(AMOUNTS) }
	}
	return form
}

/**
 * The value with NaN and undefined marked, so JSON keeps them apart from null.
 *
 * @param {object} value The request.
 * @return {object}
 */
function marked(value) {
	return JSON.parse(
		JSON.stringify(value, (key, v) => {
			if (typeof v === 'number' && Number.isNaN(v)) {
				return '__NaN'
			}
			return v === undefined ? '__undefined' : v
		}),
	)
}

describe('down payment request golden', () => {
	it('matches the record', () => {
		const next = random(31)
		const actual = []
		for (let i = 0; i < 600; i++) {
			const form = draft(next)
			actual.push({
				form: JSON.stringify(form),
				request: marked(buildRaiseRequest(form)),
			})
		}
		actual.push({ form: '{}', request: marked(buildRaiseRequest({})) })
		if (process.env.SHILLINQ_GOLDEN_RECORD === '1') {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
		expect(actual.length).toBe(expected.length)
		actual.forEach((record, i) => expect(record).toEqual(expected[i]))
	})
})
