/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: online payment through integriq, from Send payment link to a
 * request and an invoice that end paid (receivables-payment-links tasks 3.3
 * and 4.1).
 *
 * Task 3.3, REQ-RPL-002 (no sibling app needed):
 *
 *   1. `GET /api/online-payment/status` answers `{live, reason, message}`;
 *      while the payment adapter is dormant it says `not-set-up` with the
 *      line that names where to configure it;
 *   2. the Payment requests page opens from its route and lists requests;
 *   3. on a pending request's page, Send payment link is enabled when online
 *      payment is live and disabled when it is not. An instance without a
 *      pending request skips this step and says so;
 *   4. a pay token whose signature does not verify opens nothing: the guest
 *      forward of `POST /api/portal/payments/initiate` answers 403
 *      (REQ-RPL-006).
 *
 * Task 4.1, the live pass against integriq's Mollie test source. It skips,
 * and says why, when integriq is not installed or online payment is not live:
 *
 *   5. a case leges request of EUR 97.50, raised through the payment requests
 *      leaf (`POST .../integrations/shillinq-payment-requests/dossiq/Zaak/{id}`,
 *      the route the case panel posts to), stores a Mollie checkout link, its
 *      provider payment id (`tr_...`) and the checkout expiry (REQ-RPL-001);
 *   6. integriq's `nl.conduction.payment.status` CloudEvent `captured` for that
 *      payment id, saved in integriq's event register the way integriq's
 *      verified Mollie webhook saves it, moves the request to captured with a
 *      settlement and no settle pressed; the same event again changes nothing
 *      (REQ-RPL-003);
 *   7. portal Pay now on an open invoice of EUR 312.00 (portaliq's forward,
 *      with a portal subject assertion signed by portaliq's secret) answers a
 *      Mollie checkout for EUR 312.00, stores it on the request, and the
 *      captured status settles the invoice to paid (REQ-RPL-005, REQ-RPL-003).
 *      Signing the assertion needs portaliq's `jwt_signing_secret` in
 *      `PORTALIQ_JWT_SIGNING_SECRET`; without it, or without portaliq, the step
 *      skips and says so;
 *   8. the invoice pay link: the signed token reaches a person only in the
 *      invoice mail, so that path is a test.fixme with the reason;
 *   9. paying in Mollie's own test checkout and Mollie calling integriq's
 *      webhook is the provider's real network, a test.fixme as well.
 *
 * The payment ids and end states the task wants in the PR body are recorded as
 * test annotations. The live pass works in an administration of its own (a
 * unique id with a membership for the suite's administrator), so no seed
 * request or invoice is paid. Every object written here, in shillinq's,
 * integriq's or portaliq's register, is removed afterwards; a booking the
 * capture posted stays in that administration.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/receivables-payment-links/specs/ar-invoice-payment-links/spec.md
 * @spec openspec/changes/receivables-payment-links/specs/portal-payment-initiation/spec.md
 * @spec openspec/changes/receivables-payment-links/tasks.md
 *
 * @e2e ar-invoice-payment-links::an-instance-without-a-payment-source
 * @e2e ar-invoice-payment-links::a-case-handler-sends-a-leges-payment-link
 * @e2e ar-invoice-payment-links::the-applicant-pays-and-the-case-sees-it
 * @e2e ar-invoice-payment-links::a-paid-invoice-closes-itself
 * @e2e ar-invoice-payment-links::a-customer-pays-from-the-pdf-three-weeks-later
 * @e2e portal-payment-initiation::a-resident-pays-a-tax-assessment-in-the-portal
 * @e2e portal-payment-initiation::a-customer-without-a-portal-account-pays-from-the-invoice-mail
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { createHmac } from 'node:crypto'
import { resolveBaseURL } from './base-url.ts'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OR = '/index.php/apps/openregister/api'
const OBJECTS = `${OR}/objects/shillinq`
const HEADERS = { 'OCS-APIRequest': 'true' }
const NOT_SET_UP = 'Online payment is not set up: configure a payment source in integriq.'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION = `${UNIQUE_PREFIX}-rpl`
const CUSTOMER = `${UNIQUE_PREFIX}-cust`
const PORTAL_SUBJECT = `${UNIQUE_PREFIX}-resident`
const PORTALIQ_SECRET = process.env.PORTALIQ_JWT_SIGNING_SECRET ?? ''
const NEEDED = ['AdministrationMembership', 'PaymentRequest', 'ARInvoice']

/** The online payment status (OnlinePaymentStatusController::status). */
interface OnlinePaymentStatus {
	live: boolean
	reason: string
	message: string
}

/**
 * Close Nextcloud's first-run wizard when it covers the page.
 *
 * @param page The page.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Whether an app is enabled: listed among the enabled apps, or, when the OCS
 * list cannot be read, its app route answering.
 *
 * @param api   The administrator's request context.
 * @param appId The app id.
 * @return True when the app is there.
 */
async function appInstalled(api: APIRequestContext, appId: string): Promise<boolean> {
	const apps = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { ...HEADERS, Accept: 'application/json' },
	})
	if (apps.ok()) {
		return (await apps.text()).includes(`"${appId}"`)
	}
	const route = await api.get(`/index.php/apps/${appId}/`, { maxRedirects: 0 })
	return route.status() === 200
}

