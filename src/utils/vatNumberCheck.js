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
	const vatId = result.vatId
	if (result.status === 'valid') {
		return {
			kind: 'success',
			text: t('shillinq', 'VAT number {vatId} is valid (checked on {date}).', { vatId, date: day(result.lastValidAt) }),
		}
	}
	if (result.status === 'invalid') {
		return {
			kind: 'error',
			text: t('shillinq', 'VAT number {vatId} is not valid according to VIES.', { vatId }),
		}
	}
	if (result.lastValidAt) {
		return {
			kind: 'warning',
			text: t('shillinq', 'VIES cannot be reached. VAT number {vatId} was last valid on {date}.', { vatId, date: day(result.lastValidAt) }),
		}
	}
	return {
		kind: 'warning',
		text: t('shillinq', 'VIES cannot be reached, and VAT number {vatId} has not been confirmed before.', { vatId }),
	}
}

/**
 * Check the record's VAT number and show the outcome.
 *
 * @param {string} type `customer` or `supplier`.
 * @param {{item: object}} scope The page scope.
 * @return {Promise<object|null>} The outcome, or null when it failed.
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */
export async function checkVatNumber(type, scope) {
	const id = (scope && scope.item && scope.item.id) || window.location.pathname.split('/').pop()
	if (!id) {
		return null
	}
	try {
		const { data } = await axios.post(generateUrl('/apps/shillinq/api/vat-number-checks/{type}/{id}', { type, id }))
		const message = vatCheckMessage(data)
		const show = { success: showSuccess, warning: showWarning, error: showError }[message.kind]
		show(message.text)
		emit('cn:page:refresh', {})
		return data
	} catch (e) {
		const answer = e && e.response && e.response.data && e.response.data.error
		showError(answer || t('shillinq', 'The VAT number could not be checked.'))
		return null
	}
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
