/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Rejected e-invoices quick filter on Accounts Receivable
 * (sales-einvoice-exchange REQ-SEIX-004). A rejection a customer's access
 * point reports lands on `ARInvoice.deliveryStatus`; this filter is where a
 * bookkeeper finds those invoices. A filter on a field the schema does not
 * declare, or on a value outside its enum, lists nothing without an error.
 *
 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-2.3
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const manifest = readJson('src', 'manifest.json')
const page = manifest.pages.find((p) => p.id === 'AccountsReceivable')
const filter = (page.config.quickFilters || []).find((f) => f.label === 'Rejected e-invoices')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

/**
 * The ARInvoice schema's declared deliveryStatus property, wherever a
 * register file declares it.
 *
 * @return {object|undefined} The property definition.
 */
function deliveryStatusProperty() {
	const dir = path.join(ROOT, 'lib', 'Settings')
	const files = [
		...fs.readdirSync(dir).filter((f) => f.endsWith('.json')).map((f) => path.join(dir, f)),
		...fs.readdirSync(path.join(dir, 'register.d')).filter((f) => f.endsWith('.json')).map((f) => path.join(dir, 'register.d', f)),
	]
	for (const file of files) {
		const text = fs.readFileSync(file, 'utf8')
		if (!text.includes('"deliveryStatus"')) {
			continue
		}
		const schemas = JSON.parse(text)?.components?.schemas ?? {}
		const prop = schemas.ARInvoice?.properties?.deliveryStatus
		if (prop) {
			return prop
		}
	}
	return undefined
}

describe('the Rejected e-invoices quick filter (REQ-SEIX-004)', () => {
	it('lists the invoices whose delivery was rejected, with a count', () => {
		expect(page.schema ?? page.config.schema).toBe('ARInvoice')
		expect(filter.filter).toEqual({ deliveryStatus: 'rejected' })
		expect(filter.showCount).toBe(true)
		expect(filter.default).toBeUndefined()
	})

	it('filters on a value the schema declares', () => {
		const prop = deliveryStatusProperty()
		expect(prop, 'ARInvoice.deliveryStatus is declared').toBeTruthy()
		expect(prop.enum).toContain('rejected')
	})

	it('is translated into Dutch', () => {
		expect(en[filter.label]).toBe(filter.label)
		expect(nl[filter.label]).toBe('Afgewezen e-facturen')
	})
})
