/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * purchasing-supplier-invoice-intake: the warnings and the booking reach the
 * supplier invoice pages. The manifest shows the fields the register declares,
 * and Book without order is a declared transition the lifecycle actions show.
 *
 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/specs/bookkeeping-purchase-order-3way/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(
			ROOT,
			'lib/Settings/register.d/bookkeeping-purchase-order-3way-01-schemas-and-registers.json',
		),
		'utf8',
	),
)
const nl = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8'),
).translations
const schema = fragment.components.schemas.SupplierInvoice
const page = (id) => manifest.pages.find((p) => p.id === id)

describe('Supplier invoice intake on the pages', () => {
	it('shows every warning field the register declares, with a Dutch label', () => {
		const detail = page('SupplierInvoiceDetail').config
		const keys = detail.fields.map((f) => f.key)
		for (const key of [
			'supplierIdentifier',
			'payeeIban',
			'duplicateOfId',
			'duplicateAcknowledgedReason',
			'ibanMismatch',
			'ibanAcknowledgedReason',
			'apTransactionId',
		]) {
			expect(keys).toContain(key)
			expect(schema.properties[key]).toBeDefined()
			const label = detail.fields.find((f) => f.key === key).label
			expect(nl[label]).toBeTruthy()
		}
		expect(detail.lifecycleActions).toBe(true)
	})

	it('lists the two warnings as columns on Supplier invoices', () => {
		const columns = page('SupplierInvoices').config.columns.map((c) => c.key)
		expect(columns).toEqual(
			expect.arrayContaining(['duplicateOfId', 'ibanMismatch']),
		)
	})

	it('declares Book without order from received, guarded and handing over to accounts payable', () => {
		const transition =
			schema['x-openregister-lifecycle'].transitions.bookWithoutOrder
		expect(transition.from).toBe('received')
		expect(transition.requires).toBe(
			'OCA\\Shillinq\\Lifecycle\\SupplierInvoiceBookingGuard',
		)
		expect(transition.actions[0].action).toBe(
			'OCA\\Shillinq\\Lifecycle\\Action\\HandToAccountsPayableAction',
		)
		expect(nl[transition.label]).toBe('Boeken zonder order')
	})
})
