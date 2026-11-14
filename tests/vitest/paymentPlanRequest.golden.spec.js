/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of buildPlanRequest over generated payment-plan dialog states:
 * the request body, key order included, for modes, ids, numbers, frequencies
 * and charge flags that are valid, blank, missing or of the wrong type.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-4.1
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildPlanRequest } from '../../src/utils/paymentPlanApi.js'

const FIXTURE = path.join(__dirname, 'fixtures', 'paymentPlanRequest.golden.json')

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

const IDS = ['adm-1', '', undefined, null, 0, 42]
const NUMBERS = [3, '4', '', undefined, null, 0, '2.5', 'abc', -1]
const MODES = ['amount', 'count', undefined, 'AMOUNT']
const FREQUENCIES = ['weekly', 'monthly', 'daily', undefined]
const FLAGS = [true, false, undefined, 'yes', 0]

/**
 * A generated dialog state.
 *
 * @param {Function} next Random source.
 * @return {object}
 */
function draft(next) {
	const pick = (list) => list[Math.floor(next() * list.length)]
	const form = {
		mode: pick(MODES),
		administrationId: pick(IDS),
		customerId: pick(IDS),
		instalmentCount: pick(NUMBERS),
		instalmentAmount: pick(NUMBERS),
		frequency: pick(FREQUENCIES),
		firstDueDate: pick(['2026-02-01', '', undefined]),
		graceDays: pick(NUMBERS),
		includesCharges: pick(FLAGS),
		chargesAmount: pick(NUMBERS),
		agreedWith: pick(IDS),
		note: pick(IDS),
	}
	if (next() < 0.8) {
		form.invoiceIds = ['inv-1', 'inv-2'].slice(0, Math.floor(next() * 3))
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

describe('payment plan request golden', () => {
	it('matches the record', () => {
		const next = random(31)
		const actual = []
		for (let i = 0; i < 600; i++) {
			const form = draft(next)
			actual.push({
				form: JSON.stringify(form),
				request: marked(buildPlanRequest(form)),
			})
		}
		actual.push({ form: '{}', request: marked(buildPlanRequest({})) })
		if (process.env.SHILLINQ_GOLDEN_RECORD === '1') {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
		expect(actual.length).toBe(expected.length)
		actual.forEach((record, i) => expect(record).toEqual(expected[i]))
	})
})
