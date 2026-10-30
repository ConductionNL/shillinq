// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The JS half of `shillinq-financial-documents-panel` (REQ-FDO-003): every
// quote, invoice, credit note, payment request and payout linked to the host
// object, with the acts the caller holds. It reads through the
// `shillinq-financial-documents` DATA-PROVIDER leaf.

import { translate as t } from '@nextcloud/l10n'
import { mountPairFor, sharedRegistry } from './leafMount.js'

/**
 * The leaf id, equal to
 * `PaymentRequestLeafRegistrationListener::DOCUMENTS_PANEL_ID` on the server.
 *
 * @type {string}
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export const FINANCIAL_DOCUMENTS_PANEL_ID = 'shillinq-financial-documents-panel'

const pair = mountPairFor(() => import('./ShillinqFinancialDocumentsPanel.vue'))

/**
 * The descriptor. Every field the server descriptor also states is stated
 * identically, because those two sets are what gate-24 compares.
 *
 * @type {object}
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export const financialDocumentsPanelDescriptor = {
	id: FINANCIAL_DOCUMENTS_PANEL_ID,
	label: t('shillinq', 'Financial documents'),
	icon: 'FileDocumentMultipleOutline',
	group: 'Finance',
	requiredApp: 'shillinq',
	order: 49,
	surfaces: ['detail-page', 'single-entity'],
	renderMode: 'mount',
	chrome: 'card',
	mount: pair.mount,
	unmount: pair.unmount,
	defaultSize: { w: 4, h: 3 },
}

/**
 * Register the panel on the shared registry.
 *
 * @param {object} [globalRef] The global to attach to, defaulting to `window`.
 *
 * @return {void}
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
export function registerFinancialDocumentsLeaf(globalRef) {
	const integrations = sharedRegistry(globalRef)
	if (integrations === null) {
		return
	}

	try {
		integrations.register(financialDocumentsPanelDescriptor)
	} catch (e) {
		// First registration wins (AD-13); the app bundle and the init script both register.
		// eslint-disable-next-line no-console
		console.warn('[shillinq] the financial documents panel was already registered', e)
	}
}
