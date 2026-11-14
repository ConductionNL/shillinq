/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The expense categories a receipt can pick (expenses-category-mapping
 * REQ-ECM-002): only the active categories of the receipt's own
 * administration, named in the reader's language.
 *
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
 */

/**
 * The categories a receipt of this administration may pick: active ones,
 * of that administration when it is known, ordered by code.
 *
 * @param {Array<object>} categories ExpenseCategory records.
 * @param {string} administrationId The receipt's administration, or ''.
 * @return {Array<object>} The pickable categories.
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
 */
export function pickableCategories(categories, administrationId) {
	return (Array.isArray(categories) ? categories : [])
		.filter(
			(category) =>
				category
				&& category.active === true
				&& String(category.code || '') !== '',
		)
		.filter(
			(category) =>
				!administrationId
				|| String(category.administrationId || '')
					=== String(administrationId),
		)
		.sort((a, b) => String(a.code).localeCompare(String(b.code)))
}

/**
 * A category's name in the language asked for, else the other language,
 * else its code.
 *
 * @param {object} category An ExpenseCategory record.
 * @param {string} language A language code (`nl`, `en`, `nl_NL`).
 * @return {string} The name.
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-2.1
 */
export function categoryName(category, language) {
	const names =
		(category && typeof category.name === 'object' && category.name) || {}
	const preferred = String(language || '')
		.toLowerCase()
		.startsWith('nl')
		? ['nl', 'en']
		: ['en', 'nl']
	for (const key of preferred) {
		if (typeof names[key] === 'string' && names[key].trim() !== '') {
			return names[key]
		}
	}
	return String(category?.code || '')
}

/**
 * The account a receipt line posts to, in the resolver's order: the account
 * an operator confirmed on the receipt, else its category's expense account.
 * Mirrors ExpenseAccountResolver for display; the docudesk suggestion is
 * never read here.
 *
 * @param {object} receipt The receipt.
 * @param {Array<object>} categories The administration's expense categories.
 * @return {{code: string|null, source: 'confirmed'|'category'|null, category: string}} The account and where it came from.
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.3
 */
export function resolvedReceiptAccount(receipt, categories) {
	const category = String(receipt?.category || '').trim()
	const confirmed = String(receipt?.glAccount || '').trim()
	if (confirmed !== '') {
		return { code: confirmed, source: 'confirmed', category }
	}

	const match = (Array.isArray(categories) ? categories : []).find(
		(candidate) =>
			String(candidate?.code || '') === category
			&& (!receipt?.administrationId
				|| String(candidate?.administrationId || '')
					=== String(receipt.administrationId)),
	)
	const account = String(match?.expenseAccount || '').trim()
	if (category !== '' && account !== '') {
		return { code: account, source: 'category', category }
	}

	return { code: null, source: null, category }
}

/**
 * How a docudesk suggestion relates to the resolved account: `differs` when
 * it names another account, `same` when it names this one, null without a
 * suggestion.
 *
 * @param {{code: string|null}} resolved The resolved account.
 * @param {object|null} suggestion The receipt's suggestedGlAccount.
 * @return {'differs'|'same'|null} The relation.
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-3.3
 */
export function suggestionRelation(resolved, suggestion) {
	const code = String(suggestion?.code || '').trim()
	if (code === '') {
		return null
	}

	return code === String(resolved?.code || '') ? 'same' : 'differs'
}
