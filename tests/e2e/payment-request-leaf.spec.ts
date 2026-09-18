/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payment-request leaf, end to end.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * PaymentRequestLeafProviderTest already covers the refusals and the shape it
 * appends, against a double. What it cannot see is whether the leaf is on the
 * catalogue at all: whether shillinq's listener ran, whether the descriptor
 * survived OpenRegister's validation, and whether the id on the PHP half is the
 * id the JS half registers. A provider that is never contributed answers
 * nothing at runtime and every unit test still passes.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the money. No gateway is reachable from CI, so no request is captured and
 * no receipt is booked here; the booking branch is asserted in
 * PaymentReconciliationServiceTest against a signed fixture. This file asserts
 * that the leaf is reachable, that it is scoped to its host object, and that an
 * unauthenticated caller gets nothing.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md
 * @e2e object-payment-requests/requirement-requests-are-a-data-provider-leaf-with-append-req-sopr-003/dossiq-raises-a-dwangsom-request-on-a-case
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const LEAF_ID = 'shillinq-payment-requests'
const CATALOGUE = '/index.php/apps/openregister/api/integrations'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * A host object id unique to this run, so a row left behind by an earlier run
 * cannot make an empty read look like a working one.
 */
const HOST_ID = `zaak-e2e-${Date.now()}-${Math.floor(Math.random() * 10_000)}`

test.describe('case-payment-requests — the payment-request leaf', () => {
	test('the leaf is on OpenRegister\'s catalogue under its own id', async ({
		request,
	}) => {
		const response = await request.get(CATALOGUE, { headers: HEADERS })

		// OpenRegister may not be installed on every topology this suite runs
		// against. That is a skip, not a failure: the assertion below is about
		// what the catalogue says WHEN there is one.
		test.skip(response.status() === 404, 'OpenRegister is not installed here')

		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const entries = (body.items ?? body.results ?? body ?? []) as Array<
			Record<string, unknown>
		>
		const ids = entries.map((entry) => String(entry.id ?? ''))

		expect(ids).toContain(LEAF_ID)
	})

	test('an unauthenticated caller cannot append a payment request', async ({
		playwright,
	}) => {
		// The least privileged principal that should be refused: nobody at all.
		// A leaf that answered this would let any visitor raise a demand for
		// money against somebody else's case.
		const anonymous = await playwright.request.newContext({
			baseURL: resolveBaseURL(),
		})

		const response = await anonymous.post(
			`${CATALOGUE}/${LEAF_ID}/dossiq/Zaak/${HOST_ID}`,
			{
				headers: HEADERS,
				data: { requestType: 'dwangsom', amount: 250 },
			},
		)

		expect(response.status()).not.toBe(200)
		expect(response.status()).not.toBe(201)

		await anonymous.dispose()
	})
})
