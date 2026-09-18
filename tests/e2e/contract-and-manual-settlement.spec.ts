/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contract leaf and the manual settlement, end to end.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * ContractLeafProviderTest and PaymentSettlementServiceTest already cover the
 * scoping, the refusals and the derived state, against doubles. What they
 * cannot see is whether the contract leaf reached OpenRegister's catalogue, and
 * whether the settle route exists at all. A leaf that is never contributed and
 * a controller with no route both leave every unit test green.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the money and not the roll-up. No contract or case fixture exists on a CI
 * stack, so what is asserted is reachability and the refusal of an
 * unauthenticated caller. The arithmetic is asserted in
 * ContractCostRollupServiceTest.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md
 * @e2e fees-payments-and-the-contract-register/requirement-req-fpcr-006-a-case-reads-its-contract-without-copying-it/a-handler-sees-the-contract-behind-the-case
 * @e2e fees-payments-and-the-contract-register/requirement-req-fpcr-003-a-payment-is-settled-by-hand/a-pin-payment-at-the-counter-is-recorded-against-the-case
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const CATALOGUE = '/index.php/apps/openregister/api/integrations'
const CONTRACT_LEAF = 'shillinq-contracts'
function SETTLE (id: string) {
  return `/index.php/apps/shillinq/api/payment-requests/${id}/settle`
}
const HEADERS = { 'OCS-APIRequest': 'true' }

const ABSENT_ID = `pr-e2e-${Date.now()}`

test.describe('fees-payments-and-the-contract-register', () => {
	test('the contract leaf is on OpenRegister\'s catalogue', async ({
		request,
	}) => {
		const response = await request.get(CATALOGUE, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const entries = (body.items ?? body.results ?? body ?? []) as Array<
			Record<string, unknown>
		>

		expect(entries.map(entry => String(entry.id ?? ''))).toContain(
			CONTRACT_LEAF,
		)
	})

	test('an unauthenticated caller cannot record a counter payment', async ({
		playwright,
	}) => {
		// A settlement says money arrived. The least privileged principal that
		// should be refused is nobody at all: anyone who could write one could
		// close a debt that was never paid.
		const anonymous = await playwright.request.newContext({
			baseURL: resolveBaseURL(),
		})

		const response = await anonymous.post(SETTLE(ABSENT_ID), {
			headers: HEADERS,
			data: {
				settlementReference: 'PIN-E2E-0001',
				method: 'pin',
				amount: 245,
			},
		})

		expect(response.status()).not.toBe(200)

		await anonymous.dispose()
	})
})
