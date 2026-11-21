/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: an accepted quote becomes a sales order, the order is
 * confirmed and invoiced in two parts (sales-quote-and-order-to-invoice
 * task 3.2, REQ-QTI-001, REQ-QTI-002, REQ-QTI-003).
 *
 *   1. a salesperson orders an accepted quote: "Create sales order" on the
 *      quote page opens a draft sales order with the quote's customer, both
 *      lines at the same quantities and prices, and the quote as its source;
 *      a second order from the same quote is refused (REQ-QTI-001);
 *   2. a quote that is still being negotiated is refused: a quote in state
 *      sent gets no sales order, and the refusal says it must be accepted
 *      first (REQ-QTI-001);
 *   3. a user confirms a sales order: the order moves from draft to
 *      confirmed and the Orders page filtered to Sales lists it
 *      (REQ-QTI-002); the Confirm button on the order page is not built yet;
 *   4. a bookkeeper invoices an order in two parts: with 4 of the 10 oven
 *      trays already invoiced, "Invoice order" opens a draft sales invoice
 *      with 6 trays and the installation at the order prices, and the order
 *      is invoiced (REQ-QTI-003);
 *   5. a fully invoiced order is refused: a second "Invoice order" says every
 *      line is already invoiced (REQ-QTI-003).
 *
 * The spec writes: it creates its own quotes (named after the scenario's
 * Q-2026-031 with a run-unique suffix) in the caller's active
 * administration, and deletes every quote, quote line, order, order line
 * and draft invoice it made in afterAll. The caller is the suite's
 * administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 * @spec openspec/changes/sales-quote-and-order-to-invoice/tasks.md#task-3.2
 *
 * @e2e sales-quote-order-invoice::a-salesperson-orders-an-accepted-quote
 * @e2e sales-quote-order-invoice::a-quote-that-is-still-being-negotiated-is-refused
 * @e2e sales-quote-order-invoice::a-user-confirms-a-sales-order
 * @e2e sales-quote-order-invoice::a-bookkeeper-invoices-an-order-in-two-parts
 * @e2e sales-quote-order-invoice::a-fully-invoiced-order-is-refused
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const RUN = `${Date.now().toString(36)}`
const QUOTE_NUMBER = `Q-2026-031-E2E-${RUN}`
const CUSTOMER = 'Bakkerij De Korenaar'

/** An OpenRegister object as the list and item endpoints answer it. */
interface RegisterObject {
	id?: string
	'@self'?: { id?: string }
	[field: string]: unknown
}

/** One line of the draft ARInvoice the invoice endpoint writes. */
interface InvoiceLine {
	quantity: number
	itemName: string
	netPrice: number
}

/** What the spec made, for afterAll. */
const made: Array<{ schema: string, id: string }> = []

