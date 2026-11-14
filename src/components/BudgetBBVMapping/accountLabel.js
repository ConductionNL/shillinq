/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * How the GL account picker reads an account: its number, name and type from
 * whichever field the record carries, and whether a balance is an amount.
 *
 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
 */

/**
 * The account's number, name and type, each the first filled field of its
 * candidates, '' when none is.
 *
 * @param {object} account The Account record.
 * @return {Array<string>}
 */
export function accountLabelParts(account) {
	return [
		firstFilled([account.accountNumber, account.id]),
		firstFilled([account.accountName, account.name, account.title]),
		firstFilled([account.accountType, account.type]),
	]
}

/**
 * The first truthy value, '' when there is none.
 *
 * @param {Array<unknown>} values The candidates, in order.
 * @return {unknown}
 */
function firstFilled(values) {
	return values.find(Boolean) || ''
}

/**
 * The balance is present and a finite number.
 *
 * @param {unknown} cents The balance in cents.
 * @return {boolean}
 */
export function isAmount(cents) {
	return cents !== null && cents !== undefined && Number.isFinite(Number(cents))
}
