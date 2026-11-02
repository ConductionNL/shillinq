/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the `handleConfirm` method of the CountOp and ReceiveOp
 * inventory operations. Each scenario runs the method on a fake instance
 * (the component's data, computed values and methods) with a fake scanner
 * store, and compares the instance's data afterwards and every submitted
 * operation with tests/vitest/fixtures/inventoryCountReceiveConfirm.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	readStockQuantity: async () => 0,
}))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => null,
}))
vi.mock('../../src/components/inventory/BarcodeScanner.vue', () => ({
	default: { name: 'BarcodeScanner' },
}))

const { default: CountOp } =
	await import('../../src/components/inventory/CountOp.vue')
const { default: ReceiveOp } =
	await import('../../src/components/inventory/ReceiveOp.vue')

const GOLDEN = path.join(
	__dirname,
	'fixtures/inventoryCountReceiveConfirm.golden.json',
)
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
 * Build a fake instance: data, scenario fields, computed getters and bound methods.
 *
 * @param {object} component The component options.
 * @param {object} scenario Fields and store behaviour.
 * @param {Array} submits Where submitted operations are recorded.
 * @return {object} The instance.
 */
function instance(component, scenario, submits) {
	const vm = { ...component.data(), ...scenario.fields, t: fakeT }
	for (const [name, fn] of Object.entries(component.computed)) {
		if (name !== 'store') {
			Object.defineProperty(vm, name, { get: fn.bind(vm) })
		}
	}
	vm.store = {
		db: { name: 'db' },
		submitOperation: async (op) => {
			submits.push({ op: { ...op }, submitting: vm.submitting })
			await new Promise((resolve) => setTimeout(resolve, 0))
			if ('submitError' in scenario) {
				throw scenario.submitError
			}
			return scenario.submitResult
		},
	}
	for (const [name, fn] of Object.entries(component.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * Run handleConfirm for one scenario and return what it did.
 *
 * @param {object} component The component options.
 * @param {object} scenario Fields and store behaviour.
 * @return {Promise<object>} The resulting data, extra fields and submits.
 */
async function run(component, scenario) {
	const submits = []
	const vm = instance(component, scenario, submits)
	const nowSpy = vi.spyOn(Date, 'now').mockReturnValue(NOW)
	let returned
	let thrown = null
	try {
		returned = await vm.handleConfirm()
	} catch (e) {
		thrown = e instanceof Error ? `Error:${e.message}` : e
	} finally {
		nowSpy.mockRestore()
	}
	const data = {}
	for (const key of [...Object.keys(component.data()), 'lastTransactionId']) {
		data[key] = vm[key] === undefined ? '(undefined)' : vm[key]
	}
	return {
		data,
		submits,
		returned: returned === undefined ? '(undefined)' : returned,
		thrown,
	}
}

const FAILURES = {
	'submit fails with a message': { submitError: new Error('server said no') },
	'submit fails without a message': { submitError: new Error('') },
	'submit fails with null': { submitError: null },
	'submit fails with a string': { submitError: 'plain' },
	'submit fails with a message object': { submitError: { message: 'obj' } },
}

const GUARD = {
	'submitted 4999 ms ago': { fields: { lastSubmittedAt: NOW - 4999 } },
	'submitted 5000 ms ago': { fields: { lastSubmittedAt: NOW - 5000 } },
	'submitted in the future': { fields: { lastSubmittedAt: NOW + 1 } },
}

const COUNT_VALID = {
	location: 'L1',
	sku: 'S1',
	physicalQuantity: 7,
	systemQuantity: 4,
	reconcile: true,
	error: 'stale',
	successMessage: 'stale',
}

const COUNT = {
	valid: {},
	'valid without a system quantity': { fields: { systemQuantity: null } },
	'valid with a zero count': { fields: { physicalQuantity: 0 } },
	'valid with a text count': { fields: { physicalQuantity: '3' } },
	'valid without reconcile': { fields: { reconcile: false } },
	'missing location': { fields: { location: '' } },
	'missing sku': { fields: { sku: '' } },
	'null count': { fields: { physicalQuantity: null } },
	'undefined count': { fields: { physicalQuantity: undefined } },
	'negative count': { fields: { physicalQuantity: -1 } },
	'missing sku and recently submitted': {
		fields: { sku: '', lastSubmittedAt: NOW - 1 },
	},
	...GUARD,
	...FAILURES,
}

const RECEIVE_VALID = {
	location: 'L2',
	sku: 'S2',
	quantity: 5,
	error: 'stale',
	successMessage: 'stale',
}

const RECEIVE = {
	valid: { submitResult: { transactionId: 'tx-9' } },
	'valid without a result': {},
	'valid with a result without id': { submitResult: {} },
	'valid with a fractional quantity': {
		fields: { quantity: 0.5 },
		submitResult: { transactionId: 'tx-1' },
	},
	'missing location': { fields: { location: '' } },
	'missing sku': { fields: { sku: '' } },
	'null quantity': { fields: { quantity: null } },
	'undefined quantity': { fields: { quantity: undefined } },
	'zero quantity': { fields: { quantity: 0 } },
	'negative quantity': { fields: { quantity: -1 } },
	'text quantity': { fields: { quantity: 'abc' }, submitResult: {} },
	'missing sku and recently submitted': {
		fields: { sku: '', lastSubmittedAt: NOW - 1 },
	},
	...GUARD,
	...FAILURES,
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe.each([
	['CountOp', CountOp, COUNT_VALID, COUNT],
	['ReceiveOp', ReceiveOp, RECEIVE_VALID, RECEIVE],
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
			...Object.keys(COUNT).map((t) => `CountOp / ${t}`),
			...Object.keys(RECEIVE).map((t) => `ReceiveOp / ${t}`),
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
