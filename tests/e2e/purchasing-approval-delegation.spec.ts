/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright coverage for openspec/changes/purchasing-approval-delegation.
 *
 * Purchase order and supplier invoice approval run on OpenRegister's
 * declared approval chains: each step is an OpenRegister flow task, shown in
 * the Approval sidebar tab (`cn-object-sidebar-tab-approval`) through the
 * shared `tasks` widget. These specs drive that tab as the people the
 * scenarios name, each in its OWN browser context with an EMPTY storage state
 * (never the admin session of `tests/e2e/.auth/admin.json`): separation of
 * duties and the step's group are checked by the server for the logged-in
 * user, so an admin session would prove nothing.
 *
 * ## Environment
 *
 * The base URL comes from `tests/e2e/base-url.ts` (no localhost default). The
 * users and their passwords come from the environment, because they are
 * accounts of the target instance, not of this repository:
 *
 * - `SHILLINQ_E2E_TEAMLEIDER_USER` / `_PASS`: Karin de Wit, member of
 *   `shillinq_teamleider` (task 1.2 approves PO-2026-040 as her).
 * - `SHILLINQ_E2E_REQUESTER_USER` / `_PASS`: the requester of PO-2026-041
 *   (task 1.2 sees them refused on their own order).
 * - `SHILLINQ_E2E_CONTROLLER_USER` / `_PASS`: a member of
 *   `shillinq_controller` (task 1.4 approves a large matched invoice).
 * - `SHILLINQ_E2E_LARGE_MATCHED_INVOICE`: the number of a matched supplier
 *   invoice of EUR 10.000 or more whose controller step is pending.
 *
 * A test whose users or fixture are not configured is skipped and says which
 * variable is missing; it never falls back to another account.
 *
 * ## Locators
 *
 * The sidebar tab and the index/detail pages are addressed by `data-testid`.
 * The step's outcome buttons and the step rows are matched on their English
 * and Dutch labels both, because this instance may render either.
 *
 * @e2e purchasing-approval-delegation::teamleider-approves-purchase-order
 * @e2e purchasing-approval-delegation::requester-refused-on-own-order
 * @e2e purchasing-approval-delegation::controller-approves-large-matched-invoice
 * @e2e purchasing-approval-delegation::hand-approval-to-colleague
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md
 */

import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const PURCHASE_ORDERS = '/inkoop/purchase-orders'
const SUPPLIER_INVOICES = '/inkoop/supplier-invoices'

const APPROVE = /^(Approve|Goedkeuren)$/
const APPROVED = /(Approved|Goedgekeurd)/

type Account = { user: string, pass: string }

/** The account in `<PREFIX>_USER` / `<PREFIX>_PASS`, or null when unset. */
function account(prefix: string): Account | null {
	const user = process.env[`${prefix}_USER`]?.trim() ?? ''
	const pass = process.env[`${prefix}_PASS`] ?? ''
	return user !== '' && pass !== '' ? { user, pass } : null
}

/** A fresh, logged-in page for one user, in a context with an empty storage state. */
async function pageAs(browser: Browser, who: Account): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.setViewportSize({ width: 1600, height: 1200 })
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(who.user)
	await page.locator('input[name="password"]').fill(who.pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForURL((url) => !/\/login(\?|$|\/)/.test(url.toString()), { timeout: 30_000 })
	return page
}

/** Open the detail page of the row whose text contains `number`, from an index route. */
async function openByNumber(page: Page, route: string, number: string): Promise<void> {
	await page.goto(APP + route, { waitUntil: 'domcontentloaded', timeout: 25_000 })
	await expect(page.getByTestId('cn-index-page')).toBeVisible({ timeout: 15_000 })
	const row = page.getByTestId('cn-object-list-table').locator('tr', { hasText: number }).first()
	await expect(row, `${number} must be listed on ${route}`).toBeVisible({ timeout: 15_000 })
	await row.click()
	await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 15_000 })
}

