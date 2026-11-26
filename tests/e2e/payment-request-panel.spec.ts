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
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-008)
 * @e2e object-payment-requests/requirement-a-panel-shows-and-acts-on-the-requests-req-sopr-004/a-handler-sends-the-link-from-the-case
 * @e2e object-payment-requests/requirement-the-desk-sees-the-fee-and-raises-it-in-one-click-req-sopr-008/a-clerk-raises-leges-after-a-desk-intake
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

function SEND(id: string) {
	return `/index.php/apps/shillinq/api/payment-requests/${id}/send`
}
function SETTLE(id: string) {
	return `/index.php/apps/shillinq/api/payment-requests/${id}/settle`
}
const RAISE_LEGES = '/index.php/apps/shillinq/api/payment-requests/leges'
const OR = '/index.php/apps/openregister/api'
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

	test('the desk can ask for the published fee, and is told when there is none', async ({
		request,
	}) => {
		// The amount is never in the body: a clerk who could type it would be
		// back to reading a tariff out of a verordening, which is the whole gap
		// this closes. So the only thing sent is which object to raise it on.
		const response = await request.post(RAISE_LEGES, {
			headers: HEADERS,
			data: {
				register: 'dossiq',
				schema: 'Zaak',
				objectId: `zaak-e2e-${Date.now()}`,
			},
		})

		expect(
			response.headers()['content-type'] ?? '',
			'the leges route did not answer JSON',
		).toContain('application/json')

		// On a CI stack with no dossiq case and no published schedule the honest
		// answer is 404 with a reason, not a request for an invented amount.
		expect([200, 400, 403, 404, 409]).toContain(response.status())
	})

	test('a clerk raises leges after a desk intake: one pending request of the schedule\'s amount @spec REQ-SOPR-008', async ({
		request,
	}) => {
		// The case app's own type objects are not on a CI stack, so the test
		// makes a register and schema of its own as the case type, publishes a
		// schedule for it, and removes all of it afterwards.
		const run = `${Date.now()}`
		const registerSlug = `e2e-desk-${run}`
		const schemaSlug = `desk-case-${run}`
		const made: string[] = []
		const json = { ...HEADERS, 'Content-Type': 'application/json' }
		try {
			const schema = await request.post(`${OR}/schemas`, {
				headers: json,
				data: { title: `Desk case ${run}`, slug: schemaSlug, properties: { title: { type: 'string' }, caseType: { type: 'string' } } },
			})
			test.skip(!schema.ok(), `this account cannot make a schema: HTTP ${schema.status()}`)
			const schemaId = (await schema.json()).id
			made.push(`${OR}/schemas/${schemaId}`)
			const register = await request.post(`${OR}/registers`, {
				headers: json,
				data: { title: `E2E desk ${run}`, slug: registerSlug, schemas: [schemaId] },
			})
			expect(register.ok(), `register: HTTP ${register.status()}`).toBeTruthy()
			made.push(`${OR}/registers/${(await register.json()).id}`)

			const caseRes = await request.post(`${OR}/objects/${registerSlug}/${schemaSlug}`, {
				headers: json,
				data: { title: 'Dakkapel Kerkstraat 1', caseType: 'bouwvergunning' },
			})
			expect(caseRes.ok(), `case: HTTP ${caseRes.status()}`).toBeTruthy()
			const caseId = String((await caseRes.json()).id)
			made.push(`${OR}/objects/${registerSlug}/${schemaSlug}/${caseId}`)

			const scheduleRes = await request.post(`${OR}/objects/shillinq/FeeSchedule`, {
				headers: json,
				data: {
					targetApp: 'dossiq',
					register: registerSlug,
					schema: schemaSlug,
					typeProperty: 'caseType',
					typeValue: 'bouwvergunning',
					name: 'Leges bouwvergunning',
					amount: 125.5,
					currency: 'EUR',
					payAtIntake: 'optional',
					legalBasis: { regulation: 'Legesverordening 2026', article: 'Tarieventabel 2.3.1', effectiveDate: '2026-01-01' },
					validFrom: '2026-01-01',
				},
			})
			const scheduleBody = await scheduleRes.text()
			// OpenRegister before openregister#11192 drops a body's register and
			// schema, so a FeeSchedule cannot be written through its API.
			test.skip(scheduleRes.status() === 400 && scheduleBody.includes('register'), 'OpenRegister drops the schedule\'s register/schema fields (openregister#11192)')
			expect(scheduleRes.ok(), scheduleBody).toBeTruthy()
			const schedule = JSON.parse(scheduleBody)
			made.push(`${OR}/objects/shillinq/FeeSchedule/${schedule.id}`)
			test.skip(schedule.register !== registerSlug, 'OpenRegister stored its route register as the schedule\'s data (openregister#11192)')

			const raised = await request.post(RAISE_LEGES, {
				headers: json,
				data: { register: registerSlug, schema: schemaSlug, objectId: caseId },
			})
			const raisedBody = await raised.text()
			expect(raised.status(), raisedBody).toBe(200)
			const created = JSON.parse(raisedBody).request as Record<string, unknown>
			made.push(`${OR}/objects/shillinq/PaymentRequest/${String(created.id)}`)

			expect(created.requestType).toBe('leges')
			expect(created.state).toBe('pending')
			expect(created.amount, 'the schedule\'s amount, never one typed at the desk').toBe(125.5)
			expect((created.subject as Record<string, unknown>).id, 'the request is on the case').toBe(caseId)
			expect(String(created.description)).toContain('Legesverordening 2026')
		} finally {
			for (const url of made.reverse()) {
				await request.delete(url, { headers: HEADERS })
			}
		}
	})
})