/**
 * The id OpenRegister answers an object under.
 *
 * @param row The object, or a wrapped answer.
 * @return Its id.
 */
function idOf(row: Record<string, unknown>): string {
	const self = row['@self'] as Record<string, unknown> | undefined
	const data = row.data as Record<string, unknown> | undefined
	return String(self?.id ?? row.id ?? row.uuid ?? data?.id ?? '')
}

/**
 * Base64url without padding.
 *
 * @param value Text or bytes.
 * @return The encoding.
 */
function b64url(value: string | Buffer): string {
	return Buffer.from(value).toString('base64url')
}

/**
 * A portal subject assertion as portaliq mints it: HS256 over the claims the
 * receiver checks (PortalAssertionVerifier).
 *
 * @param secret  portaliq's signing secret.
 * @param subject The portal subject.
 * @return The compact JWT.
 */
function portalAssertion(secret: string, subject: string): string {
	const now = Math.floor(Date.now() / 1000)
	const header = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
	const claims = b64url(JSON.stringify({ use: 'assertion', iss: 'portaliq', sub: subject, audience: 'customer', iat: now, exp: now + 300 }))
	const signature = b64url(createHmac('sha256', secret).update(`${header}.${claims}`).digest())
	return `${header}.${claims}.${signature}`
}

