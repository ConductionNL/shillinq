/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Shared harness for golden records under tests/vitest/fixtures.
 *
 * golden(name) returns check(key, value): with SHILLINQ_GOLDEN_RECORD=1 it
 * records the value under the key and writes fixtures/<name>.golden.json
 * after the file's tests; otherwise it compares the value with the record.
 * Values are made plain first, so Sets, Maps, undefined, NaN, -0, Dates and
 * thrown errors survive JSON. outcome(fn) runs fn and records either its
 * result or the message it threw. seeded(seed) is a small deterministic
 * random source for generated inputs.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, expect } from 'vitest'

/**
 * A JSON-safe copy of a value, with markers for what JSON drops.
 *
 * @param {*} value Any value.
 * @return {*} The plain value.
 */
export function plain(value) {
	if (value === undefined) return '__undefined'
	if (typeof value === 'number') {
		if (Number.isNaN(value)) return '__NaN'
		if (Object.is(value, -0)) return '__-0'
		if (!Number.isFinite(value)) return `__${value}`
		return value
	}
	if (typeof value === 'function') return '__function'
	if (value === null || typeof value !== 'object') return value
	if (value instanceof Date) return `__date:${Number.isNaN(value.getTime()) ? 'invalid' : value.toISOString()}`
	if (value instanceof Set) return { __set: [...value].map(plain) }
	if (value instanceof Map) return { __map: [...value].map(([k, v]) => [plain(k), plain(v)]) }
	if (Array.isArray(value)) return value.map(plain)
	return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, plain(v)]))
}

/**
 * Run fn and describe what happened: its result, or the error it threw.
 *
 * @param {Function} fn The call.
 * @return {object} `{ value }` or `{ threw }`.
 */
export function outcome(fn) {
	try {
		return { value: plain(fn()) }
	} catch (error) {
		return { threw: String(error?.message ?? error) }
	}
}

/**
 * Await fn and describe what happened: its result, or the error it threw.
 *
 * @param {Function} fn The async call.
 * @return {Promise<object>} `{ value }` or `{ threw }`.
 */
export async function asyncOutcome(fn) {
	try {
		return { value: plain(await fn()) }
	} catch (error) {
		return { threw: String(error?.message ?? error) }
	}
}

/**
 * A deterministic random source (mulberry32).
 *
 * @param {number} seed The seed.
 * @return {object} `{ next(), int(n), pick(list) }`.
 */
export function seeded(seed) {
	let state = seed >>> 0
	const next = () => {
		state = (state + 0x6d2b79f5) >>> 0
		let t = state
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
	return {
		next,
		int: (n) => Math.floor(next() * n),
		pick: (list) => list[Math.floor(next() * list.length)],
	}
}

/**
 * The checker of one golden record file.
 *
 * @param {string} name Record name, the file is fixtures/<name>.golden.json.
 * @return {Function} check(key, value).
 */
export function golden(name) {
	const file = path.join(__dirname, 'fixtures', `${name}.golden.json`)
	const record = process.env.SHILLINQ_GOLDEN_RECORD === '1'
	const recorded = {}
	const stored = record ? {} : JSON.parse(fs.readFileSync(file, 'utf8'))
	afterAll(() => {
		if (record) {
			fs.writeFileSync(file, `${JSON.stringify(recorded, null, '\t')}\n`)
		}
	})
	return (key, value) => {
		const actual = JSON.parse(JSON.stringify(plain(value)))
		if (record) {
			recorded[key] = actual
			return
		}
		expect(actual, key).toEqual(stored[key])
	}
}
