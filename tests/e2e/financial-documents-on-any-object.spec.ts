/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Financial documents on any object, end to end over HTTP.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * FinancialDocumentLeafProviderTest and FinancialDocumentActionControllerTest
 * cover the row shape, the administration check and the refusals against
 * doubles. What they cannot see is whether the leaf and the panel are on
 * OpenRegister's catalogue, whether the six action routes are registered at
 * all (a controller with no route answers the SPA shell, not JSON), and
 * whether an invoice issued on an object comes back from the leaf read for
 * that object, and for no other.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the payment: the debtor paying through the link needs a payment
 * provider and a signed webhook, covered in PaymentReconciliationServiceTest.
 * Not the panel's kinds filter: that is the vitest financialDocumentsPanel
 * spec, since the panel renders inside a host app's page, not shillinq's.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md
 * @e2e financial-documents-on-any-object/requirement-one-leaf-lists-every-financial-document-on-an-object-req-fdo-002/a-case-with-an-invoice-and-a-payout
 * @e2e financial-documents-on-any-object/requirement-an-invoice-on-an-object-is-payable-through-shillinq-req-fdo-004/a-citizen-pays-the-invoice-on-their-case
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const LEAF_ID = 'shillinq-financial-documents'
const PANEL_ID = 'shillinq-financial-documents-panel'
const CATALOGUE = '/index.php/apps/openregister/api/integrations'
const ACTIONS = '/index.php/apps/shillinq/api/financial-documents'
const HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * Host object ids unique to this run, so a row left by an earlier run cannot
 * make a read look like a working one.
 */
const RUN = `${Date.now()}-${Math.floor(Math.random() * 10_000)}`
const HOST = { register: 'dossiq', schema: 'Zaak', objectId: `zaak-e2e-${RUN}` }
const OTHER_HOST = `zaak-e2e-other-${RUN}`

/**
 * The rows of a leaf read, whatever envelope OpenRegister puts around them.
 *
 * @param body The decoded answer.
 * @return The rows.
 */
function rowsOf(body: Record<string, unknown>): Array<Record<string, unknown>> {
	const data = (body.data ?? body) as Record<string, unknown>
	return (data.items ?? data.results ?? []) as Array<Record<string, unknown>>
}

test.describe('financial-documents-on-any-object — the leaf', () => {
	test("the leaf and the panel are on OpenRegister's catalogue", async ({ request }) => {
		const response = await request.get(CATALOGUE, { headers: HEADERS })
		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const entries = (body.items ?? body.results ?? body ?? []) as Array<Record<string, unknown>>
		const ids = entries.map((entry) => String(entry.id ?? ''))

		expect(ids).toContain(LEAF_ID)
		expect(ids).toContain(PANEL_ID)
	})

	test('an invoice issued on a case comes back from the leaf for that case only', async ({ request }) => {
		const issued = await request.post(`${ACTIONS}/invoices`, {
			headers: HEADERS,
			data: {
				...HOST,
				objectType: 'case',
				amount: 120,
				currency: 'EUR',
				description: 'Leges omgevingsvergunning (e2e)',
				debtor: { name: 'E2E Debtor', email: 'e2e-debtor@example.invalid' },
			},
		})
		// A fresh install without an administration for the admin refuses with
		// a reason; that is a set-up gap of the instance, not of this change.
		test.skip(issued.status() === 403 || issued.status() === 409, `the instance refused to issue: ${await issued.text()}`)
		expect(issued.ok(), await issued.text()).toBeTruthy()

		const onCase = await request.get(`${CATALOGUE}/${LEAF_ID}/${HOST.register}/${HOST.schema}/${HOST.objectId}`, { headers: HEADERS })
		expect(onCase.ok(), await onCase.text()).toBeTruthy()
		const rows = rowsOf(await onCase.json())
		const invoices = rows.filter((row) => row.kind === 'invoice')
		expect(invoices).toHaveLength(1)
		expect(Number(invoices[0].amount)).toBe(120)
		for (const key of ['number', 'status', 'currency', 'issuedOn', 'openAmount', 'paymentState']) {
			expect(invoices[0], `row carries ${key}`).toHaveProperty(key)
		}

		const elsewhere = await request.get(`${CATALOGUE}/${LEAF_ID}/${HOST.register}/${HOST.schema}/${OTHER_HOST}`, { headers: HEADERS })
		expect(elsewhere.ok()).toBeTruthy()
		expect(rowsOf(await elsewhere.json())).toHaveLength(0)
	})
})

test.describe('financial-documents-on-any-object — the panel actions', () => {
	const routes = [
		`${ACTIONS}/invoices`,
		`${ACTIONS}/invoices/fd-e2e-absent/payment-request`,
		`${ACTIONS}/invoices/fd-e2e-absent/take-payment`,
		`${ACTIONS}/quotes`,
		`${ACTIONS}/invoices/fd-e2e-absent/credit`,
		`${ACTIONS}/payouts`,
	]

	test('every action route is registered and answers JSON, not the SPA shell', async ({ request }) => {
		for (const url of routes) {
			// An empty body: a registered route refuses it as JSON; an
			// unregistered path is served the SPA shell as text/html.
			const response = await request.post(url, { headers: HEADERS, data: {} })
			expect(response.headers()['content-type'] ?? '', `${url} did not answer JSON`).toContain('application/json')
			expect(response.status(), `${url} accepted an empty request`).toBeGreaterThanOrEqual(400)
		}
	})

	test('an unauthenticated caller cannot act', async ({ playwright }) => {
		const anonymous = await playwright.request.newContext({ baseURL: resolveBaseURL() })
		for (const url of routes) {
			const response = await anonymous.post(url, { headers: HEADERS, data: { ...HOST, amount: 120 } })
			expect([200, 201], `${url} answered an anonymous caller`).not.toContain(response.status())
		}
		await anonymous.dispose()
	})
})