test.describe('receivables-payment-links: Send payment link and the payment source', () => {
	test('the status says whether online payment is set up @spec REQ-RPL-002', async ({ request }) => {
		const res = await request.get(`${API}/online-payment/status`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const status = (await res.json()) as OnlinePaymentStatus
		expect(typeof status.live).toBe('boolean')
		if (status.live) {
			expect(status.reason).toBe('')
		} else {
			expect(status.reason).toBe('not-set-up')
			expect(status.message).not.toBe('')
		}
	})

	test('the Payment requests page opens @spec REQ-RPL-002', async ({ page }) => {
		await page.goto(`/index.php${APP}/bookkeeping/payment-requests`)
		await dismissOverlays(page)
		await expect(page.getByText('Payment requests').first()).toBeVisible()
	})

	test('Send payment link on a pending request follows the status @spec REQ-RPL-002', async ({ page, request }) => {
		const statusRes = await request.get(`${API}/online-payment/status`, { headers: HEADERS })
		expect(statusRes.status()).toBe(200)
		const status = (await statusRes.json()) as OnlinePaymentStatus

		const list = await request.get(`${OBJECTS}/PaymentRequest?state=pending&_limit=1`, { headers: HEADERS })
		expect(list.ok(), await list.text()).toBe(true)
		const pending = (((await list.json()) as { results?: Array<{ id?: string, '@self'?: { id?: string } }> }).results ?? [])[0]
		test.skip(pending === undefined, 'the instance holds no pending payment request')
		const id = String(pending?.id ?? pending?.['@self']?.id ?? '')

		await page.goto(`/index.php${APP}/bookkeeping/payment-requests/${encodeURIComponent(id)}`)
		await dismissOverlays(page)
		const send = page.getByRole('button', { name: 'Send payment link' }).first()
		await expect(send).toBeVisible()
		if (status.live) {
			await expect(send).toBeEnabled()
		} else {
			await expect(send).toBeDisabled()
			await expect(page.getByText(NOT_SET_UP).first()
				.or(page.locator(`[title="${NOT_SET_UP}"]`).first())).toBeAttached()
		}
	})

	test('a pay token whose signature does not verify opens nothing @spec REQ-RPL-006', async ({ playwright }) => {
		// portaliq's guest page forwards without a session or an assertion; the
		// token is the only credential (PortalPaymentInitiationController::initiate).
		const guest = await playwright.request.newContext({ baseURL: resolveBaseURL() })
		const payload = b64url(JSON.stringify({ a: ADMINISTRATION, i: '00000000-0000-4000-8000-000000000000', g: 1 }))
		const res = await guest.post(`${API}/portal/payments/initiate`, {
			headers: { 'Content-Type': 'application/json' },
			data: { payToken: `${payload}.${b64url('not-the-signature')}` },
		})
		expect(res.status(), await res.text()).toBe(403)
		expect(await res.json()).toEqual({ error: 'forbidden' })
		await guest.dispose()
	})
})

test.describe('receivables-payment-links: the live pass through integriq (task 4.1)', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let integriq = false
	let live = false
	/** Objects in another app's register, removed in afterAll. */
	const foreign: Array<{ register: string, schema: string, id: string }> = []
	/** Objects the server wrote on the test's behalf, removed in afterAll. */
	const written: Array<{ schema: string, id: string }> = []

	/**
	 * The CSRF token OpenRegister's and shillinq's write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get('/index.php/apps/shillinq/')).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * Headers for a write by the signed-in administrator.
	 *
	 * @return The headers.
	 */
	async function writeHeaders(): Promise<Record<string, string>> {
		return { ...HEADERS, requesttoken: await requestToken(), 'Content-Type': 'application/json' }
	}

	/**
	 * Save an object in another app's register through OpenRegister's object API.
	 *
	 * @param register The register slug.
	 * @param schema   The schema slug.
	 * @param data     The object.
	 * @return Its id.
	 */
	async function createForeign(register: string, schema: string, data: Record<string, unknown>): Promise<string> {
		const res = await api.post(`${OR}/objects/${register}/${schema}`, { headers: await writeHeaders(), data })
		expect(res.ok(), `create ${register}/${schema}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
		const id = idOf(await res.json())
		expect(id, `no id for the ${register}/${schema} object`).not.toBe('')
		foreign.push({ register, schema, id })
		return id
	}

	/**
	 * The shillinq objects of a schema whose fields equal the filters.
	 *
	 * @param schema  The schema slug.
	 * @param filters Field filters.
	 * @return The objects.
	 */
	async function list(schema: string, filters: Record<string, string>): Promise<Array<Record<string, unknown>>> {
		const query = new URLSearchParams({ ...filters, _limit: '50' })
		const res = await api.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), `list ${schema}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
		return rows.filter((row) => Object.entries(filters).every(([field, value]) => String(row[field] ?? '') === value))
	}

	/**
	 * Save integriq's payment status CloudEvent in integriq's event register, as
	 * integriq's `PaymentIntentService::emitStatusEvent()` does after Mollie's
	 * verified webhook. shillinq's IntegriqCloudEventListener takes it from there.
	 *
	 * @param providerPaymentId The provider payment id the request stores.
	 * @param outcome           The payment outcome.
	 * @return The event object's id.
	 */
	async function paymentStatus(providerPaymentId: string, outcome: string): Promise<string> {
		return createForeign('integriq', 'event', {
			specversion: '1.0',
			id: `${UNIQUE_PREFIX}-${providerPaymentId}-${outcome}-${foreign.length}`,
			source: '/apps/integriq/payments',
			type: 'nl.conduction.payment.status',
			subject: providerPaymentId,
			time: new Date().toISOString(),
			datacontenttype: 'application/json',
			data: {
				paymentIntentId: providerPaymentId,
				outcome,
				errorCode: null,
				errorMessage: null,
				settlementReference: `${UNIQUE_PREFIX}-settlement`,
				gatewayFeeAmount: 0.29,
			},
		})
	}

	/**
	 * Assert a request carries a live Mollie checkout and its payment id, and
	 * record the id for the PR body.
	 *
	 * @param request The stored PaymentRequest.
	 * @param label   The annotation type.
	 * @return The provider payment id.
	 */
	function expectCheckout(request: Record<string, unknown>, label: string): string {
		const link = String(request.paymentLink ?? '')
		const providerPaymentId = String(request.providerPaymentId ?? '')
		expect(new URL(link).protocol).toBe('https:')
		expect(new URL(link).hostname, 'a checkout of integriq\'s Mollie source').toMatch(/(^|\.)mollie\.com$/)
		expect(providerPaymentId).toMatch(/^tr_/)
		expect(String(request.paymentIntentId ?? '')).toBe(providerPaymentId)
		expect(Date.parse(String(request.checkoutExpiresAt ?? ''))).toBeGreaterThan(Date.now())
		expect(request.state).toBe('pending')
		test.info().annotations.push({ type: label, description: `${providerPaymentId} (${link})` })
		return providerPaymentId
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		integriq = await appInstalled(api, 'integriq')
		if (integriq) {
			const status = await api.get(`${API}/online-payment/status`, { headers: HEADERS })
			live = status.ok() && ((await status.json()) as OnlinePaymentStatus).live === true
		}
	})

	test.beforeEach(async () => {
		test.skip(!integriq, 'integriq is not installed: the live pass needs its Mollie test source')
		test.skip(!live, 'integriq is installed but online payment is not live: activate a Mollie payment source in test mode in integriq')
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		if ((await list('AdministrationMembership', { administrationId: ADMINISTRATION, userId: ADMIN_USER })).length === 0) {
			await fx.create('AdministrationMembership', {
				userId: ADMIN_USER,
				administrationId: ADMINISTRATION,
				role: 'controller',
				mayPostJournalEntries: true,
				validFrom: '2024-01-01',
				validUntil: null,
			})
		}
	})

	test.afterAll(async () => {
		const headers = api ? await writeHeaders().catch(() => HEADERS) : HEADERS
		for (const { register, schema, id } of [...foreign].reverse()) {
			await api?.delete(`${OR}/objects/${register}/${schema}/${id}`, { headers }).catch(() => undefined)
		}
		for (const { schema, id } of [...written].reverse()) {
			await fx?.remove(schema, id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a case leges request gets a Mollie checkout and ends paid without settle @spec REQ-RPL-001 @spec REQ-RPL-003', async ({ page }) => {
		// 5. The case panel raises the request through the payment requests leaf.
		const caseId = `${UNIQUE_PREFIX}-Z-2026-00188`
		const raised = await api.post(`${OR}/integrations/shillinq-payment-requests/dossiq/Zaak/${encodeURIComponent(caseId)}`, {
			headers: await writeHeaders(),
			data: {
				requestType: 'leges',
				amount: 97.5,
				currency: 'EUR',
				description: 'Omgevingsvergunning kappen, 1 boom',
				administrationId: ADMINISTRATION,
				debtor: { name: `Aanvrager ${UNIQUE_PREFIX}`, email: 'aanvrager@example.nl' },
			},
		})
		const raisedText = await raised.text()
		expect(raised.ok(), `raise leges: HTTP ${raised.status()} ${raisedText}`).toBeTruthy()
		const requestId = idOf(JSON.parse(raisedText) as Record<string, unknown>)
		expect(requestId).not.toBe('')
		written.push({ schema: 'PaymentRequest', id: requestId })

		const request = await fx.get('PaymentRequest', requestId)
		expect(money(Number(request.amount))).toBe(97.5)
		expect(request.requestType).toBe('leges')
		const providerPaymentId = expectCheckout(request, 'leges payment id')

		// 6. integriq reports the payment captured.
		const eventId = await paymentStatus(providerPaymentId, 'captured')
		test.info().annotations.push({ type: 'leges status event', description: eventId })
		await expect.poll(async () => (await fx.get('PaymentRequest', requestId)).state, { timeout: 30_000 }).toBe('captured')
		const captured = await fx.get('PaymentRequest', requestId)
		expect(String(captured.capturedAt ?? '')).not.toBe('')
		expect(String(captured.settledAt ?? ''), 'settled by the provider, not by a settle').not.toBe('')
		const settlements = (captured.settlements ?? []) as Array<Record<string, unknown>>
		expect(settlements.length).toBeGreaterThan(0)

		// The same status again changes nothing.
		await paymentStatus(providerPaymentId, 'captured')
		await page.waitForTimeout(3_000)
		const again = await fx.get('PaymentRequest', requestId)
		expect(again.state).toBe('captured')
		expect(again.capturedAt).toBe(captured.capturedAt)
		expect(((again.settlements ?? []) as unknown[]).length).toBe(settlements.length)
		test.info().annotations.push({ type: 'leges end state', description: String(again.state) })

		await page.goto(`/index.php${APP}/bookkeeping/payment-requests/${encodeURIComponent(requestId)}`)
		await dismissOverlays(page)
		await expect(page.getByText(/captured/i).first()).toBeVisible({ timeout: 20_000 })
	})

	test('portal Pay now on an open EUR 312.00 invoice opens a Mollie checkout and the invoice ends paid @spec REQ-RPL-005 @spec REQ-RPL-003', async ({ page, playwright }) => {
		test.skip(!(await appInstalled(api, 'portaliq')), 'portaliq is not installed: the portal Pay now forward needs its portal account register')
		test.skip(PORTALIQ_SECRET.length < 16, 'PORTALIQ_JWT_SIGNING_SECRET is not set: the portal subject assertion is signed with portaliq\'s jwt_signing_secret, which only an operator can read')

		// The resident's open invoice in the test's administration.
		const { id: invoiceId } = await fx.create('ARInvoice', {
			invoiceNumber: `${UNIQUE_PREFIX}-AFV-2026`,
			customerId: CUSTOMER,
			administrationId: ADMINISTRATION,
			invoiceDate: '2026-11-01',
			dueDate: '2026-12-01',
			periodId: '2026-11',
			netAmount: 312,
			vatAmount: 0,
			grossAmount: 312,
			currency: 'EUR',
			lifecycleState: 'issued',
		})
		if (String((await fx.get('ARInvoice', invoiceId)).lifecycleState ?? '') !== 'issued') {
			const issued = await fx.transition(invoiceId, 'issue')
			expect(issued.ok(), `issue: HTTP ${issued.status()} ${await issued.text()}`).toBeTruthy()
		}
		expect((await fx.get('ARInvoice', invoiceId)).lifecycleState).toBe('issued')

		// The resident's portal account, whose shillinq claim names the customer
		// (PortalSubjectResolver reads portaliq's portalAccount).
		await createForeign('portaliq', 'portalAccount', {
			subjectRef: PORTAL_SUBJECT,
			audience: 'customer',
			claims: { shillinq: { customerMasterId: CUSTOMER } },
		})

		// 7. portaliq forwards Pay now server to server.
		const portal = await playwright.request.newContext({ baseURL: resolveBaseURL() })
		const initiated = await portal.post(`${API}/portal/payments/initiate`, {
			headers: { 'Content-Type': 'application/json', 'X-Portal-Subject': portalAssertion(PORTALIQ_SECRET, PORTAL_SUBJECT) },
			data: { invoiceId },
		})
		const initiatedText = await initiated.text()
		await portal.dispose()
		expect(initiated.status(), initiatedText).toBe(200)
		const checkoutUrl = String((JSON.parse(initiatedText) as { checkoutUrl?: string }).checkoutUrl ?? '')

		const [request] = await list('PaymentRequest', { invoiceReference: invoiceId })
		expect(request, 'the pay flow minted a request for the invoice').toBeTruthy()
		written.push({ schema: 'PaymentRequest', id: idOf(request) })
		expect(money(Number(request.amount))).toBe(312)
		expect(request.paymentLink, 'the checkout the portal opens is the one stored').toBe(checkoutUrl)
		const providerPaymentId = expectCheckout(request, 'portal payment id')

		// integriq reports it captured: the request and the invoice settle.
		const eventId = await paymentStatus(providerPaymentId, 'captured')
		test.info().annotations.push({ type: 'portal status event', description: eventId })
		await expect.poll(async () => (await fx.get('PaymentRequest', idOf(request))).state, { timeout: 30_000 }).toBe('captured')
		await expect.poll(async () => (await fx.get('ARInvoice', invoiceId)).lifecycleState, { timeout: 30_000 }).toBe('paid')
		test.info().annotations.push({ type: 'portal end state', description: 'request captured, invoice paid' })

		await page.goto(`/index.php${APP}/bookkeeping/accounts-receivable/${encodeURIComponent(invoiceId)}`)
		await dismissOverlays(page)
		await expect(page.getByText(/\bpaid\b/i).first()).toBeVisible({ timeout: 20_000 })
	})

	test('an invoice pay link reaches a checkout and ends paid @spec REQ-RPL-004 @spec REQ-RPL-006', async () => {
		// The signed pay URL (PayLinkService::forOpenInvoice) is printed for an
		// ARInvoice only in the invoice mail of InvoiceSendingService: the PDF the
		// app serves (GET /api/v1/invoices/{id}/pdf) is a BillableInvoice, which
		// has no lifecycleState, and the hybrid PDF drops the HTML that holds the
		// link. The token is HMAC-signed with the instance secret, so a test can
		// neither read it from a route nor make one.
		test.fixme(true, 'the signed pay link of an ARInvoice reaches a person only in the invoice mail; no route serves it')
	})

	test('paying in Mollie\'s test checkout reaches integriq\'s webhook and ends paid @spec REQ-RPL-003', async () => {
		// Choosing "Paid" on Mollie's test-mode checkout is Mollie's network, and
		// Mollie then calls integriq's public webhook, which a CI instance does
		// not expose. The tests above save integriq's status CloudEvent instead.
		test.fixme(true, 'Mollie\'s hosted test checkout and its webhook to integriq are the provider\'s real network')
	})
})
