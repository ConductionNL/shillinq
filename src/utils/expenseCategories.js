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
		.filter((category) => category && category.active === true && String(category.code || '') !== '')
		.filter((category) => !administrationId || String(category.administrationId || '') === String(administrationId))
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
	const names = (category && typeof category.name === 'object' && category.name) || {}
	const preferred = String(language || '').toLowerCase().startsWith('nl') ? ['nl', 'en'] : ['en', 'nl']
	for (const key of preferred) {
		if (typeof names[key] === 'string' && names[key].trim() !== '') {
			return names[key]
		}
	}
	return String(category?.code || '')
}
