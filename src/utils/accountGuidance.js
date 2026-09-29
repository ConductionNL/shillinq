// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ledger-booking-rules (REQ-LBR-003): the booking lines of a journal entry
// or a ledger transaction, each with its account's name and guidance, the
// account's description telling the bookkeeper when to use it.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER = 'shillinq'

/**
 * The results array of an OpenRegister list response.
 *
 * @param {object} data The response body.
 * @return {Array<object>} The rows.
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
export function resultsOf(data) {
	if (Array.isArray(data)) {
		return data
	}
	return Array.isArray(data?.results) ? data.results : []
}

/**
 * Pair each line with its account's name and guidance.
 *
 * @param {Array<object>} lines The lines: accountNumber, side, amount,
 *   costCenterCode, projectCode.
 * @param {Array<object>} accounts The administration's accounts on those lines.
 * @return {Array<object>} One row per line: key, accountNumber, accountName,
 *   guidance, controlAccountFor, side, amount, costCenterCode, projectCode.
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
export function guidanceRows(lines, accounts) {
	const byNumber = new Map()
	for (const account of accounts ?? []) {
		byNumber.set(String(account?.accountNumber ?? ''), account)
	}
	return (lines ?? []).map((line, index) => {
		const number = String(line?.accountNumber ?? '')
		const account = byNumber.get(number) ?? {}
		return {
			key: String(line?.id ?? index),
			accountNumber: number,
			accountName: String(account.name ?? ''),
			guidance: String(account.description ?? '').trim(),
			controlAccountFor: String(account.controlAccountFor ?? ''),
			side: String(line?.side ?? ''),
			amount: Number(line?.amount ?? 0),
			costCenterCode: String(line?.costCenterCode ?? ''),
			projectCode: String(line?.projectCode ?? ''),
		}
	})
}

/**
 * The URL of an OpenRegister object list of one schema.
 *
 * @param {string} schema The schema slug.
 * @param {object} filters Field filters.
 * @return {string} The URL.
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
export function objectsUrl(schema, filters = {}) {
	const query = new URLSearchParams()
	for (const [key, value] of Object.entries(filters)) {
		if (value !== undefined && value !== null && value !== '') {
			query.set(key, String(value))
		}
	}
	query.set('_limit', '200')
	return (
		generateUrl(`/apps/openregister/api/objects/${REGISTER}/${schema}`)
		+ '?'
		+ query.toString()
	)
}

/**
 * Load the lines of a journal entry or a ledger transaction with their
 * accounts' guidance.
 *
 * @param {string} schema JournalEntry or GLTransaction.
 * @param {string} objectId The object's id.
 * @return {Promise<Array<object>>} The rows of guidanceRows().
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */
export async function loadGuidanceRows(schema, objectId) {
	const id = encodeURIComponent(String(objectId))
	const { data: object } = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${REGISTER}/${schema}/${id}`),
	)
	let lines = Array.isArray(object?.lines) ? object.lines : []
	if (schema === 'GLTransaction') {
		const { data } = await axios.get(
			objectsUrl('GLLine', { transactionId: String(objectId) }),
		)
		lines = resultsOf(data).sort(
			(a, b) => Number(a?.lineNumber ?? 0) - Number(b?.lineNumber ?? 0),
		)
	}
	const numbers = [
		...new Set(lines.map((line) => String(line?.accountNumber ?? ''))),
	].filter((number) => number !== '')
	const responses = await Promise.all(
		numbers.map((accountNumber) =>
			axios.get(
				objectsUrl('Account', {
					administrationId: object?.administrationId,
					accountNumber,
				}),
			),
		),
	)
	const accounts = responses.flatMap(({ data }) => resultsOf(data).slice(0, 1))
	return guidanceRows(lines, accounts)
}
