/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of validateProfile over generated recurring-invoice profile
 * drafts: every error, in order, for names, customers, lines, start dates and
 * invoice days that are valid, blank, padded, malformed or of the wrong type.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/recurring-invoicing/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { validateProfile } from '../../src/modals/recurringInvoiceProfile.js'

const FIXTURE = path.join(
	__dirname,
	'fixtures',
	'recurringProfileValidation.golden.json',
)

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

const TEXTS = ['Huur', '', '   ', ' Abonnement ', undefined, null]
const PRICES = [10, 0, -5, '12.50', '', 'abc', undefined, 0.01]
const DATES = [
	'2026-01-01',
	'2026-1-1',
	'',
	undefined,
	'26-01-01',
	'2026-13-45',
	'x2026-01-01',
]
const DAYS = [1, 31, 0, 32, 15, '15', '1.5', 1.5, '', undefined, null, -1, 'abc']

/**
 * A generated profile draft.
 *
 * @param {Function} next Random source.
 * @return {object}
 */
function draft(next) {
	const pick = (list) => list[Math.floor(next() * list.length)]
	const lines = []
	const count = Math.floor(next() * 4)
	for (let i = 0; i < count; i++) {
		lines.push({ description: pick(TEXTS), unitPrice: pick(PRICES) })
	}
	return {
		name: pick(TEXTS),
		customerReference: pick(TEXTS),
		lines: next() < 0.1 ? undefined : lines,
		startDate: pick(DATES),
		invoiceDay: pick(DAYS),
	}
}

describe('recurring profile validation golden', () => {
	it('matches the record', () => {
		const next = random(31)
		const actual = []
		for (let i = 0; i < 600; i++) {
			const form = draft(next)
			actual.push({
				form: JSON.stringify(form),
				errors: validateProfile(form),
			})
		}
		actual.push({ form: '{}', errors: validateProfile({}) })
		const valid = {
			name: 'Huur',
			customerReference: 'cust-1',
			lines: [{ description: 'Huur', unitPrice: 10 }],
			startDate: '2026-01-01',
			invoiceDay: 1,
		}
		for (const change of [
			{},
			{ invoiceDay: 31 },
			{ invoiceDay: '15' },
			{ name: ' Huur ' },
			{
				lines: [
					{ description: '', unitPrice: 5 },
					{ description: 'x', unitPrice: '0.01' },
				],
			},
		]) {
			const form = { ...valid, ...change }
			actual.push({
				form: JSON.stringify(form),
				errors: validateProfile(form),
			})
		}
		if (process.env.SHILLINQ_GOLDEN_RECORD === '1') {
			fs.mkdirSync(path.dirname(FIXTURE), { recursive: true })
			fs.writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		const expected = JSON.parse(fs.readFileSync(FIXTURE, 'utf8'))
		expect(actual.length).toBe(expected.length)
		actual.forEach((record, i) => expect(record).toEqual(expected[i]))
	})
})
