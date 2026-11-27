/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The quick invoice draft opened from a page other than the dashboard
 * (sales-quick-draft-from-any-page, REQ-IQD-009).
 *
 * The customers and the quick-draft endpoint are answered through
 * page.route, so the test proves the screens: Create invoice opens the
 * modal over the page it was chosen on, a customer page starts the draft
 * with that customer, and saving or cancelling leaves the page as it was.
 * The request the modal posts is proven by
 * tests/vitest/invoiceQuickDraft.spec.js and the choice of the starting
 * customer by tests/vitest/invoiceQuickDraftStartingCustomer.spec.js.
 *
 * On the purchase invoices and bank reconciliation pages Create invoice is
 * the sub-action of the navigation's New Booking button (task 1.1): the
 * chevron beside New Booking opens a menu with Create invoice.
 *
 * Base URL from the environment (playwright.config.ts, tests/e2e/base-url.ts).
 *
 * @spec openspec/changes/archive/2026-11-25-sales-quick-draft-from-any-page/specs/shillinq-invoice-quick-draft/spec.md
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/index.php/apps/shillinq'

const GEMEENTE = {
	id: 'cust-gemeente-voorbeeld',
	customerId: 'D1001',
	legalName: 'Gemeente Voorbeeld',
	paymentTermDays: 30,
	'@self': { id: 'cust-gemeente-voorbeeld' },
}
const KORENAAR = {
	id: 'cust-korenaar',
	customerId: 'D1002',
	legalName: 'Bakkerij De Korenaar B.V.',
	paymentTermDays: 14,
	'@self': { id: 'cust-korenaar' },
}

/**
 * Answer the customer calls of the page and the modal, and the quick-draft
 * endpoint.
 *
 * @param page The page.
 * @return The bodies posted to the quick-draft endpoint.
 */
async function stubCustomersAndDrafts(page: Page): Promise<object[]> {
	const posted: object[] = []
	await page.route(
		'**/apps/openregister/api/objects/shillinq/CustomerMaster**',
		(route: Route) => {
			const path = new URL(route.request().url()).pathname
			const id = decodeURIComponent(path.split('/CustomerMaster/')[1] ?? '').split('/')[0]
			if (id !== '') {
				const one = [GEMEENTE, KORENAAR].find((c) => c.id === id)
				return one
					? route.fulfill({ json: one })
					: route.fulfill({ status: 404, json: { message: 'Not found' } })
			}
			return route.fulfill({ json: { results: [GEMEENTE, KORENAAR], total: 2 } })
		},
	)
	await page.route(
		'**/apps/shillinq/api/ar-invoices/quick-drafts',
		(route: Route) => {
			posted.push(route.request().postDataJSON())
			return route.fulfill({
				status: 201,
				json: { id: 'ar-quick-1', invoiceNumber: 'CONCEPT-1', status: 'draft' },
			})
		},
	)
	return posted
}

/**
 * Choose the current page's own Create invoice action. A detail page holds
 * it in the header's More menu (CnDetailPage folds header actions past the
 * inline ones into it); a page that shows it inline gets a direct click.
 *
 * The navigation's Create invoice (beside New Booking) is a different action:
 * it opens the dialog without the page's customer, so it is excluded here.
 *
 * @param page The page.
 * @return Nothing.
 */
async function chooseCreateInvoiceOnPage(page: Page): Promise<void> {
	const nav = page.getByTestId('cn-nav-primary-action')
	const more = page.getByRole('button', { name: 'More', exact: true }).first()
	await expect(nav.or(more).first()).toBeVisible({ timeout: 15_000 })
	const inline = page
		.getByRole('button', { name: 'Create invoice', exact: true })
		.and(page.locator(':not([data-testid="cn-nav-primary-action"] *)'))
		.first()
	if (await inline.isVisible().catch(() => false)) {
		await inline.click()
		return
	}
	await expect(more).toBeVisible({ timeout: 15_000 })
	await more.click()
	await page.getByRole('menuitem', { name: 'Create invoice' }).click()
}

