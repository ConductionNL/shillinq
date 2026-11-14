/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bookkeeper confirms a suggested pair on Relations both ways, and sees
 * who owes whom on the customer page (reporting-relation-both-sides).
 *
 * The relation endpoints are answered through page.route with the answers
 * RelationBothSidesServiceTest and RelationLinkServiceTest pin for
 * Drukkerij Van Wijk B.V., so this test proves the screens and the request
 * they send; the sums and the link rules are proven by those tests.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import { expect, test } from '@playwright/test'

const suggestion = {
	customerId: 'c-noord',
	customerName: 'Transport Noord B.V.',
	payeeId: 'p-noord',
	payeeName: 'Transport Noord B.V.',
	matchedOn: 'vat',
	number: 'NL812345678B01',
}

test.describe('reporting-relation-both-sides', () => {
	/**
	 * @e2e bookkeeping-reconciliation-reports::a-suggested-pair-is-confirmed
	 */
	test('a bookkeeper confirms a suggested pair', async ({ page }) => {
		const posted: Record<string, unknown>[] = []
		await page.route('**/apps/shillinq/api/relations/suggestions', (route) =>
			route.fulfill({
				json: { suggestions: posted.length ? [] : [suggestion] },
			}),
		)
		await page.route('**/apps/shillinq/api/relations/both-sides**', (route) =>
			route.fulfill({
				json: {
					relations: [],
					restricted: { sent: false, received: false },
				},
			}),
		)
		await page.route('**/apps/shillinq/api/relations/links', (route) => {
			posted.push(route.request().postDataJSON())
			return route.fulfill({ json: { payeeId: 'p-noord' } })
		})
		await page.goto('/index.php/apps/shillinq/reports/relations-both-ways')

		const row = page.getByRole('row', { name: /Transport Noord/ })
		await row.getByRole('button', { name: /Actions|Acties/ }).click()
		await page.getByRole('menuitem', { name: /^Link$|^Koppelen$/ }).click()

		await expect.poll(() => posted.length).toBe(1)
		expect(posted[0]).toEqual({
			customerId: 'c-noord',
			payeeId: 'p-noord',
			matchedOn: 'vat',
		})
	})

	/**
	 * @e2e bookkeeping-reconciliation-reports::the-bookkeeper-sees-who-owes-whom
	 */
	test('the customer page shows who owes whom', async ({ page }) => {
		await page.route(
			'**/apps/shillinq/api/relations/c-zuid/both-sides**',
			(route) =>
				route.fulfill({
					json: {
						customer: { id: 'c-zuid', name: 'Reclamebureau Zuid B.V.' },
						payee: { id: 'p-zuid', name: 'Reclamebureau Zuid B.V.' },
						link: { matchedOn: 'kvk', confirmedBy: 'petra' },
						sent: {
							restricted: false,
							rows: [
								{
									id: 'ar-0355',
									number: '2026-0355',
									date: '2026-04-02',
									state: 'issued',
									amount: 1210,
									open: 1210,
								},
								{
									id: 'ar-0310',
									number: '2026-0310',
									date: '2026-03-10',
									state: 'paid',
									amount: 4235,
									open: 0,
								},
							],
						},
						received: {
							restricted: false,
							rows: [
								{
									id: 'ap-118',
									number: 'INK-2026-118',
									date: '2026-04-15',
									state: 'issued',
									amount: 2662,
									open: 2662,
								},
							],
						},
						totals: {
							sales: 5445,
							purchases: 2662,
							openReceivable: 1210,
							openPayable: 2662,
							net: -1452,
						},
					},
				}),
		)
		await page.goto('/index.php/apps/shillinq/bookkeeping/customers/c-zuid')

		await expect(page.getByText('INK-2026-118')).toBeVisible()
		await expect(page.getByText('2026-0355')).toBeVisible()
		await expect(page.getByText(/1,452|1\.452/)).toBeVisible()
	})
})
