/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Boards ShVerkoopfactuur, ShVerkoopfacturen and ShInkoopfacturen: the invoice
 * page is named by its number, its money facts read as amounts, the count
 * lines take a singular and a plural, and the primary header buttons open a
 * form or a registered modal.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const einvoicing = read('src/manifest.d/add-shillinq-einvoicing-ubl-peppol.json')
const nl = read('l10n/nl.json').translations
const en = read('l10n/en.json').translations
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

const pageOf = (doc, id) => doc.pages.find((p) => p.id === id)
const pluralKey = ({ singular, plural }) => `_${singular}_::_${plural}_`

describe('invoice pages on the 2.85 library', () => {
	const detail = pageOf(einvoicing, 'ARInvoiceDetail')

	it('names the invoice by its number', () => {
		expect(detail.config.titleField).toBe('invoiceNumber')
	})

	it('shows the amounts in the payment facts as money in the invoice currency', () => {
		const facts = detail.config.widgets.find((w) => w.id === 'invoice-payment')
		const money = facts.content.fields.filter((f) => f.format === 'currency')
		expect(money.map((f) => f.path)).toEqual(['amountDue', 'grossAmount'])
		money.forEach((f) => expect(f.currencyField).toBe('currency'))
	})

	it.each([
		['AccountsReceivable', 'factuur', 'facturen'],
		['SupplierInvoices', 'inkoopfactuur', 'inkoopfacturen'],
	])(
		'%s has a count line in two forms with a Dutch plural entry',
		(id, one, many) => {
			const subtitle = pageOf(manifest, id).config.countSubtitle
			const key = pluralKey(subtitle)
			expect(en[key]).toEqual([subtitle.singular, subtitle.plural])
			expect(nl[key][0]).toContain(`{total} ${one}`)
			expect(nl[key][1]).toContain(`{total} ${many}`)
		},
	)

	it('opens the create form from New invoice and keeps the other actions in the menu', () => {
		const config = pageOf(manifest, 'AccountsReceivable').config
		const primary = config.headerButtons.find((b) => b.variant === 'primary')
		const action = config.headerActions.find((a) => a.id === primary.action)
		expect(action).toMatchObject({ type: 'open-form', schema: 'arInvoice' })
		const menu = config.headerButtons.find((b) => b.action === 'actions-menu')
		expect(menu.items).toEqual(['new-down-payment-invoice', 'generate-invoices'])
		expect(config.showAdd).toBe(false)
	})

	it('imports a purchase invoice from the primary button and keeps New purchase invoice', () => {
		const config = pageOf(manifest, 'SupplierInvoices').config
		const primary = config.headerButtons.find((b) => b.variant === 'primary')
		const action = config.headerActions.find((a) => a.id === primary.action)
		expect(action).toMatchObject({
			type: 'open-modal',
			target: 'BillImportModal',
		})
		expect(registrySource).toMatch(/BillImportModal: \{ kind: 'modal'/)
		const menu = config.headerButtons.find((b) => b.action === 'actions-menu')
		const create = config.headerActions.find(
			(a) => a.id === 'new-purchase-invoice',
		)
		expect(menu.items).toContain(create.id)
		expect(config.showAdd).toBe(false)
		expect(create).toMatchObject({
			type: 'open-form',
			schema: 'supplierInvoice',
		})
	})
})
