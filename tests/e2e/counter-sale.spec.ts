/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Counter sale page and its routes, end to end (receivables-provider-payouts
 * REQ-RPPO-006, design D6), at a phone's 390 px viewport.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * CounterSaleServiceTest and CounterSaleSettlementTest cover the draft, the
 * debtor, the issue on capture and the cancel against doubles. What they
 * cannot see is whether the page is reachable from the Sales menu, fits a
 * phone without sideways scrolling, totals what the assistant types, and
 * whether both routes are registered (an unrouted path is served the SPA
 * shell as 200 text/html, which no unit test notices).
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the payment on a real phone. Pushing a payment needs a registered
 * PaymentDevice and a payment provider connected through integriq; on a stack
 * without them the server refuses with 409 or 503 and the sale test skips with
 * that reason instead of passing on a payment that never started.
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-provider-payouts/specs/ar-invoice-payment-links/spec.md
 * @e2e ar-invoice-payment-links/requirement-a-counter-sale-is-a-simplified-invoice-issued-on-capture-req-rppo-006/a-market-stall-sells-a-tea-tin
 * @e2e ar-invoice-payment-links/requirement-a-counter-sale-is-a-simplified-invoice-issued-on-capture-req-rppo-006/the-customer-walks-away
 */

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const APP = '/index.php/apps/shillinq'
const SALES = APP + '/api/financial-documents/counter-sales'
const HEADERS = { 'OCS-APIRequest': 'true' }
const PHONE = { width: 390, height: 844 }

test.describe('receivables-provider-payouts — counter sale', () => {
	test.use({ viewport: PHONE })

	test('the page fits a phone and totals the tea tin', async ({ page }) => {
		await page.goto(APP + '/sales/counter-sale')

		const form = page.getByTestId('counter-sale-page')
		await expect(form).toBeVisible()
		await page.getByTestId('counter-sale-description-0').fill('Theeblik groot')
		await page.getByTestId('counter-sale-price-0').fill('12.50')
		await page.getByTestId('counter-sale-vat-0').selectOption('21')

		await expect(page.getByTestId('counter-sale-total')).toContainText(/12[.,]50/)

		const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
		expect(overflow, 'the page scrolls sideways on a phone').toBeLessThanOrEqual(0)
	})

	test('both routes are registered and refuse a sale without lines', async ({ request }) => {
		const missing = await request.post(SALES, { headers: HEADERS, data: { administrationId: 'e2e-none' } })
		expect(missing.headers()['content-type'] ?? '').toContain('application/json')
		expect(missing.status()).toBe(400)

		const cancel = await request.post(`${SALES}/inv-e2e-${Date.now()}/cancel`, { headers: HEADERS })
		expect(cancel.headers()['content-type'] ?? '').toContain('application/json')
		expect([403, 404, 409]).toContain(cancel.status())
	})

	test('a caller with no account cannot record a sale', async ({ playwright }) => {
		const anonymous = await playwright.request.newContext({ baseURL: resolveBaseURL(), storageState: { cookies: [], origins: [] } })

		const response = await anonymous.post(SALES, {
			headers: HEADERS,
			data: { administrationId: 'e2e-none', lines: [{ description: 'Theeblik groot', unitPrice: 12.5, vatRate: 21 }] },
		})

		expect(response.status()).not.toBe(201)
		await anonymous.dispose()
	})

	test('the tea tin becomes a draft simplified invoice, cancelled when the customer walks away', async ({ request }) => {
		const context = await request.get(APP + '/api/administrations/context', { headers: HEADERS })
		const administrationId = String((await context.json())?.activeAdministrationId ?? '')
		test.skip(administrationId === '', 'The e2e user works in no administration on this stack.')

		const sale = await request.post(SALES, {
			headers: HEADERS,
			data: { administrationId, lines: [{ description: 'Theeblik groot', quantity: 1, unitPrice: 12.5, vatRate: 21 }] },
		})
		const body = await sale.json()
		test.skip(
			[409, 503].includes(sale.status()),
			`The payment cannot start on this stack: ${String(body?.error ?? sale.status())}`,
		)

		expect(sale.status()).toBe(201)
		expect(body.invoice).toMatchObject({ lifecycleState: 'draft', simplifiedInvoice: true, counterSale: true, grossAmount: 12.5 })
		expect(['device', 'qr']).toContain(body.mode)

		const cancelled = await request.post(`${SALES}/${encodeURIComponent(body.invoice.id)}/cancel`, { headers: HEADERS })
		expect(cancelled.status()).toBe(200)
		expect((await cancelled.json()).invoice.lifecycleState).toBe('cancelled')
	})
})