/** The id of a register object, wherever OpenRegister put it. */
function idOf(object: RegisterObject): string {
	return String(object.id ?? object['@self']?.id ?? '')
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Run a detail page's header action: a button when it stands alone, else an entry of its Actions menu. */
async function runHeaderAction(page: Page, name: RegExp): Promise<void> {
	const button = page.getByRole('button', { name }).first()
	if (await button.isVisible().catch(() => false)) {
		await button.click()
		return
	}
	await page.getByTestId('cn-detail-page').getByRole('button', { name: /^(Actions|Acties)$/i }).first().click()
	await page.getByRole('menuitem', { name }).first().click()
}

/** The caller's active administration, or '' when there is none. */
async function activeAdministration(request: APIRequestContext): Promise<string> {
	const context = await request.get(`${API}/administrations/context`, { headers: HEADERS })
	if (!context.ok()) {
		return ''
	}
	return String((await context.json() as { activeAdministrationId?: string | null }).activeAdministrationId ?? '')
}

/** Create one register object and remember it for afterAll. */
async function create(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await request.post(`${OBJECTS}/${schema}`, { headers: HEADERS, data })
	expect(res.ok(), `create ${schema}: ${res.status()} ${await res.text()}`).toBe(true)
	const id = idOf(await res.json() as RegisterObject)
	expect(id, `no id on the created ${schema}`).not.toBe('')
	made.push({ schema, id })
	return id
}

/** Read one register object. */
async function read(request: APIRequestContext, schema: string, id: string): Promise<RegisterObject> {
	const res = await request.get(`${OBJECTS}/${schema}/${id}`, { headers: HEADERS })
	expect(res.ok(), `read ${schema}/${id}: ${res.status()}`).toBe(true)
	return await res.json() as RegisterObject
}

/** List register objects by one field. */
async function list(request: APIRequestContext, schema: string, field: string, value: string): Promise<RegisterObject[]> {
	const res = await request.get(`${OBJECTS}/${schema}?${field}=${encodeURIComponent(value)}&_limit=100`, { headers: HEADERS })
	expect(res.ok(), `list ${schema}: ${res.status()}`).toBe(true)
	const body = await res.json() as { results?: RegisterObject[] }
	return body.results ?? []
}

/** A quote with the scenario's two lines: 10 oven trays at EUR 45 and 1 installation at EUR 300. */
async function quoteWithLines(request: APIRequestContext, administrationId: string, quoteNumber: string, status: string): Promise<string> {
	const quoteId = await create(request, 'Quote', {
		quoteNumber,
		customerReference: CUSTOMER,
		currency: 'EUR',
		administrationId,
		status,
		...(status === 'accepted' ? { acceptedAt: new Date().toISOString(), acceptanceChannel: 'manual-backoffice' } : {}),
	})
	await create(request, 'QuoteLine', { quote: quoteId, lineNumber: 1, description: 'Ovenplaat', quantity: 10, unitPrice: 45, vatRate: 21 })
	await create(request, 'QuoteLine', { quote: quoteId, lineNumber: 2, description: 'Installatie', quantity: 1, unitPrice: 300, vatRate: 21 })
	return quoteId
}

test.describe.serial('sales-quote-and-order-to-invoice: quote to order to invoice', () => {
	let administrationId = ''
	let acceptedQuoteId = ''
	let orderId = ''
	let orderNumber = ''

	test.beforeAll(async ({ request }) => {
		administrationId = await activeAdministration(request)
	})

	test.afterAll(async ({ request }) => {
		// Invoices and order lines first, then orders, then quote lines and quotes.
		if (orderId !== '') {
			for (const invoice of await list(request, 'ARInvoice', 'salesOrder', orderId).catch(() => [])) {
				made.push({ schema: 'ARInvoice', id: idOf(invoice) })
			}
			for (const line of await list(request, 'OrderLine', 'orderId', orderId).catch(() => [])) {
				made.push({ schema: 'OrderLine', id: idOf(line) })
			}
			made.push({ schema: 'OrderPrimitive', id: orderId })
		}
		const order = ['ARInvoice', 'OrderLine', 'OrderPrimitive', 'QuoteLine', 'Quote']
		made.sort((left, right) => order.indexOf(left.schema) - order.indexOf(right.schema))
		for (const { schema, id } of made) {
			await request.delete(`${OBJECTS}/${schema}/${id}`, { headers: HEADERS }).catch(() => {})
		}
	})

	test('"Create sales order" on an accepted quote opens a draft sales order with the quote\'s lines @spec REQ-QTI-001', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		acceptedQuoteId = await quoteWithLines(request, administrationId, QUOTE_NUMBER, 'accepted')

		await page.goto(`${APP}/quotes/${acceptedQuoteId}`)
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
		await runHeaderAction(page, /^(Create sales order|Verkooporder maken)$/i)
		await expect(page, 'the written order opens').toHaveURL(/\/orders\/[0-9a-f-]{36}/, { timeout: 20_000 })

		orderId = page.url().split('/').pop() ?? ''
		const order = await read(request, 'OrderPrimitive', orderId)
		orderNumber = String(order.orderNumber ?? '')
		expect(order.orderType).toBe('sales')
		expect(order.state).toBe('draft')
		expect(order.counterpartyId, 'the same customer').toBe(CUSTOMER)
		expect(order.sourceQuote, 'the order names its source quote').toBe(acceptedQuoteId)
		expect(orderNumber).toBe(`SO-${QUOTE_NUMBER}`)

		const lines = (await list(request, 'OrderLine', 'orderId', orderId))
			.map((line) => ({ description: line.description, quantity: Number(line.quantity), unitPrice: Number(line.unitPrice) }))
			.sort((left, right) => String(left.description).localeCompare(String(right.description)))
		expect(lines).toEqual([
			{ description: 'Installatie', quantity: 1, unitPrice: 300 },
			{ description: 'Ovenplaat', quantity: 10, unitPrice: 45 },
		])
		await expect(page.getByText(/^(Order lines|Orderregels)$/i).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('Ovenplaat').first()).toBeVisible({ timeout: 20_000 })

		// The same quote gives no second order.
		const again = await request.post(`${API}/quotes/${acceptedQuoteId}/order`, { headers: HEADERS })
		expect(again.status(), await again.text()).toBe(422)
		expect(String((await again.json() as { message?: string }).message)).toContain(orderNumber)
	})

	test('a quote in state sent gets no sales order @spec REQ-QTI-001', async ({ request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const sentQuoteId = await quoteWithLines(request, administrationId, `${QUOTE_NUMBER}-SENT`, 'sent')

		const res = await request.post(`${API}/quotes/${sentQuoteId}/order`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(422)
		const body = await res.json() as { message?: string }
		expect(String(body.message)).toMatch(/must be accepted|moet geaccepteerd zijn/i)
		expect(await list(request, 'OrderPrimitive', 'sourceQuote', sentQuoteId), 'nothing was written').toHaveLength(0)
	})

	test('a confirmed sales order is listed under the Sales filter of the Orders page @spec REQ-QTI-002', async ({ page, request }) => {
		test.skip(orderId === '', 'no sales order was made from the accepted quote')

		// The order page has no Confirm button yet (OrderDetail declares no
		// lifecycleActions), so the confirmSales transition runs through the
		// lifecycle endpoint the button would call.
		const res = await request.post(`/index.php/apps/openregister/api/objects/${orderId}/transition`, { headers: HEADERS, data: { action: 'confirmSales' } })
		expect(res.ok(), await res.text()).toBe(true)
		expect((await read(request, 'OrderPrimitive', orderId)).state).toBe('confirmed')

		await page.goto(`${APP}/orders/${orderId}`)
		await dismissOverlays(page)
		await expect(page.getByText(/^(confirmed|Confirmed|Bevestigd)$/).first(), 'the order shows state confirmed').toBeVisible({ timeout: 20_000 })

		await page.goto(`${APP}/orders`)
		await dismissOverlays(page)
		await page.getByText(/^(Sales|Verkoop)$/).first().click()
		await expect(page.getByText(orderNumber).first(), 'the Sales filter lists the order').toBeVisible({ timeout: 20_000 })
	})

	// Missing: OrderDetail in src/manifest.d/order-workspace.json declares no lifecycleActions, so there is no Confirm button on the order page.
	test.fixme('the user confirms a draft sales order with the Confirm button on the order page @spec REQ-QTI-002', async () => {})

	test('"Invoice order" with 4 of 10 trays invoiced writes a draft invoice for the remaining 6 @spec REQ-QTI-003', async ({ page, request }) => {
		test.skip(orderId === '', 'no confirmed sales order was made')
		const trays = (await list(request, 'OrderLine', 'orderId', orderId)).find((line) => line.description === 'Ovenplaat')
		expect(trays, 'the oven tray line').toBeDefined()
		const patched = await request.put(`${OBJECTS}/OrderLine/${idOf(trays!)}`, {
			headers: HEADERS,
			data: { ...trays, invoicedQuantity: 4 },
		})
		expect(patched.ok(), await patched.text()).toBe(true)

		await page.goto(`${APP}/orders/${orderId}`)
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
		await runHeaderAction(page, /^(Invoice order|Order factureren)$/i)
		await expect(page, 'the draft invoice opens').toHaveURL(/\/bookkeeping\/accounts-receivable\/[0-9a-f-]{36}/, { timeout: 20_000 })

		const invoiceId = page.url().split('/').pop() ?? ''
		const invoice = await read(request, 'ARInvoice', invoiceId)
		expect(invoice.lifecycleState).toBe('draft')
		expect(invoice.salesOrder).toBe(orderId)
		expect(invoice.customerId).toBe(CUSTOMER)
		const lines = (invoice.invoiceLines as InvoiceLine[])
			.map((line) => ({ itemName: line.itemName, quantity: Number(line.quantity), netPrice: Number(line.netPrice) }))
			.sort((left, right) => left.itemName.localeCompare(right.itemName))
		expect(lines).toEqual([
			{ itemName: 'Installatie', quantity: 1, netPrice: 300 },
			{ itemName: 'Ovenplaat', quantity: 6, netPrice: 45 },
		])

		expect((await read(request, 'OrderPrimitive', orderId)).state, 'the order shows state invoiced').toBe('invoiced')
		for (const line of await list(request, 'OrderLine', 'orderId', orderId)) {
			expect(Number(line.invoicedQuantity), `${String(line.description)} is invoiced in full`).toBe(Number(line.quantity))
		}
	})

	test('"Invoice order" on an invoiced order is refused @spec REQ-QTI-003', async ({ request }) => {
		test.skip(orderId === '', 'no sales order was made')
		const order = await read(request, 'OrderPrimitive', orderId)
		test.skip(order.state !== 'invoiced', 'the order was not invoiced by the previous test')

		const res = await request.post(`${API}/orders/${orderId}/invoice`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(422)
		const body = await res.json() as { message?: string }
		expect(String(body.message)).toMatch(/already invoiced|al gefactureerd/i)
		expect(await list(request, 'ARInvoice', 'salesOrder', orderId), 'no second invoice').toHaveLength(1)
	})
})
