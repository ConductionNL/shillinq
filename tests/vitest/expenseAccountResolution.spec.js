/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The account a receipt posts to, as the receipt and claim pages show it next
 * to the docudesk suggestion (expenses-category-mapping task 3.3).
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.3
 */

import { describe, expect, it } from 'vitest'
import {
	resolvedReceiptAccount,
	suggestionRelation,
} from '../../src/utils/expenseCategories.js'

const CATEGORIES = [
	{ code: 'OV', expenseAccount: '4510', administrationId: 'adm-1' },
	{ code: 'OV', expenseAccount: '9999', administrationId: 'adm-2' },
	{ code: 'LEEG', expenseAccount: '', administrationId: 'adm-1' },
]

describe('resolvedReceiptAccount', () => {
	it('takes the confirmed account first', () => {
		expect(
			resolvedReceiptAccount(
				{ glAccount: ' 4400 ', category: 'OV', administrationId: 'adm-1' },
				CATEGORIES,
			),
		).toEqual({ code: '4400', source: 'confirmed', category: 'OV' })
	})

	it('else the account of its category in its own administration', () => {
		expect(
			resolvedReceiptAccount(
				{ category: 'OV', administrationId: 'adm-1' },
				CATEGORIES,
			),
		).toEqual({ code: '4510', source: 'category', category: 'OV' })
		expect(
			resolvedReceiptAccount(
				{ category: 'OV', administrationId: 'adm-2' },
				CATEGORIES,
			).code,
		).toBe('9999')
	})

	it('else nothing, so the post will stop', () => {
		expect(
			resolvedReceiptAccount(
				{ category: 'LEEG', administrationId: 'adm-1' },
				CATEGORIES,
			),
		).toEqual({ code: null, source: null, category: 'LEEG' })
		expect(
			resolvedReceiptAccount(
				{ category: '', administrationId: 'adm-1' },
				CATEGORIES,
			).code,
		).toBeNull()
		expect(resolvedReceiptAccount(null, null).code).toBeNull()
	})
})

describe('suggestionRelation', () => {
	it('says whether the suggestion names another account', () => {
		expect(suggestionRelation({ code: '4510' }, { code: '4400' })).toBe(
			'differs',
		)
		expect(suggestionRelation({ code: '4510' }, { code: '4510' })).toBe('same')
		expect(suggestionRelation({ code: null }, { code: '4510' })).toBe('differs')
		expect(suggestionRelation({ code: '4510' }, null)).toBeNull()
	})
})
