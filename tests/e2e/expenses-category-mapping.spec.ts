/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Expense categories and the account a receipt posts to, in the browser.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT AND VITEST CANNOT
 * ------------------------------------------------
 * The guard, the resolver and the posting are covered in PHPUnit, and the
 * picker in Vitest. What neither sees is whether the Expense categories page
 * is routed and shows the account NAME next to its number (the fkResolve
 * column reading the chart), and whether the claim page mounts
 * ClaimReceiptAccounts in its slot and keeps a differing docudesk suggestion
 * beside the resolved account instead of in its place.
 *
 * The OpenRegister object calls are answered from fixtures, so the specs run
 * on a fresh instance without seeded categories or claims and leave nothing
 * behind.
 *
 * @spec openspec/changes/expenses-category-mapping/specs/expense-capture-core/spec.md
 * @e2e expense-capture-core/requirement-expense-categories-name-an-expense-account-req-ecm-001/a-bookkeeper-maps-software-subscriptions
 * @e2e expense-capture-core/requirement-each-expense-line-resolves-to-an-account-in-a-fixed-order-req-ecm-003
 */

import type { Page, Route } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OBJECTS = '**/apps/openregister/api/objects/shillinq'
const ADMINISTRATION = 'adm-kade'

const SOFTWARE = {
	id: 'cat-software',
	code: 'software',
	name: { nl: 'Software en abonnementen', en: 'Software and subscriptions' },
	expenseAccount: '4340',
	vatDeductionRule: 'full',
	active: true,
	administrationId: ADMINISTRATION,
}

const ACCOUNT_4340 = {
	id: 'acc-4340',
	accountNumber: '4340',
	name: 'Software en SaaS-abonnementen',
	accountType: 'expenses',
	administrationId: ADMINISTRATION,
}

/**
 * Answer a list call and a single-object call of one schema from fixtures.
 *
 * @param page   The page.
 * @param schema The schema slug.
 * @param rows   The objects.
 */
async function answer(page: Page, schema: string, rows: object[]): Promise<void> {
	await page.route(`${OBJECTS}/${schema}**`, (route: Route) => {
		const request = route.request()
		if (request.method() !== 'GET') {
			return route.fallback()
		}
		const path = new URL(request.url()).pathname
		const id = path.split(`/${schema}/`)[1]?.split('/')[0]
		if (id) {
			const row = rows.find((candidate) => (candidate as { id: string }).id === id)
			return row ? route.fulfill({ json: row }) : route.fulfill({ status: 404, json: { message: 'Not found' } })
		}
		return route.fulfill({ json: { results: rows, total: rows.length } })
	})
}

test.describe('expenses-category-mapping — the Expense categories page', () => {
	test('a category shows its account number with the account name', async ({ page }) => {
		await answer(page, 'ExpenseCategory', [SOFTWARE])
		await answer(page, 'Account', [ACCOUNT_4340])

		await page.goto('/index.php/apps/shillinq/expense-categories')

		const row = page.getByRole('row').filter({ hasText: 'Software en abonnementen' }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('4340 Software en SaaS-abonnementen')
	})
})

test.describe('expenses-category-mapping — the account a receipt posts to', () => {
	test('a differing docudesk suggestion stays beside the category account', async ({ page }) => {
		const claim = {
			id: 'claim-e2e',
			claimNumber: 'EC-E2E-1',
			employeeName: 'S. de Vries',
			status: 'approved',
			administrationId: ADMINISTRATION,
		}
		const receipt = {
			id: 'rcpt-e2e',
			receiptNumber: 'R-E2E-1',
			vendorName: 'Adobe',
			amount: 59.99,
			category: 'software',
			claimId: claim.id,
			administrationId: ADMINISTRATION,
			suggestedGlAccount: { code: '4440', confidence: 0.71 },
		}
		await answer(page, 'ExpenseClaimEntry', [claim])
		await answer(page, 'Receipt', [receipt])
		await answer(page, 'ExpenseCategory', [SOFTWARE])

		await page.goto(`/index.php/apps/shillinq/inkoop/expense-claims/${claim.id}`)

		const row = page.getByTestId('claim-receipt-accounts-row').filter({ hasText: 'R-E2E-1' })
		await expect(row).toBeVisible({ timeout: 20_000 })
		// Posts to the category's account, not to the suggestion.
		await expect(row.locator('td').nth(1)).toContainText('4340')
		await expect(row.locator('td').nth(1)).not.toContainText('4440')
		// The suggestion is shown, and marked as not used until confirmed.
		await expect(row.locator('td').nth(2)).toContainText('4440')
		await expect(row.getByTestId('claim-receipt-accounts-differs')).toBeVisible()
	})
})
