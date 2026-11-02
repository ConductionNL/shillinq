/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The shared parts of the warehouse operations: their starting state, the
 * scan handler, the form state, the queued submit, and the operation form
 * that wires submit, scan and cancel to its caller.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */

import { parse } from '@vue/compiler-sfc'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { compile } from 'vue/dist/vue.cjs.js'

vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({
	useInventoryMobileScannerStore: () => 'the store',
}))

const { inventoryOperation } =
	await import('../../src/components/inventory/inventoryOperation.js')

/**
 * A fake component instance with the shared state and methods bound to it.
 *
 * @param {object} store The scanner store.
 * @return {object} The instance.
 */
function operation(store) {
	const vm = { ...inventoryOperation.state(), store }
	for (const [name, fn] of Object.entries(inventoryOperation.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('inventoryOperation', () => {
	it('starts empty, idle and without feedback', () => {
		expect(inventoryOperation.state()).toEqual({
			sku: '',
			scanning: false,
			submitting: false,
			error: null,
			successMessage: null,
			lastSubmittedAt: 0,
		})
	})

	it('reads the scanner store', () => {
		expect(inventoryOperation.computed.store.call({})).toBe('the store')
	})

	it('shows the feedback and progress state on the form', () => {
		const vm = {
			error: 'e',
			successMessage: 's',
			submitting: true,
			scanning: false,
			warning: 'w',
		}
		expect(inventoryOperation.computed.formState.call(vm)).toEqual({
			error: 'e',
			successMessage: 's',
			submitting: true,
			scanning: false,
		})
	})

	it('takes a scanned SKU and closes the scanner', () => {
		const vm = operation(null)
		vm.scanning = true
		vm.handleScan('SKU-7')
		expect([vm.sku, vm.scanning]).toEqual(['SKU-7', false])
	})

	it('submits while showing it is submitting, then reports the answer', async () => {
		const seen = []
		const vm = operation({
			submitOperation: async (op) => {
				seen.push([op, vm.submitting])
				return { transactionId: 'tx-1' }
			},
		})
		await vm.queueOperation(
			{ type: 'pick' },
			{
				queued: (result) => seen.push(['queued', result, vm.submitting]),
				failed: () => 'never',
			},
		)
		expect(seen).toEqual([
			[{ type: 'pick' }, true],
			['queued', { transactionId: 'tx-1' }, true],
		])
		expect([vm.submitting, vm.error]).toEqual([false, null])
	})

	it('shows the failure text when the submit throws', async () => {
		const vm = operation({
			submitOperation: async () => {
				throw new Error('offline')
			},
		})
		await vm.queueOperation(
			{},
			{ queued: () => {}, failed: (e) => `failed: ${e.message}` },
		)
		expect([vm.submitting, vm.error]).toEqual([false, 'failed: offline'])
	})
})

describe('InventoryOperationForm', () => {
	const source = fs.readFileSync(
		path.join(
			__dirname,
			'../../src/components/inventory/InventoryOperationForm.vue',
		),
		'utf8',
	)
	// The slot needs a rendering instance; the wiring under test does not use it.
	const render = compile(
		parse(source).descriptor.template.content.replace('<slot />', ''),
	)

	/**
	 * Render the form and return the vnodes and the events it emitted.
	 *
	 * @param {object} props The props.
	 * @return {object} The section vnode and the emitted events.
	 */
	function rendered(props) {
		const emitted = []
		const ctx = {
			title: 'T',
			submitLabel: 'S',
			error: null,
			warning: null,
			successMessage: null,
			submitting: false,
			scanning: true,
			fallbackToManual: false,
			...props,
			$emit: (...args) => emitted.push(args),
		}
		return { section: render(ctx, []), emitted }
	}

	it('emits submit when the form is submitted, and prevents the default', () => {
		const { section, emitted } = rendered({})
		const form = section.children.find((node) => node.type === 'form')
		const event = { preventDefault: vi.fn() }
		form.props.onSubmit(event)
		expect(event.preventDefault).toHaveBeenCalled()
		expect(emitted).toEqual([['submit']])
	})

	it('passes a scanned value on and reports a cancelled scan', () => {
		const { section, emitted } = rendered({ fallbackToManual: true })
		const scanner = section.children[section.children.length - 1]
		scanner.props.onScan('SKU-1')
		scanner.props.onCancel()
		expect(scanner.props.fallbackToManual).toBe(true)
		expect(emitted).toEqual([['scan', 'SKU-1'], ['cancel']])
	})
})
