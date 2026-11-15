/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The shared inventory operation fields report what the user picks or types
 * through update:modelValue, and the SKU field asks for the scanner with scan.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { parse } from '@vue/compiler-sfc'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { compile } from 'vue/dist/vue.cjs.js'
import InventoryLocationField from '../../src/components/inventory/InventoryLocationField.vue'
import InventoryQuantityField from '../../src/components/inventory/InventoryQuantityField.vue'
import InventorySkuField from '../../src/components/inventory/InventorySkuField.vue'

/**
 * A fake instance of a field with the given model value.
 *
 * @param {*} modelValue The bound value.
 * @return {object} The instance and the events it emitted.
 */
function field(modelValue) {
	const emitted = []
	return { modelValue, emitted, $emit: (...args) => emitted.push(args) }
}

describe.each([
	['InventoryLocationField', InventoryLocationField, 'selected', 'B'],
	['InventorySkuField', InventorySkuField, 'value', 'SKU-9'],
	['InventoryQuantityField', InventoryQuantityField, 'value', 2.5],
])('%s', (name, component, proxy, next) => {
	it('shows the bound value', () => {
		const vm = field('current')
		expect(component.computed[proxy].get.call(vm)).toBe('current')
	})

	it('reports a new value and nothing else', () => {
		const vm = field('current')
		component.computed[proxy].set.call(vm, next)
		expect(vm.emitted).toEqual([['update:modelValue', next]])
	})

	it('declares the events it emits', () => {
		expect(component.emits).toContain('update:modelValue')
	})
})

describe('InventorySkuField', () => {
	it('declares the scan event', () => {
		expect(InventorySkuField.emits).toEqual(['update:modelValue', 'scan'])
	})

	it('wires the Scan button to requestScan', () => {
		const source = fs.readFileSync(
			path.join(
				__dirname,
				'../../src/components/inventory/InventorySkuField.vue',
			),
			'utf8',
		)
		const render = compile(parse(source).descriptor.template.content)
		const ctx = {
			t: (app, text) => text,
			value: '',
			requestScan: () => 'scan requested',
		}
		const tree = render(ctx, [])
		const row = tree.children.find((node) => node.type === 'div')
		const button = row.children.find((node) => node.type === 'button')
		expect(button.props.onClick()).toBe('scan requested')
	})

	it('asks for the scanner when Scan is pressed', () => {
		const vm = field('')
		InventorySkuField.methods.requestScan.call(vm)
		expect(vm.emitted).toEqual([['scan']])
	})
})