/** Open the Approval sidebar tab and return its panel. */
async function openApprovalTab(page: Page) {
	const tab = page.getByTestId('cn-object-sidebar-tab-approval')
	await expect(tab).toBeVisible({ timeout: 15_000 })
	await tab.click()
	return page.locator('[role="tabpanel"]:visible').last()
}

test.describe('purchasing-approval-delegation — approval on OpenRegister', () => {
	test.beforeEach(() => {
		test.setTimeout(120_000)
	})

	/**
	 * Task 1.2 / REQ-PAD-001: Karin de Wit, teamleider, approves PO-2026-040
	 * from the order's Approval tab, and the step reads approved.
	 */
	test('a teamleider approves PO-2026-040 from the Approval tab', async ({ browser }) => {
		const karin = account('SHILLINQ_E2E_TEAMLEIDER')
		test.skip(karin === null, 'SHILLINQ_E2E_TEAMLEIDER_USER / _PASS are not set')

		const page = await pageAs(browser, karin as Account)
		await openByNumber(page, PURCHASE_ORDERS, 'PO-2026-040')
		const panel = await openApprovalTab(page)

		const approve = panel.getByRole('button', { name: APPROVE }).first()
		await expect(approve, 'the teamleider may decide the pending step').toBeVisible({ timeout: 15_000 })
		await approve.click()
		await expect(panel.getByText(APPROVED).first()).toBeVisible({ timeout: 15_000 })
		await page.context().close()
	})

	/**
	 * Task 1.2 / REQ-PAD-001: separation of duties. The requester of
	 * PO-2026-041 sees its steps but is offered no decision on them.
	 */
	test('the requester is refused a decision on their own order PO-2026-041', async ({ browser }) => {
		const requester = account('SHILLINQ_E2E_REQUESTER')
		test.skip(requester === null, 'SHILLINQ_E2E_REQUESTER_USER / _PASS are not set')

		const page = await pageAs(browser, requester as Account)
		await openByNumber(page, PURCHASE_ORDERS, 'PO-2026-041')
		const panel = await openApprovalTab(page)

		await expect(panel.locator('tr, li').first()).toBeVisible({ timeout: 15_000 })
		await expect(panel.getByRole('button', { name: APPROVE })).toHaveCount(0)
		await page.context().close()
	})

	/**
	 * Task 1.4 / REQ-PAD-004: a matched invoice from EUR 10.000 waits in
	 * matched for the controller; the controller approves it from the
	 * invoice's Approval tab.
	 */
	test('a controller approves a large matched supplier invoice', async ({ browser }) => {
		const controller = account('SHILLINQ_E2E_CONTROLLER')
		const invoice = process.env.SHILLINQ_E2E_LARGE_MATCHED_INVOICE?.trim() ?? ''
		test.skip(controller === null, 'SHILLINQ_E2E_CONTROLLER_USER / _PASS are not set')
		test.skip(invoice === '', 'SHILLINQ_E2E_LARGE_MATCHED_INVOICE is not set')

		const page = await pageAs(browser, controller as Account)
		await openByNumber(page, SUPPLIER_INVOICES, invoice)
		const panel = await openApprovalTab(page)

		const approve = panel.getByRole('button', { name: APPROVE }).first()
		await expect(approve, 'the controller step is pending').toBeVisible({ timeout: 15_000 })
		await approve.click()
		await expect(panel.getByText(APPROVED).first()).toBeVisible({ timeout: 15_000 })
		await page.context().close()
	})
})

test.describe('purchasing-approval-delegation — delegation', () => {
	/**
	 * Task 2.1 / REQ-PAD-002: hand a pending step to a colleague with a
	 * mandate. The shared `tasks` widget of @conduction/nextcloud-vue offers
	 * no delegate verb yet, so there is no control to drive; this stays a
	 * fixme until the widget ships "Hand to a colleague".
	 */
	test.fixme('a teamleider hands PO-2026-040 to Karin de Wit with a mandate', async () => {})
})
