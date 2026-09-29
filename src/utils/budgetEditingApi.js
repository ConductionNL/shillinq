// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// planning-budget-editing: the requests behind typing a budget into the
// grid, Spread over months, the multi-year page and Start next year, and the
// pure helpers they share. Every write goes to BudgetEditingController;
// amounts travel as EUR cents.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const BASE = '/apps/shillinq/api/v1/budget-editing'

/**
 * Parse an amount a person typed ("206000", "206.000", "206000,50") to cents.
 *
 * @param {string|number} value The typed amount in euros.
 * @return {number|null} Cents, or null when it is not an amount.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */
export function euroToCents(value) {
	let text = String(value ?? '').trim().replace(/\s|€|EUR/gi, '')
	if (text === '') {
		return null
	}
	if (text.includes(',')) {
		text = text.replace(/\./g, '').replace(',', '.')
	} else if (/^-?\d{1,3}(\.\d{3})+$/.test(text)) {
		text = text.replace(/\./g, '')
	}
	if (!/^-?\d+(\.\d{1,2})?$/.test(text)) {
		return null
	}
	return Math.round(Number(text) * 100)
}

/**
 * Cents as a plain euro amount for a cell ("206000" or "206000.50").
 *
 * @param {number} cents The amount.
 * @return {string} The amount in euros.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */
export function centsToInput(cents) {
	const value = Number(cents || 0) / 100
	return Number.isInteger(value) ? String(value) : value.toFixed(2)
}

/**
 * Twelve equal month amounts in cents, the last taking the remainder.
 *
 * @param {number} yearly The yearly amount in cents.
 * @return {Array<number>} Twelve amounts adding up to the yearly one.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
 */
export function spreadAmounts(yearly) {
	const month = Math.trunc(yearly / 12)
	const amounts = new Array(12).fill(month)
	amounts[11] = yearly - month * 11
	return amounts
}

/**
 * The cell to move to from a key press, or null to stay.
 *
 * @param {string} key The key (ArrowUp, ArrowDown, ArrowLeft, ArrowRight, Enter).
 * @param {{row:number,col:number}} at The current cell.
 * @param {number} rowCount The number of rows.
 * @param {boolean} atStart Whether the caret is at the start of the input.
 * @param {boolean} atEnd Whether the caret is at the end of the input.
 * @return {{row:number,col:number}|null} The next cell.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */
export function nextCell(key, at, rowCount, atStart = true, atEnd = true) {
	const moves = {
		ArrowUp: { row: at.row - 1, col: at.col },
		ArrowDown: { row: at.row + 1, col: at.col },
		Enter: { row: at.row + 1, col: at.col },
		ArrowLeft: atStart ? { row: at.row, col: at.col - 1 } : null,
		ArrowRight: atEnd ? { row: at.row, col: at.col + 1 } : null,
	}
	const next = moves[key] ?? null
	if (next === null || next.row < 0 || next.row >= rowCount || next.col < 0 || next.col > 11) {
		return null
	}
	return next
}

/**
 * The message of a refused request, or a fallback.
 *
 * @param {Error} error The axios error.
 * @param {string} fallback The fallback text.
 * @return {string} The message.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */
export function refusal(error, fallback) {
	return error?.response?.data?.message || fallback
}

/**
 * The ledger groups of a budget with their month amounts.
 *
 * @param {string} administrationId The administration.
 * @param {string} annualBudgetId The annual budget.
 * @return {Promise<{budget:object,rows:Array<object>}>} The rows.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */
export async function loadBudgetLines(administrationId, annualBudgetId) {
	const { data } = await axios.get(generateUrl(`${BASE}/lines`), {
		params: { administrationId, annualBudgetId },
	})
	return data
}

/**
 * Save one month of a row.
 *
 * @param {object} cell administrationId, annualBudgetId, ledgerGroupId, month (1-12), amount and expected (cents).
 * @return {Promise<object>} The row as stored.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
 */
export async function saveBudgetCell(cell) {
	const { data } = await axios.put(generateUrl(`${BASE}/cell`), cell)
	return data
}

/**
 * Spread a yearly amount over a row's months.
 *
 * @param {object} request administrationId, annualBudgetId, ledgerGroupId, yearly (cents) and expected (twelve cents).
 * @return {Promise<object>} The row as stored.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
 */
export async function spreadBudgetRow(request) {
	const { data } = await axios.post(generateUrl(`${BASE}/spread`), request)
	return data
}

/**
 * Ledger groups against the years that have a budget, and every budget.
 *
 * @param {string} administrationId The administration.
 * @param {number} fromYear The first year.
 * @return {Promise<{years:Array<object>,rows:Array<object>,budgets:Array<object>}>} The view.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
 */
export async function loadMultiYear(administrationId, fromYear) {
	const { data } = await axios.get(generateUrl(`${BASE}/multi-year`), {
		params: { administrationId, fromYear },
	})
	return data
}

/**
 * Start next year's budget from a chosen one with a percentage.
 *
 * @param {string} administrationId The administration.
 * @param {string} annualBudgetId The budget to start from.
 * @param {number} percentage The change in percent.
 * @return {Promise<object>} The new budget.
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
 */
export async function startNextYear(administrationId, annualBudgetId, percentage) {
	const { data } = await axios.post(generateUrl(`${BASE}/next-year`), {
		administrationId,
		annualBudgetId,
		percentage,
	})
	return data
}
