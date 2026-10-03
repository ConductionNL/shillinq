/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the JS half of shillinq's two finance leaves
 * (src/integrations/, REQ-SOPR-004 and REQ-FPCR-006).
 *
 * WHAT THESE ASSERT THAT NOTHING ELSE DID
 * ---------------------------------------
 * Both leaves shipped a server descriptor, a provider and two action routes,
 * and no client registration at all. Every PHP test passed, the app built, and
 * the panels mounted nowhere. So the first assertion here is the one that was
 * missing: calling the registration puts the panel's own id on the shared
 * registry, with the complete mount pair the host needs to render it.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-004)
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShillinqContractPanel from '../../src/integrations/ShillinqContractPanel.vue'
import {
	CONTRACTS_DATA_LEAF,
	formatAmount,
	hostIdentity,
	isResolvable,
	PAYMENT_REQUESTS_DATA_LEAF,
	readLeaf,
	sendPaymentLink,
	settleByOtherMeans,
} from '../../src/integrations/leafApi.js'
import {
	CONTRACTS_PANEL_ID,
	registerContractLeaf,
} from '../../src/integrations/registerContractLeaf.js'
import {
	PAYMENT_REQUESTS_PANEL_ID,
	registerPaymentRequestsLeaf,
} from '../../src/integrations/registerPaymentRequestsLeaf.js'

/**
 * A global with no OpenRegister bundle loaded yet, which is the normal case on
 * another app's page: shillinq's init script runs before OpenRegister installs
 * the real registry.
 *
 * @return {object} The fake global.
 */
function emptyGlobal() {
	return {}
}

/**
 * A global carrying a real-looking registry, which is the case on shillinq's
 * own pages where main.js installs it first.
 *
 * @return {{global: object, registered: object[]}} The fake global and the log.
 */
function globalWithRegistry() {
	const registered = []
	return {
		global: {
			OCA: {
				OpenRegister: {
					integrations: {
						register(entry) {
							registered.push(entry)
							return entry
						},
					},
				},
			},
		},
		registered,
	}
}

describe('finance leaves — the registration that never happened', () => {
	it('🔴 puts the payment requests panel on the shared registry', () => {
		const { global: target, registered } = globalWithRegistry()

		registerPaymentRequestsLeaf(target)

		expect(registered.map((entry) => entry.id)).toEqual([
			'shillinq-payment-requests-panel',
		])
	})

	it('🔴 puts the contract panel on the shared registry', () => {
		const { global: target, registered } = globalWithRegistry()

		registerContractLeaf(target)

		expect(registered.map((entry) => entry.id)).toEqual([
			'shillinq-contracts-panel',
		])
	})

	it('ships the complete mount pair, so the host has something to render', () => {
		const { global: target, registered } = globalWithRegistry()

		registerPaymentRequestsLeaf(target)
		registerContractLeaf(target)

		for (const entry of registered) {
			expect(entry.renderMode).toBe('mount')
			expect(typeof entry.mount).toBe('function')
			expect(typeof entry.unmount).toBe('function')
		}
	})

	it('declares the surfaces its server half declares, and only real ones', () => {
		const { global: target, registered } = globalWithRegistry()

		registerPaymentRequestsLeaf(target)
		registerContractLeaf(target)

		for (const entry of registered) {
			// LeafDescriptor::VALID_SURFACES, not `widget` and `tab`, which the
			// server half used to say and which no grid matches.
			expect(entry.surfaces).toEqual(['detail-page', 'single-entity'])
			expect(entry.requiredApp).toBe('shillinq')
			expect(entry.group).toBe('Finance')
		}
	})

	it('queues the descriptor when OpenRegister has not loaded yet', () => {
		const target = emptyGlobal()

		registerPaymentRequestsLeaf(target)
		registerContractLeaf(target)

		const queue = target.OCA.OpenRegister.integrations._queue
		expect(queue.map((entry) => entry.id)).toEqual([
			PAYMENT_REQUESTS_PANEL_ID,
			CONTRACTS_PANEL_ID,
		])
	})

	it('survives the second registration, because both entry points register', () => {
		const target = {
			OCA: {
				OpenRegister: {
					integrations: {
						register() {
							throw new Error('duplicate registration for that id')
						},
					},
				},
			},
		}

		expect(() => registerPaymentRequestsLeaf(target)).not.toThrow()
	})
})

