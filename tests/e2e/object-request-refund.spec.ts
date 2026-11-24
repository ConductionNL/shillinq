/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: refunds and credit on a request another app raised
 * (receivables-object-request-refund-and-credit task 3.2, REQ-ORC-001 to
 * REQ-ORC-004).
 *
 *   1. asked by the owning app: every request in refund_requested stands on
 *      an object, is settled, and its newest refund was asked for by the app
 *      that owns the object (REQ-ORC-001);
 *   2. refunds to pay: the Refunds to pay entry opens the payment requests
 *      list on refund_requested, and the two refund steps answer JSON and are
 *      refused to an anonymous caller (REQ-ORC-002);
 *   3. a finance user pays a refund and the request reads refunded: Mark paid
 *      on a refund someone else approved, with a bank reference and a bank
 *      account, moves the request to refunded (REQ-ORC-002);
 *   4. credit: every credited request has one DebtorCredit for its amount,
 *      keyed on the customer record or the lower-cased email (REQ-ORC-003);
 *   5. credit pays first: a request settled by credit holds a credit
 *      settlement, and every DebtorCredit's remaining amount lies between zero
 *      and its amount, used exactly at zero (REQ-ORC-004).
 *
 * The two events are dispatched in-process by another app; no HTTP route
 * raises them, so the refusal of a foreign app leaves nothing to read and is
 * a fixme here (PaymentRefundRequestedListenerTest covers it). Test 3 writes:
 * it books the refund payment, which a posted entry keeps, so it runs only on
 * a refund another user already approved (four eyes: the approver may not
 * mark it paid) and skips otherwise. Everything else reads. The caller is the
 * suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-refunds-and-credit/spec.md
 * @spec openspec/changes/receivables-object-request-refund-and-credit/tasks.md#task-3.2
 *
 * @e2e object-payment-refunds-and-credit::larpinq-asks-for-a-refund-of-mila-s-paid-registration
 * @e2e object-payment-refunds-and-credit::another-app-cannot-refund-larpinq-s-request
 * @e2e object-payment-refunds-and-credit::a-treasurer-pays-mila-s-refund
 * @e2e object-payment-refunds-and-credit::joris-keeps-mila-s-payment-as-credit
 * @e2e object-payment-refunds-and-credit::joris-s-credit-pays-the-next-event
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One refund on a request. */
interface Refund {
	amount: number
	reason?: string | null
	requestedBy?: string
	requestedAt?: string
	state: 'requested' | 'approved' | 'paid'
	approvedBy?: string
	bankReference?: string
	paidBy?: string
}

/** One settlement on a request. */
interface Settlement {
	method: string
	amount: number
	reference?: string
}

/** The part of a PaymentRequest this spec reads. */
interface PaymentRequest {
	id?: string
	state: string
	subjectKind?: 'invoice' | 'object'
	subject?: { app?: string, register?: string, schema?: string, id?: string }
	requestedBy?: string
	debtor?: { customerMasterId?: string, name?: string, email?: string }
	customerId?: string
	amount: number
	administrationId?: string
	settledAt?: string | null
	settledVia?: string | null
	settlements?: Settlement[]
	refunds?: Refund[]
	'@self'?: { id?: string }
}

/** A DebtorCredit. */
interface DebtorCredit {
	debtorKey: string
	amount: number
	remaining: number
	sourcePaymentRequestId: string
	administrationId?: string
	state: 'open' | 'used'
}

/**
 * The id OpenRegister answers an object under.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: { id?: string, '@self'?: { id?: string } }): string {
	return String(object.id ?? object['@self']?.id ?? '')
}

/**
 * Every object of a schema that matches the filters, page by page.
 *
 * @param request The request context.
 * @param schema  The schema slug.
 * @param filters Field filters.
 * @return The objects.
 */
async function listAll<T>(request: APIRequestContext, schema: string, filters: Record<string, string> = {}): Promise<T[]> {
	const all: T[] = []
	for (let page = 1; page <= 20; page++) {
		const query = new URLSearchParams({ ...filters, _limit: '200', _page: String(page) })
		const res = await request.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), `list ${schema}: ${await res.text()}`).toBe(true)
		const rows = ((await res.json()) as { results?: T[] }).results ?? []
		all.push(...rows)
		if (rows.length < 200) {
			break
		}
	}
	return all
}

/**
 * The signed-in user's id.
 *
 * @param request The request context.
 * @return The uid.
 */
async function currentUser(request: APIRequestContext): Promise<string> {
	const res = await request.get('/ocs/v2.php/cloud/user?format=json', { headers: HEADERS })
	expect(res.ok()).toBe(true)
	return ((await res.json()) as { ocs: { data: { id: string } } }).ocs.data.id
}

/**
 * The newest refund of a request, the one finance acts on.
 *
 * @param request The payment request.
 * @return The refund, or undefined.
 */
