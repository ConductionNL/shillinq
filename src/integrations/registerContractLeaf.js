// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The JS half of `shillinq-contracts-panel` (REQ-FPCR-006).
//
// Same story as the payment requests panel: the descriptor, the provider and
// the projection all shipped, the registration did not, so the capability
// advertised a contract surface that mounted nothing. A handler could not see
// which agreement a case runs under, which is the whole point of reading the
// contract instead of copying it onto the case.
//
// The panel reads through the `shillinq-contracts` DATA-PROVIDER leaf, which
// carries no registration of its own: it is the source this surface reads.

import { translate as t } from '@nextcloud/l10n'
import ShillinqContractPanel from './ShillinqContractPanel.vue'
import { mountPairFor, sharedRegistry } from './leafMount.js'

/**
 * The leaf id, equal to
 * `PaymentRequestLeafRegistrationListener::CONTRACT_PANEL_ID` on the server.
 *
 * @type {string}
 */
export const CONTRACTS_PANEL_ID = 'shillinq-contracts-panel'

const pair = mountPairFor(ShillinqContractPanel)

/**
 * The descriptor. Every field the server descriptor also states is stated
 * identically here, which is the pair gate-24 compares.
 *
 * @type {object}
 */
export const contractsPanelDescriptor = {
	id: CONTRACTS_PANEL_ID,
	label: t('shillinq', 'Contract'),
	icon: 'FileDocumentOutline',
	group: 'Finance',
	requiredApp: 'shillinq',
	order: 51,
	surfaces: ['detail-page', 'single-entity'],
	renderMode: 'mount',
	mount: pair.mount,
	unmount: pair.unmount,
	defaultSize: { w: 4, h: 3 },
}

/**
 * Register the contract panel on the shared registry.
 *
 * @param {object} [globalRef] The global to attach to, defaulting to `window`.
 *
 * @return {void}
 */
export function registerContractLeaf(globalRef) {
	const integrations = sharedRegistry(globalRef)
	if (integrations === null) {
		return
	}

	try {
		integrations.register(contractsPanelDescriptor)
	} catch (e) {
		// First registration wins (AD-13) and a duplicate throws in dev.
		// eslint-disable-next-line no-console
		console.warn('[shillinq] the contract panel was already registered', e)
	}
}
