// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The docudesk extraction-draft calls BillImportModal and ReceiptCapture
// share (receipt-extraction-consume REQ-RXC-005, gl-account-suggestion-consume
// REQ-GAC-003): the suggested booking account of a draft, the
// (re-)extraction request, and the review hint above a draft.

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { glAccountSuggestionSummary } from './extractionConfidence.js'

/**
 * Ask docudesk, through the shillinq proxy, for the suggested GL account of
 * an extraction draft. A failed request is no suggestion (REQ-GAC-006).
 *
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 * @param {string} draftId The draft's object id.
 * @param {string} schema The draft's schema slug.
 * @return {Promise<object|null>} The suggestion summary, or null.
 */
export async function suggestGlAccount(draftId, schema) {
	try {
		const response = await axios.post(
			generateUrl(
				`/apps/shillinq/api/v1/extraction/drafts/${draftId}/suggest-account?schema=${schema}`,
			),
		)
		return glAccountSuggestionSummary(response.data)
	} catch {
		return null
	}
}

/**
 * (Re-)request docudesk extraction for a document and confirm it to the
 * operator. A failed request rejects, for the caller to report.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @param {{documentUri: string, docType: string, id: string}} request The document to extract.
 * @return {Promise<void>}
 */
export async function requestExtraction(request) {
	await axios.post(
		generateUrl('/apps/shillinq/api/v1/extraction/request'),
		request,
	)
	showSuccess(
		t(
			'shillinq',
			'Extraction requested. The draft will update once docudesk responds.',
		),
	)
}

/**
 * The hint above a draft under review.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @param {boolean} requiresReview Whether any field is below the review threshold.
 * @return {string}
 */
export function draftReviewHint(requiresReview) {
	return requiresReview
		? t(
				'shillinq',
				'Some fields have low confidence — please review before confirming.',
			)
		: t(
				'shillinq',
				'Extraction confidence is high. Review and confirm.',
			)
}
