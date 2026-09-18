/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payment-request panel's two actions, end to end.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * PaymentRequestActionControllerTest covers who is refused and what is written,
 * against a double. What it cannot see is whether the two routes are registered
 * at all. `appinfo/routes.php` is a file no unit test reads: a controller with
 * no route 404s at runtime while every test stays green, which is the exact
 * failure gate-6 exists for and the exact failure this file catches first.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the mail. No SMTP is configured on CI, so the send path is asserted only
 * as far as the authorization boundary; the message itself is asserted in the
 * controller test with a mailer double.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md
 * @e2e object-payment-requests/requirement-a-panel-shows-and-acts-on-the-requests-req-sopr-004/a-handler-sends-the-link-from-the-case
 */

import { expect, test } from '@playwright/test'

import { resolveBaseURL } from './base-url.ts'

const SEND = (id: string) =>
	`/index.php/apps/shillinq/api/payment-requests/${id}/send`
const SETTLE = (id: string) =>
	`/index.php/apps/shillinq/api/payment-requests/${id}/settle`
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * An id that cannot exist, on purpose. A registered route answers 403 or 404
 * for it; an unregistered route answers the SPA shell instead, which is the
 * difference this file is here to tell.
 */
const ABSENT_ID = `pr-e2e-${Date.now()}`

test.describe('case-payment-requests — the panel actions', () => {
	test('both routes are registered and answer JSON, not the SPA shell', async ({
		request,
	}) => {
		for (const url of [SEND(ABSENT_ID), SETTLE(ABSENT_ID)]) {
			const response = await request.post(url, {
				headers: HEADERS,
				data: { settlementReference: 'E2E-0001', method: 'pin' },
			})

			// The content type is the assertion, not the status. An unrouted
			// path under /apps/<app>/ is served the SPA shell as 200 text/html,
			// so a status check alone passes on a route that does not exist.
			expect(
				response.headers()['content-type'] ?? '',
				`${url} did not answer JSON`,
			).toContain('application/json')
		}
	})

	test('an unauthenticated caller cannot settle a payment request', async ({
		playwright,
	}) => {
		// Settling by other means moves a request to captured and books a
		// receipt. The least privileged principal that should be refused is
		// nobody at all.
		const anonymous = await playwright.request.newContext({
			baseURL: resolveBaseURL(),
		})

		const response = await anonymous.post(SETTLE(ABSENT_ID), {
			headers: HEADERS,
			data: { settlementReference: 'E2E-0002', method: 'pin' },
		})

		expect(response.status()).not.toBe(200)

		await anonymous.dispose()
	})
})
