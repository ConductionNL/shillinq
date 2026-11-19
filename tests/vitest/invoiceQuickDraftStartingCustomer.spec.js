/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The customer page starts the quick draft for its customer (REQ-IQD-009):
 * the dialog opened from a customer's detail page selects that customer
 * once the list has loaded, through the same path as a choice by hand.
 *
 * @spec openspec/changes/sales-quick-draft-from-any-page/specs/shillinq-invoice-quick-draft/spec.md#requirement-the-quick-draft-opens-from-every-page-of-the-app-req-iqd-009
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import InvoiceQuickDraftModal from '../../src/modals/InvoiceQuickDraftModal.vue'
import { startingCustomerOption } from '../../src/modals/invoiceQuickDraft.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

const VOORBEELD = { id: 'uuid-voorbeeld', customerId: 'C-0042', legalName: 'Gemeente Voorbeeld' }
const KORENAAR = { '@self': { id: 'uuid-korenaar' }, customerId: 'C-0007', legalName: 'Bakkerij De Korenaar B.V.' }
const OPTIONS = [
	{ value: 'C-0007', display: 'Bakkerij De Korenaar B.V.', customer: KORENAAR },
	{ value: 'C-0042', display: 'Gemeente Voorbeeld', customer: VOORBEELD },
]

/**
 * A fake dialog instance with the given props, route and customer options.
 *
 * @param {object} props The dialog's props.
 * @param {object} [route] The current route.
 * @return {object}
 */
function vm(props, route = { params: {} }) {
	const ctx = { ...props, $route: route, customerOptions: OPTIONS, selected: [] }
	for (const [name, fn] of Object.entries(InvoiceQuickDraftModal.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.onCustomerSelected = (option) => ctx.selected.push(option)
	return ctx
}

describe('startingCustomerOption', () => {
	it('finds a customer by its object id, its @self id or its customer number', () => {
		expect(startingCustomerOption(OPTIONS, 'uuid-voorbeeld')).toBe(OPTIONS[1])
		expect(startingCustomerOption(OPTIONS, 'uuid-korenaar')).toBe(OPTIONS[0])
		expect(startingCustomerOption(OPTIONS, 'C-0042')).toBe(OPTIONS[1])
	})

	it('names no option for an empty or unknown reference, or without options', () => {
		expect(startingCustomerOption(OPTIONS, '')).toBeNull()
		expect(startingCustomerOption(OPTIONS, null)).toBeNull()
		expect(startingCustomerOption(OPTIONS, 'uuid-elders')).toBeNull()
		expect(startingCustomerOption(undefined, 'uuid-voorbeeld')).toBeNull()
	})
})

describe('InvoiceQuickDraftModal — the starting customer', () => {
	it('selects the customer of the customer page it was opened on', () => {
		const ctx = vm({ from: 'customer', objectId: '' }, { params: { id: 'uuid-voorbeeld' } })
		ctx.selectStartingCustomer()
		expect(ctx.selected).toEqual([OPTIONS[1]])
	})

	it('prefers an explicit object id over the route', () => {
		const ctx = vm({ from: 'customer', objectId: 'uuid-korenaar' }, { params: { id: 'uuid-voorbeeld' } })
		ctx.selectStartingCustomer()
		expect(ctx.selected).toEqual([OPTIONS[0]])
	})

	it('starts without a customer when not opened from a customer page', () => {
		const ctx = vm({ from: '', objectId: '' }, { params: { id: 'uuid-voorbeeld' } })
		ctx.selectStartingCustomer()
		expect(ctx.selected).toEqual([])
	})

	it('starts without a customer when the page names none in the list', () => {
		const ctx = vm({ from: 'customer', objectId: '' }, { params: { id: 'uuid-elders' } })
		ctx.selectStartingCustomer()
		expect(ctx.selected).toEqual([])
	})

	it('selects it only after the customer list has loaded', async () => {
		const order = []
		const ctx = { $route: { params: {} } }
		ctx.reset = () => order.push('reset')
		ctx.fetchCustomers = () => {
			order.push('fetch')
			return Promise.resolve()
		}
		ctx.selectStartingCustomer = () => order.push('select')
		InvoiceQuickDraftModal.watch.open.handler.call(ctx, true)
		expect(order).toEqual(['reset', 'fetch'])
		await Promise.resolve()
		expect(order).toEqual(['reset', 'fetch', 'select'])
	})
})

describe('the customer page offers Create invoice', () => {
	it('opens the quick draft from CustomerDetail with the customer as its starting point', () => {
		const manifest = JSON.parse(readFileSync(new URL('../../src/manifest.json', import.meta.url), 'utf8'))
		const page = manifest.pages.find((p) => p.id === 'CustomerDetail')
		const action = page.config.headerActions.find((a) => a.id === 'create-invoice')
		expect(action).toMatchObject({
			type: 'open-modal',
			target: 'InvoiceQuickDraftModal',
			props: { open: true, from: 'customer' },
		})
	})
})
