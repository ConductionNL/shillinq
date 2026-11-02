/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the rendered markup of the four inventory operations
 * (Receive, Transfer, Pick, Count). Each scenario server-renders one
 * operation with a fake scanner store and a given state, and compares the
 * markup with tests/vitest/fixtures/inventoryOpRender.golden.json. Scoped-style
 * attributes and fragment markers are dropped, and the SKU row class is
 * recorded as SKU_ROW, so the record holds what a user sees and uses.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

const scanner = vi.hoisted(() => ({ store: null }))

vi.mock('../../src/composables/useInventoryDb.js', () => ({
	readStockQuantity: async () => 7,
}))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => scanner.store,
}))
vi.mock('../../src/components/inventory/BarcodeScanner.vue', () => ({
	default: {
		name: 'BarcodeScanner',
		props: { fallbackToManual: { type: Boolean, default: false } },
		emits: ['scan', 'cancel'],
		template:
			'<div class="scanner-stub" :data-fallback="String(fallbackToManual)"></div>',
	},
}))

const { default: ReceiveOp } =
	await import('../../src/components/inventory/ReceiveOp.vue')
const { default: TransferOp } =
	await import('../../src/components/inventory/TransferOp.vue')
const { default: PickOp } = await import('../../src/components/inventory/PickOp.vue')
const { default: CountOp } =
	await import('../../src/components/inventory/CountOp.vue')

const GOLDEN = path.join(__dirname, 'fixtures/inventoryOpRender.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const LOCATIONS = [
	{ code: 'A', name: 'Aisle A' },
	{ code: 'B', name: 'Back room' },
	{ code: 'C', name: 'Cold store' },
]

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
 * Server-render one operation with the given data and return its markup.
 *
 * @param {object} component The operation component.
 * @param {object} state Data overrides and the store's locations.
 * @return {Promise<string>} The normalised markup.
 */
async function render(component, state) {
	scanner.store = { db: null, locations: state.locations ?? LOCATIONS }
	const wrapped = {
		...component,
		data() {
			return { ...component.data.call(this), ...(state.data ?? {}) }
		},
	}
	const app = createSSRApp(wrapped)
	app.mixin({ methods: { t: fakeT } })
	const html = await renderToString(app)
	return html
		.replace(/ data-v-[a-z0-9]+(="")?/g, '')
		.replace(/<!--\[-->|<!--\]-->|<!---->/g, '')
		.replace(/[a-z-]+__sku-row|inventory-sku-field__row/g, 'SKU_ROW')
		.split('><')
		.join('>\n<')
}

const SHARED = {
	empty: {},
	'no locations': { locations: [] },
	selected: { data: { sku: 'SKU-1', quantity: 2 } },
	feedback: { data: { error: 'Something failed', successMessage: 'Saved' } },
	scanning: { data: { scanning: true } },
	submitting: { data: { submitting: true } },
}

const OPS = [
	[
		'ReceiveOp',
		ReceiveOp,
		{ ...SHARED, 'location chosen': { data: { location: 'B' } } },
	],
	[
		'TransferOp',
		TransferOp,
		{
			...SHARED,
			'source chosen': { data: { fromLocation: 'A' } },
			'both chosen': { data: { fromLocation: 'A', toLocation: 'C' } },
		},
	],
	[
		'PickOp',
		PickOp,
		{
			...SHARED,
			'location chosen': { data: { location: 'C', orderLineId: 'OL-1' } },
			warning: { data: { warning: 'Low stock' } },
		},
	],
	[
		'CountOp',
		CountOp,
		{
			...SHARED,
			'location chosen': { data: { location: 'A', physicalQuantity: 4 } },
			'with system quantity': {
				data: { systemQuantity: 6, physicalQuantity: 4 },
			},
			reconcile: { data: { reconcile: true } },
		},
	],
]

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe.each(OPS)('%s markup', (name, component, scenarios) => {
	it.each(Object.keys(scenarios))('%s', async (title) => {
		const html = await render(component, scenarios[title])
		const key = `${name} / ${title}`
		if (RECORD) {
			recorded[key] = html.split('\n')
			return
		}
		expect(html.split('\n')).toEqual(golden[key])
	})
})

describe('the golden record', () => {
	it('has no scenario the spec no longer runs', () => {
		if (RECORD) {
			return
		}
		const keys = OPS.flatMap(([name, , scenarios]) =>
			Object.keys(scenarios).map((title) => `${name} / ${title}`),
		)
		expect(Object.keys(golden).sort()).toEqual(keys.sort())
	})
})

afterAll(() => {
	if (RECORD) {
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
