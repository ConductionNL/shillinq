// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The JS half of `shillinq-payment-requests-panel` (REQ-SOPR-004).
//
// The server half has been in place since case-payment-requests: a
// LeafDescriptor of kind `render-surface`, a provider that lists and appends,
// and two controller routes for the actions. This half was the one that never
// landed, and nothing at runtime says so. A descriptor with no registration
// advertises a surface in the `openregister.integrations.leaves` capability
// that mounts nothing, which is how a handler could open a case, see no
// payment requests tab, and have no way to tell an empty case from a missing
// panel. gate-24 is the check that names it.
//
// The panel reads through the `shillinq-payment-requests` DATA-PROVIDER leaf,
// which is why that id carries no registration of its own: it is a source, not
// a surface, and registering it too would put a second identical tab on every
// object.

import { translate as t } from '@nextcloud/l10n'
import { mountPairFor, sharedRegistry } from './leafMount.js'

/**
 * The leaf id, equal to `PaymentRequestLeafRegistrationListener::PANEL_ID` on
 * the server. The two halves correlate by this string and nothing else.
 *
 * @type {string}
 */
export const PAYMENT_REQUESTS_PANEL_ID = 'shillinq-payment-requests-panel'

const pair = mountPairFor(() => import('./ShillinqPaymentRequestsPanel.vue'))

/**
 * The descriptor. Every field here that the server descriptor also states is
 * stated identically, because those two sets are what gate-24 compares. A half
 * that declares a surface by omission is how two halves drift apart unnoticed.
 *
 * @type {object}
 */
export const paymentRequestsPanelDescriptor = {
	id: PAYMENT_REQUESTS_PANEL_ID,
	label: t('shillinq', 'Payment requests'),
	icon: 'CreditCardOutline',
	group: 'Finance',
	requiredApp: 'shillinq',
	order: 50,
	surfaces: ['detail-page', 'single-entity'],
	renderMode: 'mount',
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
 */
export function registerPaymentRequestsLeaf(globalRef) {
	const integrations = sharedRegistry(globalRef)
	if (integrations === null) {
		return
	}

	try {
		integrations.register(paymentRequestsPanelDescriptor)
	} catch (e) {
		// First registration wins (AD-13) and a duplicate throws in dev. The
		// app bundle and the every-page init script both register, so a
		// duplicate on a shillinq page is expected, not a boot failure.
		// eslint-disable-next-line no-console
		console.warn(
			'[shillinq] the payment requests panel was already registered',
			e,
		)
	}
}