describe('finance leaves — the calls the panels make', () => {
	beforeEach(() => {
		axios.get = vi.fn(async () => ({ data: { items: [] } }))
		axios.post = vi.fn(async () => ({ data: { sent: true } }))
	})

	it('reads the payment requests through the data-provider leaf', async () => {
		await readLeaf(
			{ register: 'dossiq', schema: 'Zaak', objectId: 'zaak-1' },
			PAYMENT_REQUESTS_DATA_LEAF,
		)

		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/dossiq/Zaak/zaak-1/integrations/shillinq-payment-requests',
		)
	})

	it('reads the contract through its own data-provider leaf', async () => {
		await readLeaf(
			{ register: 'dossiq', schema: 'Zaak', objectId: 'zaak-1' },
			CONTRACTS_DATA_LEAF,
		)

		expect(axios.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/dossiq/Zaak/zaak-1/integrations/shillinq-contracts',
		)
	})

	it('sends the payment link through shillinq own action route', async () => {
		await sendPaymentLink('pr-1')

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/payment-requests/pr-1/send',
			{},
		)
	})

	it('records a counter payment through the settle route', async () => {
		await settleByOtherMeans('pr-1', {
			method: 'pin',
			settlementReference: 'REF-1',
			amount: 0,
			reason: '',
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/payment-requests/pr-1/settle',
			{ method: 'pin', settlementReference: 'REF-1', amount: 0, reason: '' },
		)
	})
})

describe('finance leaves — the host identity the registry hands over', () => {
	it('prefers the discrete props', () => {
		expect(
			hostIdentity({
				register: 'a',
				schema: 'b',
				objectId: 'c',
				integrationContext: { register: 'x' },
			}),
		).toEqual({ register: 'a', schema: 'b', objectId: 'c' })
	})

	it('falls back to the integration context', () => {
		expect(
			hostIdentity({
				integrationContext: { register: 'a', schema: 'b', objectId: 'c' },
			}),
		).toEqual({ register: 'a', schema: 'b', objectId: 'c' })
	})

	it('refuses to read with an incomplete identity', () => {
		expect(isResolvable({ register: 'a', schema: 'b', objectId: '' })).toBe(
			false,
		)
		expect(isResolvable({ register: 'a', schema: 'b', objectId: 'c' })).toBe(
			true,
		)
	})
})

describe('contract panel — an absent remaining value is not zero', () => {
	const bind = (name, ...args) =>
		ShillinqContractPanel.methods[name].call({ t: (app, text) => text }, ...args)

	it('🔴 says the roll-up has not run rather than showing 0,00', () => {
		expect(bind('remainingLine', { remainingValue: null })).toBe(
			'The remaining value has not been rolled up yet.',
		)
	})

	it('reports a real remaining value as money', () => {
		const line = bind('remainingLine', {
			remainingValue: 1250.5,
			currency: 'EUR',
		})
		expect(line).toContain('1.250,50')
	})

	it('names the term when both dates are set', () => {
		expect(
			bind('termLine', { startDate: '2026-01-01', endDate: '2026-12-31' }),
		).toBe('Runs from 2026-01-01 until 2026-12-31')
	})
})

describe('formatAmount', () => {
	it('formats euros the way a Dutch desk reads them', () => {
		expect(formatAmount(12.5, 'EUR')).toContain('12,50')
	})

	it('returns nothing for a value that is not a number', () => {
		expect(formatAmount('not money', 'EUR')).toBe('')
	})
})