/**
 * Choose Create invoice from the navigation, beside New Booking. With one
 * sub-action NcActions draws it inline as an icon button labelled Create
 * invoice; with more it is a chevron menu holding a Create invoice entry.
 *
 * @param page The page.
 * @return Nothing.
 */
async function chooseCreateInvoiceInNav(page: Page): Promise<void> {
	const nav = page.getByTestId('cn-nav-primary-action')
	await expect(nav).toBeVisible({ timeout: 15_000 })
	const inline = nav.getByRole('button', { name: 'Create invoice' })
	if (await inline.isVisible().catch(() => false)) {
		await inline.click()
		return
	}
	await nav.getByTestId('cn-action-children-new-booking').getByRole('button').first().click()
	await page.getByRole('menuitem', { name: 'Create invoice' }).click()
}

/**
 * Open the page, dismissing the first-run wizard when it shows.
 *
 * @param page The page.
 * @param path The app path.
 * @return Nothing.
 */
async function open(page: Page, path: string): Promise<void> {
	await page.goto(APP + path)
	await page.waitForLoadState('domcontentloaded')
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Fill one priced line in the open modal.
 *
 * @param page The page.
 * @return Nothing.
 */
async function fillOneLine(page: Page): Promise<void> {
	await page.getByTestId('iqd-line-description').first().fill('Advies maart')
	await page.getByTestId('iqd-line-quantity').first().fill('1')
	await page.getByTestId('iqd-line-unit-price').first().fill('500')
}

test.describe('shillinq-invoice-quick-draft — from any page', () => {
	/**
	 * @e2e shillinq-invoice-quick-draft::the-customer-page-starts-the-draft-for-its-customer
	 */
	test('the customer page starts the draft for its customer', async ({ page }) => {
		const posted = await stubCustomersAndDrafts(page)
		await open(page, '/bookkeeping/customers/' + GEMEENTE.id)
		await expect(page.getByRole('button', { name: 'More', exact: true }).first()).toBeVisible({ timeout: 15_000 })
		const before = page.url()

		await chooseCreateInvoiceOnPage(page)
		const modal = page.getByTestId('invoice-quick-draft-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })
		await expect(page.getByTestId('iqd-customer')).toContainText(GEMEENTE.legalName)
		expect(page.url()).toBe(before)

		await fillOneLine(page)
		await expect(page.getByTestId('iqd-save')).toBeEnabled()
		await page.getByTestId('iqd-save').click()
		await expect.poll(() => posted.length).toBe(1)
		expect(JSON.stringify(posted[0])).toContain(GEMEENTE.customerId)
		await expect(modal).toBeHidden({ timeout: 10_000 })
		expect(page.url()).toBe(before)
	})

	/**
	 * @e2e shillinq-invoice-quick-draft::a-bookkeeper-drafts-an-invoice-from-the-purchase-invoices-page
	 */
	test('a bookkeeper drafts an invoice from the purchase invoices page', async ({ page }) => {
		const posted = await stubCustomersAndDrafts(page)
		await open(page, '/inkoop/supplier-invoices')
		const before = page.url()

		await chooseCreateInvoiceInNav(page)
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({ timeout: 10_000 })
		await page.getByTestId('iqd-customer').click()
		await page.getByText(KORENAAR.legalName, { exact: true }).first().click()
		await fillOneLine(page)
		await page.getByTestId('iqd-save').click()

		await expect.poll(() => posted.length).toBe(1)
		expect(JSON.stringify(posted[0])).toContain(KORENAAR.customerId)
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeHidden({ timeout: 10_000 })
		expect(page.url()).toBe(before)
	})

	/**
	 * @e2e shillinq-invoice-quick-draft::cancelling-keeps-the-page
	 */
	test('cancelling keeps the page', async ({ page }) => {
		const posted = await stubCustomersAndDrafts(page)
		await open(page, '/bookkeeping/bank-reconciliation')
		const before = page.url()

		await chooseCreateInvoiceInNav(page)
		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeVisible({ timeout: 10_000 })
		await page.getByTestId('iqd-cancel').click()

		await expect(page.getByTestId('invoice-quick-draft-modal')).toBeHidden({ timeout: 10_000 })
		expect(posted).toHaveLength(0)
		expect(page.url()).toBe(before)
	})
})
