/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the `handleConfirm` method of the TransferOp and PickOp
 * inventory operations. Each scenario runs the method on a fake instance
 * (the component's data plus its methods) with a fake scanner store, and
 * compares the instance's data afterwards, every stock read and every
 * submitted operation with tests/vitest/fixtures/inventoryOpConfirm.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'

const stock = vi.hoisted(() => ({ reads: [], answer: null }))

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	readStockQuantity: async (db, sku, location) => {
		stock.reads.push({ db: db === null ? null : db.name, sku, location })
		return stock.answer(sku, location)
	},
}))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => null,
}))
vi.mock('../../src/components/inventory/BarcodeScanner.vue', () => ({
	default: { name: 'BarcodeScanner' },
}))

const { default: TransferOp } =
	await import('../../src/components/inventory/TransferOp.vue')
const { default: PickOp } = await import('../../src/components/inventory/PickOp.vue')

const GOLDEN = path.join(__dirname, 'fixtures/inventoryOpConfirm.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const NOW = 100000
const recorded = {}

/**
 * Translate to a visible string that carries the app, text and variables.
 *
 * @param {string} app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The fake translation.
 */
function fakeT(app, text, vars) {
	return vars === undefined
		? `${app}:${text}`
		: `${app}:${text}|${JSON.stringify(vars)}`
}

/**
 * Describe a thrown value so it can be stored in the record.
 *
 * @param {*} error The thrown value.
 * @return {*} A JSON-safe description.
 */
function describeThrown(error) {
	return error instanceof Error ? `Error:${error.message}` : error
}

/**
 * Run handleConfirm for one scenario and return what it did.
 *
 * @param {object} component The component options.
 * @param {object} scenario Fields, store and stock behaviour.
 * @return {Promise<object>} The resulting data, reads and submits.
 */
async function run(component, scenario) {
	stock.reads = []
	stock.answer = scenario.stock ?? (() => 100)
	const submits = []
	const vm = { ...component.data(), ...scenario.fields, t: fakeT }
	vm.store = {
		db: scenario.db === false ? null : { name: 'db' },
		submitOperation: async (op) => {
			submits.push({ op, submitting: vm.submitting })
			await new Promise((resolve) => setTimeout(resolve, 0))
			if ('submitError' in scenario) {
				throw scenario.submitError
			}
		},
	}
	for (const [name, fn] of Object.entries(component.methods)) {
		vm[name] = fn.bind(vm)
	}
	const nowSpy = vi.spyOn(Date, 'now').mockReturnValue(NOW)
	let returned
	let thrown = null
	try {
		returned = await vm.handleConfirm()
	} catch (e) {
		thrown = describeThrown(e)
	} finally {
		nowSpy.mockRestore()
	}
	const data = {}
	for (const key of Object.keys(component.data())) {
		data[key] = vm[key] === undefined ? '(undefined)' : vm[key]
	}
	return {
		data,
		reads: stock.reads,
		submits,
		returned: returned === undefined ? '(undefined)' : returned,
		thrown,
	}
}

/**
 * A stock read that fails.
 */
function failing() {
	throw new Error('idb gone')
}

const TRANSFER_VALID = {
	fromLocation: 'A',
	toLocation: 'B',
	sku: 'S1',
	quantity: 3,
	error: 'stale',
	successMessage: 'stale',
}

const TRANSFER = {
	'valid without a db': { db: false },
	'valid with more stock': { stock: () => 5 },
	'valid with exactly the stock': { stock: () => 3 },
	'valid with less stock': { stock: () => 2 },
	'valid with an undefined stock': { stock: () => undefined },
	'stock read throws': { stock: failing },
	'missing source': { fields: { fromLocation: '' } },
	'missing destination': { fields: { toLocation: '' } },
	'missing sku': { fields: { sku: '' } },
	'null quantity': { fields: { quantity: null } },
	'zero quantity': { fields: { quantity: 0 } },
	'negative quantity': { fields: { quantity: -1 } },
	'text quantity': { fields: { quantity: 'abc' } },
	'fractional quantity': { fields: { quantity: 0.5 }, stock: () => 0.5 },
	'same locations': { fields: { toLocation: 'A' } },
	'same locations and no sku': { fields: { toLocation: 'A', sku: '' } },
	'submitted 4999 ms ago': { fields: { lastSubmittedAt: NOW - 4999 } },
	'submitted 5000 ms ago': { fields: { lastSubmittedAt: NOW - 5000 } },
	'submitted in the future': { fields: { lastSubmittedAt: NOW + 1 } },
	'less stock and recently submitted': {
		fields: { lastSubmittedAt: NOW - 1 },
		stock: () => 1,
	},
	'submit fails with a message': { submitError: new Error('server said no') },
	'submit fails without a message': { submitError: new Error('') },
	'submit fails with null': { submitError: null },
	'submit fails with a string': { submitError: 'plain' },
	'submit fails with a message object': { submitError: { message: 'obj' } },
}

const PICK_VALID = {
	location: 'L1',
	sku: 'S9',
	orderLineId: 'OL-7',
	quantity: 4,
	error: 'stale',
	warning: 'stale',
	successMessage: 'stale',
}

const PICK = {
	'valid without a db': { db: false },
	'valid without an order line': { fields: { orderLineId: '' } },
	'valid with more stock': { stock: () => 9 },
	'valid with exactly the stock': { stock: () => 4 },
	'valid with less stock': { stock: () => 3 },
	'valid with an undefined stock': { stock: () => undefined },
	'stock read throws': { stock: failing },
	'missing location': { fields: { location: '' } },
	'missing sku': { fields: { sku: '' } },
	'null quantity': { fields: { quantity: null } },
	'zero quantity': { fields: { quantity: 0 } },
	'negative quantity': { fields: { quantity: -2 } },
	'text quantity': { fields: { quantity: 'abc' } },
	'submitted 4999 ms ago': { fields: { lastSubmittedAt: NOW - 4999 } },
	'submitted 5000 ms ago': { fields: { lastSubmittedAt: NOW - 5000 } },
	'submitted in the future': { fields: { lastSubmittedAt: NOW + 1 } },
	'less stock and recently submitted': {
		fields: { lastSubmittedAt: NOW - 1 },
		stock: () => 1,
	},
	'submit fails with a message': { submitError: new Error('server said no') },
	'submit fails without a message': { submitError: new Error('') },
	'submit fails with null': { submitError: null },
	'submit fails with a string': { submitError: 'plain' },
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe.each([
	['TransferOp', TransferOp, TRANSFER_VALID, TRANSFER],
	['PickOp', PickOp, PICK_VALID, PICK],
])('%s handleConfirm', (name, component, valid, scenarios) => {
	it.each(Object.keys(scenarios))('%s', async (title) => {
		const scenario = scenarios[title]
		const result = await run(component, {
			...scenario,
			fields: { ...valid, ...scenario.fields },
		})
		const key = `${name} / ${title}`
		if (RECORD) {
			recorded[key] = result
			return
		}
		expect(result).toEqual(golden[key])
	})
})

describe('the golden record', () => {
	it('has no scenario the spec no longer runs', () => {
		if (RECORD) {
			return
		}
		const keys = [
			...Object.keys(TRANSFER).map((t) => `TransferOp / ${t}`),
			...Object.keys(PICK).map((t) => `PickOp / ${t}`),
		]
		expect(Object.keys(golden).sort()).toEqual(keys.sort())
	})
})

afterAll(() => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
