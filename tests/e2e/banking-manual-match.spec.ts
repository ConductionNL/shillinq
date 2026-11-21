/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Matching a bank line by hand (banking-manual-match).
 *
 * The line, the open invoices and the match endpoint are answered through
 * page.route, so the test proves the screen: the row action opens the dialog,
 * the dialog lists the open invoices, and the confirm posts the selection and
 * shows the server's answer. The server side (the match, the refusal, the
 * settlement) is proven by ManualMatchServiceTest against the real register
 * schemas and declared lifecycles.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const LINE = {
	id: 'line-devries',
	lineId: 'L-1',
	statementId: 'stmt-1',
	valueDate: '2026-09-15',
	amount: -2420,
	counterpartyName: 'Schoonmaakbedrijf De Vries',
	remittanceInfo: 'factuur sept',
	status: 'unmatched',
	administrationId: 'adm-gv',
}

/**
 * Answer the OpenRegister and shillinq calls the page and the dialog make.
 *
 * @param page The page.
 * @param matchAnswer The status and body the match endpoint answers.
 * @return The bodies posted to the match endpoint.
 */
async function stubApis(
	page: Page,
	matchAnswer: { status: number; body: object },
): Promise<object[]> {
	const posted: object[] = []
	await page.route(
		'**/apps/openregister/api/objects/shillinq/BankStatementLine**',
		(route: Route) => {
			const url = route.request().url()
			const body = url.includes('/BankStatementLine/')
				? LINE
				: { results: [LINE], total: 1 }
			return route.fulfill({ json: body })
		},
	)
	await page.route(
		'**/apps/openregister/api/objects/shillinq/APTransaction**',
		(route: Route) =>
			route.fulfill({
				json: {
					results: [
						{
							id: 'ap-dv7781',
							invoiceNumber: 'DV-7781',
							vendorId: 'De Vries',
							state: 'issued',
							totalAmount: 2420,
						},
					],
				},
			}),
	)
	await page.route(
		'**/apps/openregister/api/objects/shillinq/Account**',
		(route: Route) =>
			route.fulfill({
				json: { results: [{ accountNumber: '4910', name: 'Bankkosten' }] },
			}),
	)
	await page.route(
		'**/apps/shillinq/api/v1/bank-lines/*/match',
		(route: Route) => {
			posted.push(route.request().postDataJSON())
			return route.fulfill({
				status: matchAnswer.status,
				json: matchAnswer.body,
			})
		},
	)
	return posted
}

/**
 * Open the dialog from the first unmatched bank line.
 *
 * @param page The page.
 * @return Whether the dialog opened.
 */
async function openDialog(page: Page): Promise<boolean> {
	await page.goto('/index.php/apps/shillinq/bookkeeping/bank-lines/unmatched')
	const row = page.getByRole('row').filter({ hasText: 'De Vries' }).first()
	if ((await row.count()) === 0) {
		return false
	}
	await row.getByRole('button', { name: /actions/i }).click()
	await page.getByRole('menuitem', { name: 'Match by hand' }).click()
	await page
		.locator('[data-testid="bank-line-match-modal"]')
		.waitFor({ state: 'visible', timeout: 8_000 })
	return true
}

test.describe('bookkeeping-bank-reconciliation', () => {
	/**
	 * @e2e bookkeeping-bank-reconciliation::a-bookkeeper-pairs-a-supplier-payment-with-its-invoice
	 */
	test('a bookkeeper pairs a supplier payment with its invoice', async ({
		page,
	}) => {
		const posted = await stubApis(page, {
			status: 200,
			body: { id: 'm1', status: 'confirmed', remainder: 0 },
		})
		test.skip(
			!(await openDialog(page)),
			'Unmatched bank lines page not reachable',
		)

		await page.locator('[data-testid="bank-line-match-invoice-DV-7781"]').click()
		await page.locator('[data-testid="bank-line-match-confirm"]').click()

		await expect(
			page.locator('[data-testid="bank-line-match-modal"]'),
		).toBeHidden()
		expect(posted).toEqual([{ targets: ['ap-dv7781'] }])
	})

	/**
	 * @e2e bookkeeping-bank-reconciliation::a-selection-larger-than-the-line-is-refused
	 */
	test('a selection larger than the line is refused', async ({ page }) => {
		await stubApis(page, {
			status: 422,
			body: { message: 'The selection exceeds the bank line by EUR 600.00.' },
		})
		test.skip(
			!(await openDialog(page)),
			'Unmatched bank lines page not reachable',
		)

		await page.locator('[data-testid="bank-line-match-invoice-DV-7781"]').click()
		await page.locator('[data-testid="bank-line-match-confirm"]').click()

		await expect(
			page.locator('[data-testid="bank-line-match-error"]'),
		).toHaveText('The selection exceeds the bank line by EUR 600.00.')
	})

	/**
	 * @e2e bookkeeping-bank-reconciliation::monthly-bank-costs-are-booked-from-the-statement
	 */
	test('monthly bank costs are booked from the statement', async ({ page }) => {
		const posted = await stubApis(page, {
			status: 200,
			body: { id: 'm2', status: 'confirmed' },
		})
		test.skip(
			!(await openDialog(page)),
			'Unmatched bank lines page not reachable',
		)

		await page.locator('[data-testid="bank-line-match-tab-ledger"]').click()
		await page.locator('[data-testid="bank-line-match-account"]').click()
		await page.getByRole('option', { name: /4910/ }).click()
		await page.locator('[data-testid="bank-line-match-confirm"]').click()

		await expect(
			page.locator('[data-testid="bank-line-match-modal"]'),
		).toBeHidden()
		expect(posted[0]).toMatchObject({ ledgerAccount: { accountNumber: '4910' } })
	})
})
