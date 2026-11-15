/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the edge branches of five small form helpers before they are split:
 * the receipt review form's coercions, the billable-reading filter, the bill
 * import format detection, the budget grid's key navigation and the GL
 * account picker's display text.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import GlAccountPicker from '../../src/components/BudgetBBVMapping/GlAccountPicker.vue'
import { detectFormat } from '../../src/modals/billImportModal.js'
import { nextCell } from '../../src/utils/budgetEditingApi.js'
import { billableReadings } from '../../src/utils/usageBilling.js'
import { reviewFormFromReceipt } from '../../src/views/receiptCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: async () => ({ data: {} }) } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({ NcSelect: {} }))

describe('reviewFormFromReceipt edges', () => {
	it('coerces every field of a full record', () => {
		expect(reviewFormFromReceipt({
			receiptNumber: 7,
			photoUri: null,
			amount: '12.50',
			currency: null,
			amountInBaseCurrency: 'x',
			exchangeRate: '1.1',
			receiptDate: '2026-01-02',
			category: 'meals',
			extractedText: undefined,
			vendorName: 'V',
			description: 0,
			costCentreCode: 'CC',
			claimId: 'C-1',
			glAccount: 4400,
		})).toStrictEqual({
			receiptNumber: '7',
			photoUri: '',
			amount: 12.5,
			currency: 'EUR',
			amountInBaseCurrency: 0,
			exchangeRate: 1.1,
			receiptDate: '2026-01-02',
			category: 'meals',
			extractedText: '',
			vendorName: 'V',
			description: '0',
			costCentreCode: 'CC',
			claimId: 'C-1',
			glAccount: '4400',
		})
	})

	it.each([
		[null, 0, null],
		[{ amount: 0, exchangeRate: 0 }, 0, 0],
		[{ amount: -3, exchangeRate: 'abc' }, -3, Number.NaN],
		[{ amount: '', exchangeRate: undefined }, 0, null],
	])('amount and rate of %j', (record, amount, rate) => {
		const form = reviewFormFromReceipt(record)
		expect(form.amount).toBe(amount)
		expect(form.exchangeRate).toBe(rate)
		expect(Object.keys(form)).toEqual(['receiptNumber', 'photoUri', 'amount', 'currency', 'amountInBaseCurrency', 'exchangeRate', 'receiptDate', 'category', 'extractedText', 'vendorName', 'description', 'costCentreCode', 'claimId', 'glAccount'])
	})
})

describe('billableReadings edges', () => {
	const readings = [
		{ id: 'late', status: 'rated', customerId: 'c-1', periodEnd: '2026-10-01' },
		{ id: 'b', status: 'rated', customerId: 'c-1', periodEnd: '2026-09-30' },
		{ id: 'billed', status: 'rated', customerId: 'c-1', periodEnd: '2026-09-15', invoiceId: 'i-1' },
		{ id: 'raw', status: 'raw', customerId: 'c-1', periodEnd: '2026-09-15' },
		{ id: 'other', status: 'rated', customerId: 'c-2', periodEnd: '2026-09-15' },
		null,
		{ id: 'a', status: 'rated', customerId: 'c-1', periodEnd: '2026-09-01' },
		{ id: 'noend', status: 'rated', customerId: 'c-1' },
		{ id: 'numeric', status: 'rated', customerId: 5, periodEnd: '2026-09-10' },
	]

	it.each([
		['c-1', '2026-09-01', '2026-09-30', ['a', 'b']],
		[5, '2026-09-01', '2026-09-30', ['numeric']],
		['c-1', '', '2026-09-30', ['noend', 'a', 'b']],
		['c-1', '2026-09-01', '', []],
		[undefined, '', 'z', []],
	])('customer %j from %j to %j', (customer, from, to, expected) => {
		expect(billableReadings(readings, customer, from, to).map((r) => r.id)).toEqual(expected)
	})

	it('accepts no readings', () => {
		expect(billableReadings(null, 'c-1', '2026-01-01', '2026-12-31')).toEqual([])
	})
})

describe('detectFormat edges', () => {
	it.each([
		['INVOICE.XML', '', 'ubl'],
		['report.Csv', '', 'csv'],
		['scan.PDF', '', 'pdf'],
		['archive.tar.gz', '  \n <Invoice/>', 'ubl'],
		['', '\t%PDF-1.4', 'pdf'],
		[null, undefined, ''],
		['constructor', 'a,b', 'csv'],
		['x.toString', '', ''],
		['noext', '   ', ''],
	])('%j / %j', (name, contents, expected) => {
		expect(detectFormat(name, contents)).toBe(expected)
	})
})

describe('nextCell edges', () => {
	it.each([
		['ArrowDown', { row: 2, col: 0 }, 3, true, true, null],
		['ArrowDown', { row: 1, col: 0 }, 3, true, true, { row: 2, col: 0 }],
		['ArrowLeft', { row: 0, col: 0 }, 3, true, true, null],
		['ArrowLeft', { row: 0, col: 4 }, 3, true, true, { row: 0, col: 3 }],
		['ArrowRight', { row: 0, col: 10 }, 3, true, false, null],
		['ArrowRight', { row: 0, col: 10 }, 3, true, true, { row: 0, col: 11 }],
		['ArrowUp', { row: 2, col: 5 }, 3, false, false, { row: 1, col: 5 }],
		['Enter', { row: 0, col: 12 }, 3, true, true, null],
		['Tab', { row: 0, col: 1 }, 3, true, true, null],
	])('%s from %j in %i rows', (key, at, rows, atStart, atEnd, expected) => {
		expect(nextCell(key, at, rows, atStart, atEnd)).toEqual(expected)
	})
})

describe('GlAccountPicker formatDisplay edges', () => {
	const vm = {}
	for (const [name, fn] of Object.entries(GlAccountPicker.methods)) {
		vm[name] = fn.bind(vm)
	}
	vm.formatEuro = (cents) => `EUR ${cents}`
	const formatDisplay = (account) => vm.formatDisplay(account)

	it.each([
		[{ accountNumber: '4000', accountName: 'Rent', accountType: 'expense', balance: 1250 }, '4000 · Rent · expense · EUR 1250'],
		[{ id: 'a-1', name: 'Rent', type: 'expense', balanceCents: '99' }, 'a-1 · Rent · expense · EUR 99'],
		[{ title: 'Only title', balance: 'n/a' }, 'Only title'],
		[{ accountNumber: '', id: 0, accountName: '', title: 'T', balance: 0 }, 'T · EUR 0'],
		[{ balance: null, balanceCents: 5 }, 'EUR 5'],
		[{}, ''],
		[undefined, ''],
	])('%j', (account, expected) => {
		expect(formatDisplay(account)).toBe(expected)
	})
})