function newestRefund(request: PaymentRequest): Refund | undefined {
	const refunds = request.refunds ?? []
	return refunds[refunds.length - 1]
}

/**
 * The key credit is kept under: the customer record, else the lower-cased email.
 *
 * @param request The payment request.
 * @return The key, or '' when the debtor cannot be recognised.
 */
function debtorKey(request: PaymentRequest): string {
	const customer = String(request.debtor?.customerMasterId ?? request.customerId ?? '').trim()
	if (customer !== '') {
		return customer
	}
	return String(request.debtor?.email ?? '').trim().toLowerCase()
}

/**
 * Compare a euro amount to the cent.
 *
 * @param value The amount.
 * @return The cents.
 */
function cents(value: number): number {
	return Math.round(Number(value) * 100)
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('receivables-object-request-refund-and-credit: refunds', () => {
	test('a refund is only asked for by the app that owns the settled object @spec REQ-ORC-001', async ({ request }) => {
		const asked = await listAll<PaymentRequest>(request, 'PaymentRequest', { state: 'refund_requested' })
		test.skip(asked.length === 0, 'no payment request is waiting for a refund')

		for (const paymentRequest of asked) {
			const label = idOf(paymentRequest)
			const refund = newestRefund(paymentRequest)
			expect(paymentRequest.subjectKind, `${label} stands on an object`).toBe('object')
			expect(paymentRequest.settledAt, `${label} was settled first`).toBeTruthy()
			expect(refund, `${label} carries the refund`).toBeDefined()
			expect(cents(refund!.amount), `${label}: the full settled amount goes back`).toBe(cents(paymentRequest.amount))
			const owner = String(paymentRequest.subject?.app ?? paymentRequest.requestedBy?.replace(/^app:/, '') ?? '')
			if (owner !== '') {
				expect(refund!.requestedBy, `${label}: asked by the owning app`).toBe(owner)
			}
		}
	})

	test.fixme('an app that does not own the object is refused @spec REQ-ORC-001', async () => {
		// Missing: no HTTP route dispatches PaymentRefundRequestedEvent; the refusal changes nothing a browser can read.
	})

	test('Refunds to pay opens the payment requests waiting for a refund @spec REQ-ORC-002', async ({ page, request }) => {
		await page.goto(APP + '/bookkeeping/payment-requests')
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		await page.getByRole('link', { name: /^(Refunds to pay|Terug te betalen)$/i }).first().click({ timeout: 20_000 })
		await expect(page).toHaveURL(/\/bookkeeping\/payment-requests.*refund_requested/)

		const asked = await listAll<PaymentRequest>(request, 'PaymentRequest', { state: 'refund_requested' })
		test.skip(asked.length === 0, 'no payment request is waiting for a refund, so the list is empty')
		const rows = page.locator('table tbody tr')
		await expect(rows.first()).toBeVisible({ timeout: 20_000 })
		expect(await rows.count(), 'only requests waiting for a refund are listed').toBeLessThanOrEqual(asked.length)
		for (const row of await rows.all()) {
			await expect(row).toContainText(/refund_requested|Refund requested|Terugbetaling/i)
		}
	})

	test('the refund steps answer JSON and refuse an anonymous caller @spec REQ-ORC-002', async ({ request, playwright }) => {
		const absent = `pr-e2e-${Date.now()}`
		for (const step of ['approve', 'paid']) {
			const res = await request.post(`${API}/payment-requests/${absent}/refund/${step}`, { headers: HEADERS, data: { bankReference: 'E2E', bankAccount: '1100' } })
			expect(res.headers()['content-type'] ?? '', `${step} is routed`).toContain('application/json')
			expect(res.ok(), `${step} on an absent request`).toBe(false)
		}

		const anonymous = await playwright.request.newContext({ baseURL: resolveBaseURL() })
		const refused = await anonymous.post(`${API}/payment-requests/${absent}/refund/approve`, { headers: HEADERS })
		expect(refused.status()).not.toBe(200)
		await anonymous.dispose()
	})

	test('a finance user pays a refund and the request reads refunded @spec REQ-ORC-002', async ({ page, request }) => {
		const user = await currentUser(request)
		const asked = await listAll<PaymentRequest>(request, 'PaymentRequest', { state: 'refund_requested' })
		const target = asked.find((candidate) => newestRefund(candidate)?.state === 'approved' && newestRefund(candidate)?.approvedBy !== user)
		test.skip(target === undefined, 'no refund is approved by another user, and the approver may not mark it paid')
		// The list shows no id, so the row is found by its amount: it must be the only one.
		test.skip(asked.filter((candidate) => cents(candidate.amount) === cents(target!.amount)).length > 1, 'two refunds share the amount, so the row cannot be told apart')
		const id = idOf(target!)
		const reference = `RF-E2E-${Date.now()}`

		await page.goto(`${APP}/bookkeeping/payment-requests?state=refund_requested`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		const row = page.getByRole('row').filter({ hasText: String(target!.amount).replace('.', ',') })
			.or(page.getByRole('row').filter({ hasText: String(target!.amount) }))
			.first()
		await row.getByRole('button', { name: /actions|acties/i }).click({ timeout: 20_000 })
		await page.getByRole('menuitem', { name: /^(Mark paid|Markeer betaald)$/i }).click()

		const dialog = page.locator('[data-testid="refund-paid-modal"]')
		await expect(dialog).toBeVisible()
		await dialog.getByLabel(/^(Bank reference|Bankreferentie)$/i).fill(reference)
		await dialog.locator('[data-testid="refund-paid-account"]').click()
		await page.getByRole('option').first().click({ timeout: 20_000 })
		await page.locator('[data-testid="refund-paid-submit"]').click()
		await expect(dialog).toBeHidden({ timeout: 20_000 })

		const stored = (await (await request.get(`${OBJECTS}/PaymentRequest/${encodeURIComponent(id)}`, { headers: HEADERS })).json()) as PaymentRequest
		expect(stored.state).toBe('refunded')
		const refund = newestRefund(stored)
		expect(refund?.state).toBe('paid')
		expect(refund?.bankReference).toBe(reference)
		expect(refund?.paidBy).toBe(user)
	})

	test('the approver of a refund may not also mark it paid @spec REQ-ORC-002', async ({ request }) => {
		const user = await currentUser(request)
		const asked = await listAll<PaymentRequest>(request, 'PaymentRequest', { state: 'refund_requested' })
		const own = asked.find((candidate) => newestRefund(candidate)?.state === 'approved' && newestRefund(candidate)?.approvedBy === user)
		test.skip(own === undefined, 'the caller approved no refund that waits for payment')

		const res = await request.post(`${API}/payment-requests/${encodeURIComponent(idOf(own!))}/refund/paid`, {
			headers: HEADERS,
			data: { bankReference: `RF-E2E-${Date.now()}`, bankAccount: '1100' },
		})
		expect(res.status(), await res.text()).toBe(403)
		const answer = (await (await request.get(`${OBJECTS}/PaymentRequest/${encodeURIComponent(idOf(own!))}`, { headers: HEADERS })).json()) as PaymentRequest
		expect(answer.state, 'the request is unchanged').toBe('refund_requested')
	})
})

test.describe('receivables-object-request-refund-and-credit: credit', () => {
	test('a credited request holds one open credit for its debtor @spec REQ-ORC-003', async ({ request }) => {
		const credited = await listAll<PaymentRequest>(request, 'PaymentRequest', { state: 'credited' })
		test.skip(credited.length === 0, 'no payment request was kept as credit')
		const credits = await listAll<DebtorCredit>(request, 'DebtorCredit')

		for (const paymentRequest of credited) {
			const label = idOf(paymentRequest)
			const mine = credits.filter((credit) => credit.sourcePaymentRequestId === label)
			expect(mine, `${label}: one credit`).toHaveLength(1)
			expect(cents(mine[0].amount), `${label}: the settled amount`).toBe(cents(paymentRequest.amount))
			expect(mine[0].debtorKey, `${label}: kept under the debtor`).toBe(debtorKey(paymentRequest))
			expect(mine[0].debtorKey).not.toBe('')
		}
	})

	test('open credit pays a new request first and keeps the rest @spec REQ-ORC-004', async ({ request }) => {
		const credits = await listAll<DebtorCredit>(request, 'DebtorCredit')
		test.skip(credits.length === 0, 'the instance holds no debtor credit')
		for (const credit of credits) {
			const label = `${credit.debtorKey} from ${credit.sourcePaymentRequestId}`
			expect(cents(credit.remaining), `${label}: never below zero`).toBeGreaterThanOrEqual(0)
			expect(cents(credit.remaining), `${label}: never above the amount`).toBeLessThanOrEqual(cents(credit.amount))
			expect(credit.state, `${label}: used exactly when nothing remains`).toBe(cents(credit.remaining) === 0 ? 'used' : 'open')
		}

		const paidByCredit = (await listAll<PaymentRequest>(request, 'PaymentRequest', { settledVia: 'credit' }))
		for (const paymentRequest of paidByCredit) {
			const label = idOf(paymentRequest)
			const fromCredit = (paymentRequest.settlements ?? []).filter((settlement) => settlement.method === 'credit')
			expect(fromCredit.length, `${label}: a credit settlement`).toBeGreaterThan(0)
			const total = fromCredit.reduce((sum, settlement) => sum + cents(settlement.amount), 0)
			expect(total, `${label}: credit covered it whole`).toBe(cents(paymentRequest.amount))
			expect(paymentRequest.settledAt, `${label}: stamped settled`).toBeTruthy()
		}
	})
})
