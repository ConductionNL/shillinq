/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Taxes area keeps one VAT return page (#1717). BtwAangiften lists
 * VatReturn records, whose rubrieken aggregation cannot compute, while
 * VATReturnService files BtwAangifte records, which VATReturns lists. No menu
 * entry, Taxes card or reporting card may open BtwAangiften any more; the page
 * stays routable for a deep link to an existing VatReturn record.
 *
 * @spec openspec/changes/tax-vat-return-from-books/tasks.md
 */

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { reportViews } from '../../src/components/reporting/reportViews.js'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const read = (path) => readFileSync(resolve(root, path), 'utf8')

describe('one VAT return page (#1717)', () => {
	it('retires BtwAangiften from the menu in favour of VATReturns', () => {
		const layout = JSON.parse(read('src/menu-layout.json'))
		expect(layout.removals).toContain('BtwAangiften')
		expect(layout.removalsReplacedBy.BtwAangiften).toBe('VATReturns')
	})

	it('has no reporting card that opens BtwAangiften', () => {
		expect(reportViews.map((view) => view.id)).not.toContain('BtwAangiften')
	})

	it('has no Taxes card that opens BtwAangiften, and keeps the VATReturns card', () => {
		const source = read('src/components/taxes/TaxesOverview.vue')
		expect(source).not.toMatch(/route:\s*'BtwAangiften'/)
		expect(source).toMatch(/route:\s*'VATReturns'/)
	})
})
