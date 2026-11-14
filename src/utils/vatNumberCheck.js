// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-vat-number-check: the Check VAT number header action on a customer's
// page and on a supplier's page. Resolved against `customComponents` in
// src/main.js.

import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * A date-time as a local date, or an empty string.
 *
 * @param {string|null} value The ISO date-time.
 * @return {string} The date.
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */
function day(value) {
	if (!value) {
		return ''
	}
	const date = new Date(value)
	if (Number.isNaN(date.getTime())) {
		return ''
	}
	return date.toLocaleDateString()
}

/**
 * The message for a check's outcome, and how to show it.
 *
 * @param {{status: string, vatId: string, lastValidAt: string|null}} result The outcome.
 * @return {{kind: string, text: string}} The toast kind (success, warning, error) and its text.
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */
export function vatCheckMessage(result) {
	if (result.status === 'valid') {
		return validMessage(result)
	}
	if (result.status === 'invalid') {
		return invalidMessage(result.vatId)
	}
	return unreachableMessage(result)
}

/**
 * The message for a number VIES confirmed.
 *
 * @param {{vatId: string, lastValidAt: string|null}} result The outcome.
 * @return {{kind: string, text: string}}
 */
function validMessage(result) {
	return {
		kind: 'success',
		text: t('shillinq', 'VAT number {vatId} is valid (checked on {date}).', {
			vatId: result.vatId,
			date: day(result.lastValidAt),
		}),
	}
}

/**
 * The message for a number VIES rejected.
 *
 * @param {string} vatId The number.
 * @return {{kind: string, text: string}}
 */
function invalidMessage(vatId) {
	return {
		kind: 'error',
		text: t('shillinq', 'VAT number {vatId} is not valid according to VIES.', {
			vatId,
		}),
	}
}

/**
 * The message when VIES could not be reached, with the last confirmation if there was one.
 *
 * @param {{vatId: string, lastValidAt: string|null}} result The outcome.
 * @return {{kind: string, text: string}}
 */
function unreachableMessage(result) {
	if (result.lastValidAt) {
		return lastValidMessage(result)
	}
	return unconfirmedMessage(result.vatId)
}

/**
 * The message when VIES could not be reached and the number was valid before.
 *
 * @param {{vatId: string, lastValidAt: string}} result The outcome.
 * @return {{kind: string, text: string}}
 */
function lastValidMessage(result) {
	return {
		kind: 'warning',
		text: t(
			'shillinq',
			'VIES cannot be reached. VAT number {vatId} was last valid on {date}.',
			{ vatId: result.vatId, date: day(result.lastValidAt) },
		),
	}
}

/**
 * The message when VIES could not be reached and the number was never confirmed.
 *
 * @param {string} vatId The number.
 * @return {{kind: string, text: string}}
 */
function unconfirmedMessage(vatId) {
	return {
		kind: 'warning',
		text: t(
			'shillinq',
			'VIES cannot be reached, and VAT number {vatId} has not been confirmed before.',
			{ vatId },
		),
	}
}

/** How each outcome kind is shown. */
const TOAST = { success: showSuccess, warning: showWarning, error: showError }

/**
 * Check the record's VAT number and show the outcome.
 *
 * @param {string} type `customer` or `supplier`.
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The outcome, or null when it failed.
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */
export async function checkVatNumber(type, scope) {
	const id = recordId(scope)
	if (!id) {
		return null
	}
	try {
		const { data } = await axios.post(
			generateUrl('/apps/shillinq/api/vat-number-checks/{type}/{id}', {
				type,
				id,
			}),
		)
		const message = vatCheckMessage(data)
		TOAST[message.kind](message.text)
		emit('cn:page:refresh', {})
		return data
	} catch (e) {
		showError(
			e?.response?.data?.error
				|| t('shillinq', 'The VAT number could not be checked.'),
		)
		return null
	}
}

/**
 * The id of the record the action runs on: the page item, else the last
 * segment of the address.
 *
 * @param {{item: object}|null} scope The page scope.
 * @return {string} The id, or an empty string.
 */
function recordId(scope) {
	return scope?.item?.id || window.location.pathname.split('/').pop()
}

/**
 * Header action on the customer page.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The outcome.
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */
export function checkCustomerVatNumber(scope) {
	return checkVatNumber('customer', scope)
}

/**
 * Header action on the supplier page.
 *
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The outcome.
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */
export function checkSupplierVatNumber(scope) {
	return checkVatNumber('supplier', scope)
}
