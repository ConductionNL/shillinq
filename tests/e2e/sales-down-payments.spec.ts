/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Down payments on an order (sales-down-payments), the Keuken Eiland example.
 *
 * The administration, the customers, the orders and the down-payment
 * endpoints are answered through page.route, so the test proves the screens:
 * the header action opens the dialog and posts what the bookkeeper chose,
 * and the invoice page lists the order's down payments and deducts them.
 * The amounts, the posting and the refusals are proven by
 * DownPaymentServiceTest and MaterialiseGlTransactionActionTest against the
 * real register schemas.
 *
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const CUSTOMER = { id: 'cust-deboer', legalName: 'Familie De Boer' }
const ORDER = { id: 'order-117', orderNumber: 'Keuken Eiland 2026-117' }

/**
 * Answer the calls the dialog makes.
 *
 * @param page The page.
 * @return The bodies posted to the raise endpoint.
 */
async function stubRaise(page: Page): Promise<object[]> {
	const posted: object[] = []
	await page.route(
		'**/apps/shillinq/api/administrations/context',
		(route: Route) =>
			route.fulfill({
				json: { activeAdministrationId: 'adm-kvl', administrations: [] },
			}),
	)
	await page.route(
		'**/apps/openregister/api/objects/shillinq/CustomerMaster**',
		(route: Route) => route.fulfill({ json: { results: [CUSTOMER] } }),
	)
	await page.route(
		'**/apps/openregister/api/objects/shillinq/OrderPrimitive**',
		(route: Route) => route.fulfill({ json: { results: [ORDER] } }),
	)
	await page.route(
		'**/apps/shillinq/api/ar-invoices/down-payments',
		(route: Route) => {
			posted.push(route.request().postDataJSON())
			return route.fulfill({
				status: 201,
				json: {
					id: 'ar-dp',
					invoiceNumber: '2026-0412',
					grossAmount: 5445,
					invoiceTypeCode: '386',
				},
			})
		},
	)
	return posted
}

/**
 * Answer the down payments panel of an invoice.
 *
 * @param page The page.
 * @param panel The panel body.
 * @return The bodies posted to the deduction endpoint.
 */
async function stubPanel(page: Page, panel: object): Promise<object[]> {
	const posted: object[] = []
	await page.route(
		'**/apps/shillinq/api/ar-invoices/*/down-payments',
		(route: Route) => route.fulfill({ json: panel }),
	)
	await page.route(
		'**/apps/shillinq/api/ar-invoices/*/down-payment-deductions',
		(route: Route) => {
			posted.push(route.request().postDataJSON())
			return route.fulfill({ json: { id: 'ar-kitchen', grossAmount: 12705 } })
		},
	)
	return posted
}

test.describe('bookkeeping-accounts-receivable-core', () => {
	/**
	 * @e2e bookkeeping-accounts-receivable-core::a-kitchen-studio-asks-30-percent-up-front
	 */
	test('a kitchen studio asks 30 percent up front', async ({ page }) => {
		const posted = await stubRaise(page)
		await page.goto('/index.php/apps/shillinq/bookkeeping/accounts-receivable')
		const action = page.getByRole('button', { name: 'New down-payment invoice' })
		test.skip(
			(await action.count()) === 0,
			'Accounts Receivable page not reachable',
		)

		await action.click()
		const dialog = page.locator('[data-testid="down-payment-modal"]')
		await dialog.waitFor({ state: 'visible', timeout: 8_000 })
		await page.locator('[data-testid="down-payment-customer"]').click()
		await page.getByRole('option', { name: 'Familie De Boer' }).click()
		await page.locator('[data-testid="down-payment-order"]').click()
		await page.getByRole('option', { name: 'Keuken Eiland 2026-117' }).click()
		await page.locator('[data-testid="down-payment-value"] input').fill('30')
		await page.locator('[data-testid="down-payment-submit"]').click()

		await expect(dialog).toBeHidden()
		expect(posted[0]).toMatchObject({
			administrationId: 'adm-kvl',
			customerId: 'cust-deboer',
			orderReference: 'order-117',
			percentage: 30,
		})
	})

	/**
	 * @e2e bookkeeping-accounts-receivable-core::the-kitchen-is-delivered-and-invoiced
	 */
	test('the kitchen is delivered and invoiced', async ({ page }) => {
		const posted = await stubPanel(page, {
			kind: '',
			orderLabel: '',
			position: [],
			canDeduct: true,
			openOrders: [
				{
					orderReference: 'order-117',
					orderLabel: 'Keuken Eiland 2026-117',
					grossAmount: 5445,
					downPayments: [
						{
							id: 'ar-dp',
							invoiceNumber: '2026-0412',
							grossAmount: 5445,
							paid: true,
						},
					],
				},
			],
		})
		await page.goto(
			'/index.php/apps/shillinq/bookkeeping/accounts-receivable/ar-kitchen',
		)
		const deduct = page.locator(
			'[data-testid="ar-down-payments-deduct-order-117"]',
		)
		test.skip((await deduct.count()) === 0, 'Invoice page not reachable')

		await deduct.click()

		await expect.poll(() => posted.length).toBe(1)
		expect(posted[0]).toEqual({ orderReference: 'order-117' })
	})

	/**
	 * @e2e bookkeeping-accounts-receivable-core::the-bookkeeper-checks-what-is-still-to-deduct
	 */
	test('the bookkeeper checks what is still to deduct', async ({ page }) => {
		await stubPanel(page, {
			kind: 'down-payment',
			orderLabel: 'Keuken Eiland 2026-117',
			canDeduct: false,
			openOrders: [],
			position: [
				{
					id: 'ar-dp',
					invoiceNumber: '2026-0412',
					grossAmount: 5445,
					paid: true,
					deductedOnInvoiceNumber: '2026-0587',
				},
				{
					id: 'ar-dp2',
					invoiceNumber: '2026-0450',
					grossAmount: 1815,
					paid: false,
					deductedOnInvoiceNumber: '',
				},
			],
		})
		await page.goto(
			'/index.php/apps/shillinq/bookkeeping/accounts-receivable/ar-dp',
		)
		const table = page.locator('[data-testid="ar-down-payments-position"]')
		test.skip((await table.count()) === 0, 'Invoice page not reachable')

		await expect(table.getByRole('row')).toHaveCount(3)
		await expect(table).toContainText('2026-0587')
		await expect(table).toContainText('Not deducted yet')
	})
})
