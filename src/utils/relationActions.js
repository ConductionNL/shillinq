// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// reporting-relation-both-sides: the named handlers behind the relation
// actions. The suggestions table on Relations both ways confirms or dismisses
// a pair; the report's header exports it; the supplier page opens the linked
// customer, where both sides are shown. Resolved against `customComponents`
// in src/main.js.

import axios from '@nextcloud/axios'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import RelationsExportDialog from '../modals/RelationsExportDialog.vue'

/**
 * The error text an endpoint answered with, or a fallback.
 *
 * @param {Error} error The request error.
 * @param {string} fallback The text when the endpoint said nothing.
 * @return {string} The text.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
function reason(error, fallback) {
	return (
		(error && error.response && error.response.data && error.response.data.error)
		|| fallback
	)
}

/**
 * Row action: confirm a suggested pair as a link.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<boolean>} Whether the link was made.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export async function confirmRelationSuggestion(scope) {
	const pair = scope && scope.item
	if (!pair || !pair.customerId || !pair.payeeId) {
		return false
	}
	try {
		await axios.post(generateUrl('/apps/shillinq/api/relations/links'), {
			customerId: pair.customerId,
			payeeId: pair.payeeId,
			matchedOn: pair.matchedOn,
		})
		showSuccess(
			t('shillinq', 'Linked {customer} to its supplier record.', {
				customer: pair.customerName,
			}),
		)
		emit('cn:page:refresh', {})
		return true
	} catch (e) {
		showError(reason(e, t('shillinq', 'The link could not be made.')))
		return false
	}
}

/**
 * Row action: stop suggesting a pair.
 *
 * @param {{item: object}} scope The row scope.
 * @return {Promise<boolean>} Whether the pair was dismissed.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export async function dismissRelationSuggestion(scope) {
	const pair = scope && scope.item
	if (!pair || !pair.customerId || !pair.payeeId) {
		return false
	}
	try {
		await axios.post(
			generateUrl('/apps/shillinq/api/relations/suggestions/dismiss'),
			{
				customerId: pair.customerId,
				payeeId: pair.payeeId,
			},
		)
		emit('cn:page:refresh', {})
		return true
	} catch (e) {
		showError(reason(e, t('shillinq', 'The suggestion could not be dismissed.')))
		return false
	}
}

/**
 * Header action: export Relations both ways for a period.
 *
 * @return {Promise<unknown>} The dialog's close payload.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export function openRelationsExport() {
	return spawnDialog(RelationsExportDialog, { open: true })
}

/**
 * The customer page of a supplier's linked customer, or null when it is not linked.
 *
 * @param {string} payeeId The supplier.
 * @return {Promise<string|null>} The customer page URL.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export async function linkedCustomerUrl(payeeId) {
	const response = await axios.get(
		generateUrl('/apps/shillinq/api/relations/payee/{payeeId}/both-sides', {
			payeeId,
		}),
	)
	const customerId =
		response.data && response.data.customer && response.data.customer.id
	return customerId
		? generateUrl('/apps/shillinq/bookkeeping/customers/{customerId}', {
				customerId,
			})
		: null
}

/**
 * Header action on a supplier: open the linked customer's page, where both sides are shown.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<boolean>} Whether a linked customer was opened.
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
export async function openPayeeRelation(scope) {
	const payeeId =
		(scope && scope.item && scope.item.id)
		|| window.location.pathname.split('/').pop()
	try {
		const url = await linkedCustomerUrl(payeeId)
		if (!url) {
			showInfo(
				t(
					'shillinq',
					'This supplier is not linked to a customer yet. Link it from the customer page or from Relations both ways.',
				),
			)
			return false
		}
		window.location.assign(url)
		return true
	} catch (e) {
		showError(
			reason(e, t('shillinq', 'The linked customer could not be found.')),
		)
		return false
	}
}
