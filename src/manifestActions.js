// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The functions a manifest action names with `"type": "handler"`.
//
// The library looks a handler up in two places, and a page only sees one:
//   - an INDEX page (CnIndexPage) resolves it against the `customComponents`
//     map App.vue hands to CnAppRoot;
//   - a DETAIL or DASHBOARD page's header actions dispatch through
//     CnPageRenderer's `cnDispatchAction`, which reads `manifest.actions`.
// A handler registered in only the first map does nothing on a detail page:
// "Continue in the wizard" on an import batch logged
// `Handler "openImportWizard" not found in context.handlers` (live pass S4).
// So every handler is registered in both, from this one list.

import { openMissedDepreciation } from './utils/assetActions.js'
import { classifyUnmatched, openBankLineMatch } from './utils/bankMatchActions.js'
import { openCarryOverCommitments } from './utils/commitmentYearEndApi.js'
import { openDownPaymentInvoice } from './utils/downPaymentActions.js'
import { openImportWizard } from './utils/importWizard.js'
import { openIntegriqConnections } from './utils/integriqConnections.js'
import { openProposePaymentRun } from './utils/paymentRunActions.js'
import { approveRefund, openRefundPaid } from './utils/refundActions.js'
import {
	confirmRelationSuggestion,
	dismissRelationSuggestion,
	openPayeeRelation,
	openRelationsExport,
} from './utils/relationActions.js'
import {
	openMeterReadingImport,
	rateMeterReadings,
} from './utils/usageBillingActions.js'
import {
	checkCustomerVatNumber,
	checkSupplierVatNumber,
} from './utils/vatNumberCheck.js'

export const manifestActions = Object.freeze({
	// The External Connections page's Add integration action leaves for
	// integriq (adopt-connection-registry).
	openIntegriqConnections,
	// banking-manual-match: the "Match by hand" row action and the
	// unmatched items bulk classification.
	openBankLineMatch,
	classifyUnmatched,
	// sales-down-payments: the "New down-payment invoice" header action on
	// Accounts Receivable.
	openDownPaymentInvoice,
	// banking-payment-run: the "Propose payment run" header action on
	// Payment runs.
	openProposePaymentRun,
	// planning-commitment-year-end: the "Carry open commitments to next year"
	// header action on Commitments.
	openCarryOverCommitments,
	// assets-method-change-and-reserve: the fixed asset page's missed depreciation.
	openMissedDepreciation,
	// tax-vat-number-check: Check VAT number on the customer and supplier pages.
	checkCustomerVatNumber,
	checkSupplierVatNumber,
	// sales-usage-billing: Import readings and Rate on Meter readings.
	openMeterReadingImport,
	rateMeterReadings,
	// reporting-relation-both-sides: the suggestions' row actions, the report's
	// export and the supplier page's way to the linked customer.
	confirmRelationSuggestion,
	dismissRelationSuggestion,
	openRelationsExport,
	openPayeeRelation,
	// platform-administration-import: the batch page's way back into the
	// wizard (live pass S3, S4).
	openImportWizard,
	// receivables-object-request-refund-and-credit: Approve and Mark paid on
	// the Refunds to pay page.
	approveRefund,
	openRefundPaid,
})

/**
 * Put the handlers on `manifest.actions`, where CnPageRenderer's dispatch
 * looks them up for a detail or dashboard page's header actions.
 *
 * Non-enumerable on purpose: the manifest is also serialised (the in-app
 * editor's snapshot and saved delta), and the manifest schema allows no
 * top-level `actions` key. A function map has no business in that JSON, and
 * hidden from enumeration it never reaches it, while a plain read still
 * finds it.
 *
 * @param {object} manifest The merged manifest, mutated in place.
 * @return {object} The same manifest.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function attachManifestActions(manifest) {
	Object.defineProperty(manifest, 'actions', {
		value: manifestActions,
		enumerable: false,
		configurable: true,
		writable: false,
	})
	return manifest
}
